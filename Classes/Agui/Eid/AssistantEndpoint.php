<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Agui\Eid;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use Webconsulting\AgentNexus\Agui\Service\AgentRunner;
use Webconsulting\AgentNexus\Agui\Service\EventEncoder;
use Webconsulting\AgentNexus\Agui\Service\LeadStore;
use Webconsulting\AgentNexus\Agui\Service\RunLogger;

/**
 * Public (frontend) AG-UI endpoint for the Live Assistant content element.
 * Accepts a RunAgentInput and streams the run as SSE. Rate-limited; the lead is
 * stored only when the visitor's approval (a confirm_booking TOOL_CALL_RESULT)
 * is present.
 */
final class AssistantEndpoint
{
    private const RATE_LIMIT = 20;
    private const RATE_WINDOW = 600;

    public function run(ServerRequestInterface $request): ResponseInterface
    {
        $input = json_decode((string)$request->getBody(), true);
        $input = is_array($input) ? $input : [];

        if (!$this->passesRateLimit($request)) {
            return new JsonResponse(['error' => 'Too many requests.'], 429);
        }

        $runner = GeneralUtility::makeInstance(AgentRunner::class);
        $encoder = GeneralUtility::makeInstance(EventEncoder::class);
        $runLogger = GeneralUtility::makeInstance(RunLogger::class);

        $approval = is_array($input['approval'] ?? null) ? $input['approval'] : null;
        $preset = is_string($input['preset'] ?? null) ? $input['preset'] : 'plan';
        $threadId = is_string($input['threadId'] ?? null) ? $input['threadId'] : 't-fe';

        // On an approved confirmation, persist the lead before streaming the apply.
        if ($approval !== null && ($approval['decision'] ?? '') === 'approved') {
            $lead = is_array($input['lead'] ?? null) ? $input['lead'] : [];
            GeneralUtility::makeInstance(LeadStore::class)->store(
                (int)($input['page'] ?? 0),
                (string)($input['url'] ?? ''),
                (string)($input['intent'] ?? $preset),
                $lead,
            );
        }

        $count = 0;
        $outcome = ($approval !== null && ($approval['decision'] ?? '') !== 'approved') ? 'rejected' : 'finished';
        $events = (function () use ($runner, $input, &$count): \Generator {
            foreach ($runner->run($input, 'frontend') as $event) {
                $count++;
                yield $event;
            }
        })();

        // Log after the stream exhausts (best-effort; stream() exits the request).
        register_shutdown_function(static function () use ($runLogger, $threadId, $input, $preset, &$count, $approval, $outcome): void {
            $runLogger->log(
                RunLogger::SOURCE_FRONTEND,
                $threadId,
                is_string($input['runId'] ?? null) ? $input['runId'] : '',
                $preset,
                $count,
                $approval !== null,
                $outcome,
                0,
            );
        });

        $encoder->stream($events, 65);
    }

    private function passesRateLimit(ServerRequestInterface $request): bool
    {
        try {
            $cache = GeneralUtility::makeInstance(CacheManager::class)->getCache('agui');
        } catch (\Throwable) {
            return true;
        }
        $ip = (string)($request->getServerParams()['REMOTE_ADDR'] ?? 'unknown');
        $key = 'rl_' . sha1($ip);
        $count = (int)$cache->get($key);
        if ($count >= self::RATE_LIMIT) {
            return false;
        }
        $cache->set($key, $count + 1, [], self::RATE_WINDOW);
        return true;
    }
}
