<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Agui\Event;

/**
 * Factory for AG-UI events.
 *
 * Each event is a plain associative array with an UPPER_SNAKE_CASE `type` (the
 * on-the-wire spelling) plus its payload fields — exactly what gets JSON-encoded
 * into one SSE `data:` frame. Keeping events as arrays (rather than a class per
 * type) keeps the deterministic emitter readable; the field names follow the
 * AG-UI spec (docs.ag-ui.com/concepts/events).
 */
final class Events
{
    // ---- Run lifecycle ----------------------------------------------
    /**
     * @return array<string, mixed>
     */
    public static function runStarted(string $threadId, string $runId): array
    {
        return ['type' => 'RUN_STARTED', 'threadId' => $threadId, 'runId' => $runId];
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    public static function runFinished(string $threadId, string $runId, ?array $result = null): array
    {
        $e = ['type' => 'RUN_FINISHED', 'threadId' => $threadId, 'runId' => $runId];
        if ($result !== null) {
            $e['result'] = $result;
        }
        return $e;
    }

    /**
     * @return array<string, mixed>
     */
    public static function runError(string $message, string $code = ''): array
    {
        return ['type' => 'RUN_ERROR', 'message' => $message, 'code' => $code];
    }

    /**
     * @return array<string, mixed>
     */
    public static function stepStarted(string $stepName): array
    {
        return ['type' => 'STEP_STARTED', 'stepName' => $stepName];
    }

    /**
     * @return array<string, mixed>
     */
    public static function stepFinished(string $stepName): array
    {
        return ['type' => 'STEP_FINISHED', 'stepName' => $stepName];
    }

    // ---- Streamed assistant text ------------------------------------
    /**
     * @return array<string, mixed>
     */
    public static function textStart(string $messageId, string $role = 'assistant'): array
    {
        return ['type' => 'TEXT_MESSAGE_START', 'messageId' => $messageId, 'role' => $role];
    }

    /**
     * @return array<string, mixed>
     */
    public static function textContent(string $messageId, string $delta): array
    {
        return ['type' => 'TEXT_MESSAGE_CONTENT', 'messageId' => $messageId, 'delta' => $delta];
    }

    /**
     * @return array<string, mixed>
     */
    public static function textEnd(string $messageId): array
    {
        return ['type' => 'TEXT_MESSAGE_END', 'messageId' => $messageId];
    }

    // ---- Tool calls (also the human-in-the-loop channel) ------------
    /**
     * @return array<string, mixed>
     */
    public static function toolStart(string $toolCallId, string $toolCallName): array
    {
        return ['type' => 'TOOL_CALL_START', 'toolCallId' => $toolCallId, 'toolCallName' => $toolCallName];
    }

    /**
     * @return array<string, mixed>
     */
    public static function toolArgs(string $toolCallId, string $delta): array
    {
        return ['type' => 'TOOL_CALL_ARGS', 'toolCallId' => $toolCallId, 'delta' => $delta];
    }

    /**
     * @return array<string, mixed>
     */
    public static function toolEnd(string $toolCallId): array
    {
        return ['type' => 'TOOL_CALL_END', 'toolCallId' => $toolCallId];
    }

    /**
     * @return array<string, mixed>
     */
    public static function toolResult(string $messageId, string $toolCallId, string $content): array
    {
        return ['type' => 'TOOL_CALL_RESULT', 'messageId' => $messageId, 'toolCallId' => $toolCallId, 'content' => $content, 'role' => 'tool'];
    }

    // ---- Shared state -----------------------------------------------
    /**
     * @param array<string, mixed> $snapshot
     * @return array<string, mixed>
     */
    public static function stateSnapshot(array $snapshot): array
    {
        return ['type' => 'STATE_SNAPSHOT', 'snapshot' => $snapshot];
    }

    /**
     * @param array<int, array<string, mixed>> $patch RFC 6902 JSON Patch ops
     *
     * @return array<string, mixed>
     */
    public static function stateDelta(array $patch): array
    {
        return ['type' => 'STATE_DELTA', 'delta' => $patch];
    }

    // ---- Reasoning (chain-of-thought summary) -----------------------
    /**
     * @return array<string, mixed>
     */
    public static function reasoningStart(): array
    {
        return ['type' => 'REASONING_START'];
    }

    /**
     * @return array<string, mixed>
     */
    public static function reasoningContent(string $delta): array
    {
        return ['type' => 'REASONING_MESSAGE_CONTENT', 'delta' => $delta];
    }

    /**
     * @return array<string, mixed>
     */
    public static function reasoningEnd(): array
    {
        return ['type' => 'REASONING_END'];
    }

    // ---- Special ----------------------------------------------------
    /**
     * @return array<string, mixed>
     */
    public static function custom(string $name, mixed $value): array
    {
        return ['type' => 'CUSTOM', 'name' => $name, 'value' => $value];
    }
}
