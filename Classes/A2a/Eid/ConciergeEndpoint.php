<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\A2a\Eid;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use Webconsulting\AgentNexus\A2a\Service\RequestStore;
use Webconsulting\AgentNexus\A2a\Service\SseEncoder;
use Webconsulting\AgentNexus\A2a\Service\TaskLogger;
use Webconsulting\AgentNexus\A2a\Service\TaskRunner;
use Webconsulting\AgentNexus\Shared\Http\PluginSettings;
use Webconsulting\AgentNexus\Shared\Http\RateLimiter;
use Webconsulting\AgentNexus\Shared\Llm\LlmGuard;

/**
 * Public (frontend) endpoint for the A2A Concierge content element.
 *
 * The site's agent, made visitor-facing: it accepts an A2A `message/stream`
 * request, streams the Task lifecycle (including the cooperative `input-required`
 * pause) and, when the task completes, stores the request + returned artifact as a
 * lead. Rate-limited (tighter for model-backed runs); side-effect free. LLM
 * settings come from the content element server-side, never from the wire.
 */
final class ConciergeEndpoint
{
    private const RATE_LIMIT = 25;
    private const RATE_LIMIT_LLM = 10;
    private const RATE_WINDOW = 600;

    public function run(ServerRequestInterface $request): ResponseInterface
    {
        $body = json_decode((string)$request->getBody(), true);
        $body = is_array($body) ? $body : [];

        $rateLimiter = GeneralUtility::makeInstance(RateLimiter::class);
        if (!$rateLimiter->passes($request, 'a2a', self::RATE_LIMIT, self::RATE_WINDOW)) {
            return new JsonResponse(['error' => 'Too many requests.'], 429);
        }

        $rpcId = $body['id'] ?? 1;
        $params = is_array($body['params'] ?? null) ? $body['params'] : [];
        unset($params['_settings'], $params['_llm']);
        $message = is_array($params['message'] ?? null) ? $params['message'] : [];
        $metadata = is_array($message['metadata'] ?? null) ? $message['metadata'] : [];
        $skill = (string)($metadata['skill'] ?? '');
        $prompt = $this->textOf($message);

        $settings = GeneralUtility::makeInstance(PluginSettings::class)
            ->forContentElement((int)($metadata['ce'] ?? 0), 'agentnexus_concierge');
        $params['_settings'] = $settings;
        $params['_llm'] = (string)($settings['use_llm'] ?? '1') !== '0'
            && GeneralUtility::makeInstance(LlmGuard::class)->allows('a2a')['allowed']
            && $rateLimiter->passes($request, 'a2a', self::RATE_LIMIT_LLM, self::RATE_WINDOW, 'llm');

        $runner = GeneralUtility::makeInstance(TaskRunner::class);
        $encoder = GeneralUtility::makeInstance(SseEncoder::class);
        $logger = GeneralUtility::makeInstance(TaskLogger::class);
        $store = GeneralUtility::makeInstance(RequestStore::class);

        $page = (int)($metadata['page'] ?? 0);
        $url = (string)($metadata['url'] ?? '');

        $count = 0;
        $artifacts = 0;
        $finalState = 'unknown';
        $taskId = '';
        $contextId = '';
        $answer = '';

        $frames = (function () use ($runner, $params, $rpcId, &$count, &$artifacts, &$finalState, &$taskId, &$contextId, &$answer): \Generator {
            foreach ($runner->run($params, 'frontend', $rpcId) as $frame) {
                $count++;
                $result = is_array($frame['result'] ?? null) ? $frame['result'] : [];
                $kind = $result['kind'] ?? '';
                if ($kind === 'task') {
                    $taskId = (string)($result['id'] ?? '');
                    $contextId = (string)($result['contextId'] ?? '');
                } elseif ($kind === 'artifact-update') {
                    if (($result['lastChunk'] ?? false) === true) {
                        $artifacts++;
                    }
                    foreach (($result['artifact']['parts'] ?? []) as $part) {
                        if (($part['kind'] ?? '') === 'text') {
                            $answer .= (string)($part['text'] ?? '');
                        }
                    }
                } elseif ($kind === 'status-update') {
                    $finalState = (string)($result['status']['state'] ?? $finalState);
                }
                yield $frame;
            }
        })();

        // Persist after the stream exhausts (best-effort; stream() exits).
        register_shutdown_function(static function () use ($logger, $store, &$taskId, &$contextId, $skill, &$finalState, &$count, &$artifacts, &$answer, $prompt, $page, $url): void {
            $logger->log(TaskLogger::SOURCE_FRONTEND, $taskId, $contextId, $skill, $finalState, $count, $artifacts, 0);
            if ($finalState === 'completed' && $answer !== '') {
                $store->store($page, $url, $skill, $prompt, $answer, ['taskId' => $taskId]);
            }
        });

        $encoder->stream($frames, $params['_llm'] ? 25 : 55);
    }

    private function textOf(array $message): string
    {
        $text = '';
        foreach (($message['parts'] ?? []) as $part) {
            if (is_array($part) && ($part['kind'] ?? '') === 'text') {
                $text .= ' ' . (string)($part['text'] ?? '');
            }
        }
        return trim($text);
    }
}
