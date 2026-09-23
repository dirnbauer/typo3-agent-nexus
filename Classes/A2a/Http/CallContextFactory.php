<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\A2a\Http;

use Psr\Http\Message\ServerRequestInterface;
use Webconsulting\AgentNexus\A2a\Server\CallContext;
use Webconsulting\AgentNexus\Shared\Http\PluginSettings;
use Webconsulting\AgentNexus\Shared\Http\RateLimiter;
use Webconsulting\AgentNexus\Shared\Http\WidgetContext;
use Webconsulting\AgentNexus\Shared\Llm\LlmGuard;
use Webconsulting\AgentNexus\Shared\Traffic\Channel;
use Webconsulting\AgentNexus\Shared\Traffic\TrafficCapture;

/**
 * Works out who is calling.
 *
 * The concierge widget identifies itself in the message metadata
 * (`metadata.agentNexus = {ce, page, url}`, see {@see WidgetContext}). Its
 * content element — loaded here, never taken from the request — decides
 * whether the model may route the request and write the artifact; the LLM
 * guard and the model budget of the 'a2a.llm' bucket have the last word. Any
 * other caller is an API client and gets the scripted skills.
 */
final readonly class CallContextFactory
{
    public const string CONCIERGE_CTYPE = 'agentnexus_concierge';

    /** Model-backed requests per client and window. */
    public const int MODEL_LIMIT = 10;
    public const int MODEL_WINDOW = 600;

    public function __construct(
        private WidgetContext $widgetContext,
        private PluginSettings $pluginSettings,
        private LlmGuard $llmGuard,
        private RateLimiter $rateLimiter,
    ) {}

    /**
     * @param array<string, mixed> $messageMetadata the metadata of the client's message, when the call carries one
     */
    public function create(ServerRequestInterface $request, array $messageMetadata = []): CallContext
    {
        $capture = $request->getAttribute(TrafficCapture::ATTRIBUTE);
        $capture = $capture instanceof TrafficCapture ? $capture : null;

        $widget = $messageMetadata !== [] ? $this->widgetContext->from($messageMetadata) : null;
        if ($widget === null) {
            return new CallContext(Channel::Api, 0, false, true, 400, $capture);
        }

        $capture?->via(Channel::Widget);
        $settings = $this->pluginSettings->forContentElement($widget['ce'], self::CONCIERGE_CTYPE);
        $wantsModel = $settings !== [] && $this->flag($settings, 'use_llm');
        $useModel = $wantsModel
            && $this->llmGuard->allows('a2a')['allowed']
            && $this->rateLimiter->passes($request, 'a2a.llm', self::MODEL_LIMIT, self::MODEL_WINDOW);

        return new CallContext(
            Channel::Widget,
            $this->widgetContext->storagePid($widget['page']),
            $useModel,
            $this->flag($settings, 'show_rationale'),
            $this->llmGuard->maxOutputTokens(400),
            $capture,
        );
    }

    /**
     * A FlexForm checkbox; both of the concierge's default to on.
     *
     * @param array<string, mixed> $settings
     */
    private function flag(array $settings, string $name): bool
    {
        $value = $settings[$name] ?? '1';
        return !in_array($value, ['0', 0, false, ''], true);
    }
}
