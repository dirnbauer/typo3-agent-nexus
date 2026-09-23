<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Ucp\Agent;

/**
 * AG-UI 1.0 events, as plain arrays ready to be framed as SSE.
 *
 * The shopping agent talks to the visitor over AG-UI: its stream is an AG-UI
 * run in which every UCP request is a tool call. AG-UI 1.0 objects are
 * closed, so these builders emit exactly the fields the schema describes,
 * leave optional fields out instead of sending null, and stamp every event
 * with an integer millisecond timestamp.
 */
final class AgUi
{
    public const string PROTOCOL_VERSION = '1.0';

    public static function now(): int
    {
        return (int)floor(microtime(true) * 1000);
    }

    /** A fresh identifier: a short prefix and 16 hex digits. */
    public static function id(string $prefix): string
    {
        return $prefix . '_' . bin2hex(random_bytes(8));
    }

    /**
     * @return array<string, mixed>
     */
    public static function runStarted(string $threadId, string $runId, string $parentRunId = ''): array
    {
        $event = [
            'type' => 'RUN_STARTED',
            'timestamp' => self::now(),
            'threadId' => $threadId,
            'runId' => $runId,
            'protocolVersion' => self::PROTOCOL_VERSION,
        ];
        if ($parentRunId !== '') {
            $event['parentRunId'] = $parentRunId;
        }
        return $event;
    }

    /**
     * @param array<string, mixed> $outcome {@see success()} or {@see interrupt()}
     * @param list<array<string, int|string>> $usage one entry per model the run used
     * @return array<string, mixed>
     */
    public static function runFinished(string $threadId, string $runId, array $outcome, mixed $result = null, array $usage = []): array
    {
        $event = [
            'type' => 'RUN_FINISHED',
            'timestamp' => self::now(),
            'threadId' => $threadId,
            'runId' => $runId,
            'outcome' => $outcome,
        ];
        if ($result !== null) {
            $event['result'] = $result;
        }
        if ($usage !== []) {
            $event['usage'] = $usage;
        }
        return $event;
    }

    /**
     * @return array{type: 'success'}
     */
    public static function success(): array
    {
        return ['type' => 'success'];
    }

    /**
     * @param list<array<string, mixed>> $interrupts at least one
     * @return array{type: 'interrupt', interrupts: list<array<string, mixed>>}
     */
    public static function interrupt(array $interrupts): array
    {
        return ['type' => 'interrupt', 'interrupts' => $interrupts];
    }

    /**
     * @return array<string, mixed>
     */
    public static function runError(string $message, string $code = ''): array
    {
        $event = ['type' => 'RUN_ERROR', 'timestamp' => self::now(), 'message' => $message];
        if ($code !== '') {
            $event['code'] = $code;
        }
        return $event;
    }

    /**
     * @return array<string, mixed>
     */
    public static function stepStarted(string $stepName): array
    {
        return ['type' => 'STEP_STARTED', 'timestamp' => self::now(), 'stepName' => $stepName];
    }

    /**
     * @return array<string, mixed>
     */
    public static function stepFinished(string $stepName): array
    {
        return ['type' => 'STEP_FINISHED', 'timestamp' => self::now(), 'stepName' => $stepName];
    }

    /**
     * One assistant message: start, content, end.
     *
     * @return list<array<string, mixed>>
     */
    public static function textMessage(string $text): array
    {
        $messageId = self::id('msg');
        return [
            ['type' => 'TEXT_MESSAGE_START', 'timestamp' => self::now(), 'messageId' => $messageId, 'role' => 'assistant'],
            ['type' => 'TEXT_MESSAGE_CONTENT', 'timestamp' => self::now(), 'messageId' => $messageId, 'delta' => $text],
            ['type' => 'TEXT_MESSAGE_END', 'timestamp' => self::now(), 'messageId' => $messageId],
        ];
    }

    /**
     * A reasoning span holding one reasoning message.
     *
     * @return list<array<string, mixed>>
     */
    public static function reasoning(string $text): array
    {
        $spanId = self::id('rsn');
        $messageId = self::id('msg');
        return [
            ['type' => 'REASONING_START', 'timestamp' => self::now(), 'messageId' => $spanId],
            ['type' => 'REASONING_MESSAGE_START', 'timestamp' => self::now(), 'messageId' => $messageId, 'role' => 'reasoning'],
            ['type' => 'REASONING_MESSAGE_CONTENT', 'timestamp' => self::now(), 'messageId' => $messageId, 'delta' => $text],
            ['type' => 'REASONING_MESSAGE_END', 'timestamp' => self::now(), 'messageId' => $messageId],
            ['type' => 'REASONING_END', 'timestamp' => self::now(), 'messageId' => $spanId],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function toolCallStart(string $toolCallId, string $toolCallName): array
    {
        return ['type' => 'TOOL_CALL_START', 'timestamp' => self::now(), 'toolCallId' => $toolCallId, 'toolCallName' => $toolCallName];
    }

    /**
     * @return array<string, mixed>
     */
    public static function toolCallArgs(string $toolCallId, string $delta): array
    {
        return ['type' => 'TOOL_CALL_ARGS', 'timestamp' => self::now(), 'toolCallId' => $toolCallId, 'delta' => $delta];
    }

    /**
     * @return array<string, mixed>
     */
    public static function toolCallEnd(string $toolCallId): array
    {
        return ['type' => 'TOOL_CALL_END', 'timestamp' => self::now(), 'toolCallId' => $toolCallId];
    }

    /**
     * @return array<string, mixed>
     */
    public static function toolCallResult(string $toolCallId, string $content): array
    {
        return [
            'type' => 'TOOL_CALL_RESULT',
            'timestamp' => self::now(),
            'messageId' => self::id('msg'),
            'toolCallId' => $toolCallId,
            'content' => $content,
            'role' => 'tool',
        ];
    }

    /**
     * @param array<string, mixed> $snapshot
     * @return array<string, mixed>
     */
    public static function stateSnapshot(array $snapshot): array
    {
        return ['type' => 'STATE_SNAPSHOT', 'timestamp' => self::now(), 'snapshot' => $snapshot === [] ? new \stdClass() : $snapshot];
    }

    /**
     * @return array<string, mixed>
     */
    public static function custom(string $name, mixed $value): array
    {
        return ['type' => 'CUSTOM', 'timestamp' => self::now(), 'name' => $name, 'value' => $value];
    }
}
