<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Agui\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use TYPO3\CMS\Core\Http\JsonResponse;
use Webconsulting\AgentNexus\Agui\Agent\Audience;
use Webconsulting\AgentNexus\Agui\Protocol\InvalidRunInput;
use Webconsulting\AgentNexus\Agui\Protocol\RunInput;
use Webconsulting\AgentNexus\Agui\Service\LlmPlan;
use Webconsulting\AgentNexus\Agui\Service\RunConflict;
use Webconsulting\AgentNexus\Agui\Service\RunPlan;
use Webconsulting\AgentNexus\Agui\Service\RunService;
use Webconsulting\AgentNexus\Shared\Http\PluginSettings;
use Webconsulting\AgentNexus\Shared\Http\RateLimiter;
use Webconsulting\AgentNexus\Shared\Http\WidgetContext;
use Webconsulting\AgentNexus\Shared\Llm\LlmGuard;
use Webconsulting\AgentNexus\Shared\Traffic\Channel;
use Webconsulting\AgentNexus\Shared\Traffic\TrafficCapture;

/**
 * POST /api/agent-nexus/ag-ui — the public AG-UI 1.0 endpoint (HTTP + SSE).
 *
 * The request is a RunAgentInput; the answer is `text/event-stream`, one event
 * per `data:` line. A request refused before the run starts gets a JSON error
 * and no stream: 400 for a malformed input or another major protocol
 * version, 409 for a conflict with the thread (a reused run id, an answer to
 * an interrupt that is not open, a new run while one waits), 413 for an input
 * that is too large, 429 when the address ran too many runs.
 *
 * The assistant widget calls the same endpoint and adds
 * `forwardedProps.agentNexus = {ce, page, url, preset}`. Only then is the run
 * recorded as a widget run, and only then may the element's own settings let
 * the answer come from the live model; every other run is scripted.
 */
#[Autoconfigure(public: true)]
final readonly class AguiEndpoint
{
    public const string ASSISTANT_CTYPE = 'agentnexus_assistant';

    private const int RATE_LIMIT = 20;
    private const int RATE_LIMIT_LLM = 8;
    private const int RATE_WINDOW = 600;

    public function __construct(
        private RunService $runs,
        private RateLimiter $rateLimiter,
        private WidgetContext $widgetContext,
        private PluginSettings $pluginSettings,
        private LlmGuard $llmGuard,
        private LoggerInterface $logger,
    ) {}

    public function run(ServerRequestInterface $request): ResponseInterface
    {
        $capture = $request->getAttribute(TrafficCapture::ATTRIBUTE);
        $capture = $capture instanceof TrafficCapture ? $capture : null;
        $capture?->describe('RunAgent');

        if (!$this->rateLimiter->passes($request, 'agui', self::RATE_LIMIT, self::RATE_WINDOW)) {
            return self::error(429, 'rate_limited', 'Too many runs from your address. Try again in a few minutes.')
                ->withHeader('Retry-After', (string)self::RATE_WINDOW);
        }
        try {
            $input = RunInput::fromJson((string)$request->getBody());
        } catch (InvalidRunInput $e) {
            return self::error($e->status, $e->reason, $e->getMessage(), $e->pointer);
        }
        $capture?->correlate($input->runId);
        if ($input->warnings !== []) {
            $this->logger->notice('AG-UI run {run}: unrecognised input stripped: {warnings}', [
                'run' => $input->runId,
                'warnings' => implode('; ', $input->warnings),
            ]);
        }

        $widget = $this->widgetContext->from($input->forwardedProps());
        if ($widget !== null) {
            $capture?->via(Channel::Widget);
        }
        $settings = $widget !== null ? $this->pluginSettings->forContentElement($widget['ce'], self::ASSISTANT_CTYPE) : [];
        [$llm, $scriptedReason] = $input->resume === [] && $widget !== null
            ? $this->llmPlan($request, $input, $settings)
            : [null, ''];
        $preset = $input->agentNexus()['preset'] ?? '';

        try {
            return $this->runs->start($input, new RunPlan(
                audience: Audience::Site,
                preset: is_string($preset) ? $preset : '',
                channel: $widget !== null ? Channel::Widget : Channel::Api,
                pid: $widget !== null ? $this->widgetContext->storagePid($widget['page']) : 0,
                llm: $llm,
                scriptedReason: $scriptedReason,
                delayMs: $llm !== null ? 0 : 65,
            ));
        } catch (RunConflict $e) {
            return self::error(409, $e->reason, $e->getMessage());
        }
    }

    /**
     * A refusal before the run starts: no stream, a JSON body saying why.
     */
    public static function error(int $status, string $reason, string $message, string $pointer = ''): ResponseInterface
    {
        $error = ['code' => $status, 'reason' => $reason, 'message' => $message];
        if ($pointer !== '') {
            $error['pointer'] = $pointer;
        }
        return new JsonResponse(['error' => $error], $status);
    }

    /**
     * Whether this widget run may stream its answer from the live model, and
     * if not, why. Only a real assistant element (settings loaded from its
     * record, never from the request) can allow it; the shared guard and the
     * tighter model rate limit must agree too.
     *
     * @param array<string, mixed> $settings
     * @return array{0: ?LlmPlan, 1: string}
     */
    private function llmPlan(ServerRequestInterface $request, RunInput $input, array $settings): array
    {
        $useLlm = $settings['use_llm'] ?? '1';
        if ($settings === [] || $input->lastUserText() === '' || $useLlm === '0' || $useLlm === 0 || $useLlm === false) {
            return [null, ''];
        }
        $guard = $this->llmGuard->allows('agui');
        if (!$guard['allowed']) {
            return [null, $guard['reason']];
        }
        if (!$this->rateLimiter->passes($request, 'agui.llm', self::RATE_LIMIT_LLM, self::RATE_WINDOW)) {
            return [null, 'model rate limit reached'];
        }
        $prompt = is_string($settings['llm_system_prompt'] ?? null) ? trim($settings['llm_system_prompt']) : '';
        $maxTokens = is_numeric($settings['llm_max_tokens'] ?? null) ? (int)$settings['llm_max_tokens'] : null;
        return [new LlmPlan($prompt, $this->llmGuard->maxOutputTokens($maxTokens)), ''];
    }
}
