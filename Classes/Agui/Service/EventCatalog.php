<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Agui\Service;

use TYPO3\CMS\Core\SingletonInterface;

/**
 * The AG-UI event catalogue, grouped by family — the data behind the backend
 * "Event Inspector". Names are the on-the-wire UPPER_SNAKE_CASE spellings
 * (docs.ag-ui.com/concepts/events). The stable core is ~16 types; Reasoning and
 * Activity are newer/SDK-dependent.
 */
final class EventCatalog implements SingletonInterface
{
    /**
     * @return array<string, array{accent: string, events: array<int, array{type: string, fields: string, desc: string}>}>
     */
    public function all(): array
    {
        return [
            'Lifecycle' => [
                'accent' => 'lifecycle',
                'events' => [
                    ['type' => 'RUN_STARTED', 'fields' => 'threadId, runId', 'desc' => 'Opens a run. Every stream begins with this.'],
                    ['type' => 'RUN_FINISHED', 'fields' => 'threadId, runId, result?', 'desc' => 'Successful end of a run.'],
                    ['type' => 'RUN_ERROR', 'fields' => 'message, code?', 'desc' => 'The only other valid run terminator — aborts the run.'],
                    ['type' => 'STEP_STARTED', 'fields' => 'stepName', 'desc' => 'Marks the start of an internal phase.'],
                    ['type' => 'STEP_FINISHED', 'fields' => 'stepName', 'desc' => 'Marks the end of an internal phase.'],
                ],
            ],
            'Text' => [
                'accent' => 'text',
                'events' => [
                    ['type' => 'TEXT_MESSAGE_START', 'fields' => 'messageId, role', 'desc' => 'Begins a streamed assistant message.'],
                    ['type' => 'TEXT_MESSAGE_CONTENT', 'fields' => 'messageId, delta', 'desc' => 'A token/word delta — accumulate to build the text.'],
                    ['type' => 'TEXT_MESSAGE_END', 'fields' => 'messageId', 'desc' => 'Ends the streamed message.'],
                    ['type' => 'TEXT_MESSAGE_CHUNK', 'fields' => 'messageId, delta', 'desc' => 'Fused start+content+end for simple cases.'],
                ],
            ],
            'Tool' => [
                'accent' => 'tool',
                'events' => [
                    ['type' => 'TOOL_CALL_START', 'fields' => 'toolCallId, toolCallName', 'desc' => 'Agent invokes a (frontend-declared) tool.'],
                    ['type' => 'TOOL_CALL_ARGS', 'fields' => 'toolCallId, delta', 'desc' => 'Streamed JSON-fragment arguments.'],
                    ['type' => 'TOOL_CALL_END', 'fields' => 'toolCallId', 'desc' => 'Arguments complete.'],
                    ['type' => 'TOOL_CALL_RESULT', 'fields' => 'messageId, toolCallId, content', 'desc' => 'The tool outcome — also how the UI sends back a human approval.'],
                ],
            ],
            'State' => [
                'accent' => 'state',
                'events' => [
                    ['type' => 'STATE_SNAPSHOT', 'fields' => 'snapshot', 'desc' => 'Full shared-state object the UI mirrors.'],
                    ['type' => 'STATE_DELTA', 'fields' => 'delta (RFC 6902 JSON Patch)', 'desc' => 'Incremental state changes — cheap diffs.'],
                    ['type' => 'MESSAGES_SNAPSHOT', 'fields' => 'messages', 'desc' => 'Resyncs the whole message list.'],
                ],
            ],
            'Reasoning' => [
                'accent' => 'reason',
                'events' => [
                    ['type' => 'REASONING_START', 'fields' => '—', 'desc' => 'Begins a visible chain-of-thought summary.'],
                    ['type' => 'REASONING_MESSAGE_CONTENT', 'fields' => 'delta', 'desc' => 'Streamed reasoning summary text.'],
                    ['type' => 'REASONING_END', 'fields' => '—', 'desc' => 'Ends the reasoning summary.'],
                    ['type' => 'REASONING_ENCRYPTED_VALUE', 'fields' => 'value', 'desc' => 'Privacy-preserving encrypted reasoning continuity.'],
                ],
            ],
            'Activity' => [
                'accent' => 'state',
                'events' => [
                    ['type' => 'ACTIVITY_SNAPSHOT', 'fields' => 'messageId, activityType, content', 'desc' => 'A structured live activity panel (e.g. PLAN, SEARCH).'],
                    ['type' => 'ACTIVITY_DELTA', 'fields' => 'messageId, activityType, patch', 'desc' => 'Incremental activity updates (JSON Patch).'],
                ],
            ],
            'Special' => [
                'accent' => 'special',
                'events' => [
                    ['type' => 'RAW', 'fields' => 'event, source?', 'desc' => 'Passthrough of an external system event — treat as untrusted.'],
                    ['type' => 'CUSTOM', 'fields' => 'name, value', 'desc' => 'The documented extension hook for app-specific events.'],
                ],
            ],
        ];
    }
}
