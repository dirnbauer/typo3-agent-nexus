<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Agui\Eid;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use Webconsulting\AgentNexus\Agui\Service\AgentRunner;
use Webconsulting\AgentNexus\Agui\Service\EventEncoder;
use Webconsulting\AgentNexus\Agui\Service\LeadStore;
use Webconsulting\AgentNexus\Agui\Service\RunLogger;
use Webconsulting\AgentNexus\Shared\Http\PluginSettings;
use Webconsulting\AgentNexus\Shared\Http\RateLimiter;
use Webconsulting\AgentNexus\Shared\Llm\LlmGuard;

/**
 * Public (frontend) AG-UI endpoint for the Live Assistant content element.
 * Accepts a RunAgentInput and streams the run as SSE. Rate-limited (with a
 * tighter budget for runs that hit the real model); the lead is stored only
 * when the visitor's approval (a confirm_booking TOOL_CALL_RESULT) is present.
 *
 * LLM-relevant FlexForm settings are loaded server-side from the content
 * element (posted as `ce`) — the client cannot inject prompts or toggles.
 */
final class AssistantEndpoint
{
    private const RATE_LIMIT = 20;
    private const RATE_LIMIT_LLM = 8;
    private const RATE_WINDOW = 600;
    private const MAX_INTENT_LENGTH = 600;

    public function run(ServerRequestInterface $request): ResponseInterface
    {
        $input = json_decode((string)$request->getBody(), true);
        $input = is_array($input) ? $input : [];
        // Server-injected keys must never come from the wire.
        unset($input['_settings'], $input['_llm']);

        $rateLimiter = GeneralUtility::makeInstance(RateLimiter::class);
        if (!$rateLimiter->passes($request, 'agui', self::RATE_LIMIT, self::RATE_WINDOW)) {
            return new JsonResponse(['error' => 'Too many requests.'], 429);
        }

        $input['intent'] = mb_substr(trim((string)($input['intent'] ?? '')), 0, self::MAX_INTENT_LENGTH);

        $settings = GeneralUtility::makeInstance(PluginSettings::class)
            ->forContentElement((int)($input['ce'] ?? 0), 'agentnexus_assistant');
        $input['_settings'] = $settings;

        $approval = is_array($input['approval'] ?? null) ? $input['approval'] : null;
        $preset = is_string($input['preset'] ?? null) ? $input['preset'] : 'plan';
        $threadId = is_string($input['threadId'] ?? null) ? $input['threadId'] : 't-fe';

        // A run may use the real model when: it is a propose run with a
        // visitor question, the element does not opt out, the shared guard
        // (global switch, protocol toggle, daily budget) agrees, and the
        // tighter LLM rate bucket still has room.
        $wantsLlm = $approval === null
            && $input['intent'] !== ''
            && (string)($settings['use_llm'] ?? '1') !== '0'
            && GeneralUtility::makeInstance(LlmGuard::class)->allows('agui')['allowed'];
        $input['_llm'] = $wantsLlm
            && $rateLimiter->passes($request, 'agui', self::RATE_LIMIT_LLM, self::RATE_WINDOW, 'llm');

        $runner = GeneralUtility::makeInstance(AgentRunner::class);
        $encoder = GeneralUtility::makeInstance(EventEncoder::class);
        $runLogger = GeneralUtility::makeInstance(RunLogger::class);

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

        // Real model chunks pace themselves; only scripted runs get the
        // artificial token cadence.
        $encoder->stream($events, $input['_llm'] ? 0 : 65);
    }
}
