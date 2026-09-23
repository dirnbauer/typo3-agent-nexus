<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Agui\Event;

use Webconsulting\AgentNexus\Agui\Protocol\EventType;
use Webconsulting\AgentNexus\Agui\Protocol\Json;

/**
 * Builds AG-UI 1.0 events, one method per event type.
 *
 * Each event is the array that becomes one SSE `data:` frame. The factory
 * keeps the rules that are easy to get wrong in PHP: an optional field with no
 * value is left out rather than sent as `null`, fields that must be JSON
 * objects stay objects when they are empty, and `timestamp` is an integer
 * count of milliseconds, never a float. Which fields exist is not decided
 * here but in the catalogue, {@see EventType}; the conformance tests check
 * every method against the specification's schema.
 */
final class EventFactory
{
    // ---- runs and steps -------------------------------------------------

    /**
     * Opens a run. `protocolVersion` is this producer's own version, which a
     * 1.0 producer must declare.
     *
     * @param array<string, mixed>|null $input the RunAgentInput to echo back, if any
     * @return array<string, mixed>
     */
    public static function runStarted(string $threadId, string $runId, ?string $parentRunId = null, ?array $input = null): array
    {
        return self::event(EventType::RunStarted, ['threadId' => $threadId, 'runId' => $runId], [
            'protocolVersion' => EventType::PROTOCOL_VERSION,
            'parentRunId' => $parentRunId,
            'input' => $input,
        ]);
    }

    /**
     * Closes a run that did not fail. An absent outcome means success.
     *
     * @param array<string, mixed>|null $outcome {@see self::success()}, {@see self::interrupted()}, {@see self::cancelled()}
     * @param mixed $result the run's return value; `null` means it has none
     * @param list<array<string, mixed>|\stdClass> $usage {@see self::tokenUsage()} entries
     * @return array<string, mixed>
     */
    public static function runFinished(string $threadId, string $runId, ?array $outcome = null, mixed $result = null, array $usage = []): array
    {
        return self::event(EventType::RunFinished, ['threadId' => $threadId, 'runId' => $runId], [
            'result' => $result,
            'outcome' => $outcome,
            'usage' => $usage === [] ? null : $usage,
        ]);
    }

    /**
     * @param list<array<string, mixed>|\stdClass> $usage
     * @return array<string, mixed>
     */
    public static function runError(string $message, ?string $code = null, array $usage = []): array
    {
        return self::event(EventType::RunError, ['message' => $message], [
            'code' => $code,
            'usage' => $usage === [] ? null : $usage,
        ]);
    }

    /** @return array<string, mixed> */
    public static function stepStarted(string $stepName): array
    {
        return self::event(EventType::StepStarted, ['stepName' => $stepName]);
    }

    /** @return array<string, mixed> */
    public static function stepFinished(string $stepName): array
    {
        return self::event(EventType::StepFinished, ['stepName' => $stepName]);
    }

    // ---- run outcomes ---------------------------------------------------

    /**
     * @param list<string> $pendingToolCallIds tool calls the run started and left unanswered
     * @return array<string, mixed>
     */
    public static function success(array $pendingToolCallIds = []): array
    {
        return $pendingToolCallIds === []
            ? ['type' => 'success']
            : ['type' => 'success', 'pendingToolCallIds' => $pendingToolCallIds];
    }

    /**
     * The only conforming way to end a run that stopped to ask something.
     *
     * @param non-empty-list<array<string, mixed>> $interrupts {@see self::interrupt()}
     * @return array<string, mixed>
     */
    public static function interrupted(array $interrupts): array
    {
        return ['type' => 'interrupt', 'interrupts' => $interrupts];
    }

    /** @return array<string, mixed> */
    public static function cancelled(): array
    {
        return ['type' => 'cancelled'];
    }

    /**
     * One thing a run waits for. The next run answers it by `id` in
     * RunAgentInput.resume.
     *
     * @param array<string, mixed>|null $responseSchema JSON Schema of the answer's payload
     * @param array<string, mixed>|null $metadata
     * @return array<string, mixed>
     */
    public static function interrupt(
        string $id,
        string $reason,
        ?string $message = null,
        ?string $toolCallId = null,
        ?array $responseSchema = null,
        ?string $expiresAt = null,
        ?array $metadata = null,
    ): array {
        return self::compact([
            'id' => $id,
            'reason' => $reason,
            'message' => $message,
            'toolCallId' => $toolCallId,
            'responseSchema' => $responseSchema === null ? null : Json::object($responseSchema),
            'expiresAt' => $expiresAt,
            'metadata' => $metadata === null ? null : Json::object($metadata),
        ]);
    }

    /**
     * One provider and model's token usage. An absent count means the
     * provider did not report it — never write a zero you do not have.
     *
     * @return \stdClass|array<string, mixed>
     */
    public static function tokenUsage(
        ?string $provider = null,
        ?string $model = null,
        ?int $inputTokens = null,
        ?int $outputTokens = null,
    ): \stdClass|array {
        $usage = self::compact([
            'provider' => $provider,
            'model' => $model,
            'inputTokens' => $inputTokens,
            'outputTokens' => $outputTokens,
            'totalTokens' => $inputTokens !== null && $outputTokens !== null ? $inputTokens + $outputTokens : null,
        ]);
        return Json::object($usage);
    }

    // ---- text messages --------------------------------------------------

    /**
     * @param string|null $role developer, system, assistant or user; absent means assistant
     * @return array<string, mixed>
     */
    public static function textMessageStart(string $messageId, ?string $role = 'assistant', ?string $name = null): array
    {
        return self::event(EventType::TextMessageStart, ['messageId' => $messageId], ['role' => $role, 'name' => $name]);
    }

    /** @return array<string, mixed> */
    public static function textMessageContent(string $messageId, string $delta): array
    {
        return self::event(EventType::TextMessageContent, ['messageId' => $messageId, 'delta' => $delta]);
    }

    /** @return array<string, mixed> */
    public static function textMessageEnd(string $messageId): array
    {
        return self::event(EventType::TextMessageEnd, ['messageId' => $messageId]);
    }

    /**
     * The compact form. The first chunk of a message must carry its id.
     *
     * @return array<string, mixed>
     */
    public static function textMessageChunk(?string $messageId = null, ?string $delta = null, ?string $role = null, ?string $name = null): array
    {
        return self::event(EventType::TextMessageChunk, [], ['messageId' => $messageId, 'role' => $role, 'delta' => $delta, 'name' => $name]);
    }

    // ---- tool calls -----------------------------------------------------

    /** @return array<string, mixed> */
    public static function toolCallStart(string $toolCallId, string $toolCallName, ?string $parentMessageId = null): array
    {
        return self::event(EventType::ToolCallStart, ['toolCallId' => $toolCallId, 'toolCallName' => $toolCallName], ['parentMessageId' => $parentMessageId]);
    }

    /** @return array<string, mixed> */
    public static function toolCallArgs(string $toolCallId, string $delta): array
    {
        return self::event(EventType::ToolCallArgs, ['toolCallId' => $toolCallId, 'delta' => $delta]);
    }

    /** @return array<string, mixed> */
    public static function toolCallEnd(string $toolCallId): array
    {
        return self::event(EventType::ToolCallEnd, ['toolCallId' => $toolCallId]);
    }

    /**
     * The compact form. The first chunk of a call must carry its id and name.
     *
     * @return array<string, mixed>
     */
    public static function toolCallChunk(?string $toolCallId = null, ?string $toolCallName = null, ?string $delta = null, ?string $parentMessageId = null): array
    {
        return self::event(EventType::ToolCallChunk, [], [
            'toolCallId' => $toolCallId,
            'toolCallName' => $toolCallName,
            'parentMessageId' => $parentMessageId,
            'delta' => $delta,
        ]);
    }

    /**
     * The result of a call, as a new tool message with its own id.
     *
     * @param string|list<array<string, mixed>> $content a string or content parts
     * @return array<string, mixed>
     */
    public static function toolCallResult(string $messageId, string $toolCallId, string|array $content): array
    {
        return self::event(EventType::ToolCallResult, ['messageId' => $messageId, 'toolCallId' => $toolCallId, 'content' => $content], ['role' => 'tool']);
    }

    // ---- reasoning ------------------------------------------------------

    /**
     * Opens a reasoning span. The span's id names the span only; the messages
     * inside it carry ids of their own.
     *
     * @return array<string, mixed>
     */
    public static function reasoningStart(string $spanId): array
    {
        return self::event(EventType::ReasoningStart, ['messageId' => $spanId]);
    }

    /** @return array<string, mixed> */
    public static function reasoningMessageStart(string $messageId): array
    {
        return self::event(EventType::ReasoningMessageStart, ['messageId' => $messageId, 'role' => 'reasoning']);
    }

    /** @return array<string, mixed> */
    public static function reasoningMessageContent(string $messageId, string $delta): array
    {
        return self::event(EventType::ReasoningMessageContent, ['messageId' => $messageId, 'delta' => $delta]);
    }

    /** @return array<string, mixed> */
    public static function reasoningMessageEnd(string $messageId): array
    {
        return self::event(EventType::ReasoningMessageEnd, ['messageId' => $messageId]);
    }

    /** @return array<string, mixed> */
    public static function reasoningMessageChunk(?string $messageId = null, ?string $delta = null): array
    {
        return self::event(EventType::ReasoningMessageChunk, [], ['messageId' => $messageId, 'delta' => $delta]);
    }

    /** @return array<string, mixed> */
    public static function reasoningEnd(string $spanId): array
    {
        return self::event(EventType::ReasoningEnd, ['messageId' => $spanId]);
    }

    /**
     * @param string $subtype "message" or "tool-call": what `$entityId` names
     * @return array<string, mixed>
     */
    public static function reasoningEncryptedValue(string $subtype, string $entityId, string $encryptedValue): array
    {
        return self::event(EventType::ReasoningEncryptedValue, ['subtype' => $subtype, 'entityId' => $entityId, 'encryptedValue' => $encryptedValue]);
    }

    // ---- state ----------------------------------------------------------

    /**
     * Replaces the shared state. Any JSON value; an empty PHP array is sent
     * as `{}`.
     *
     * @return array<string, mixed>
     */
    public static function stateSnapshot(mixed $snapshot): array
    {
        return self::event(EventType::StateSnapshot, ['snapshot' => $snapshot === [] ? new \stdClass() : $snapshot]);
    }

    /**
     * @param list<array<string, mixed>> $patch RFC 6902 operations
     * @return array<string, mixed>
     */
    public static function stateDelta(array $patch): array
    {
        return self::event(EventType::StateDelta, ['delta' => $patch]);
    }

    /**
     * @param list<array<string, mixed>> $messages
     * @return array<string, mixed>
     */
    public static function messagesSnapshot(array $messages): array
    {
        return self::event(EventType::MessagesSnapshot, ['messages' => $messages]);
    }

    // ---- activity -------------------------------------------------------

    /**
     * Creates or replaces one activity message: structured progress a UI
     * renders as its own widget.
     *
     * @param array<string, mixed> $content always sent as a JSON object
     * @return array<string, mixed>
     */
    public static function activitySnapshot(string $messageId, string $activityType, array $content, ?bool $replace = null): array
    {
        return self::event(EventType::ActivitySnapshot, [
            'messageId' => $messageId,
            'activityType' => $activityType,
            'content' => Json::object($content),
        ], ['replace' => $replace]);
    }

    /**
     * @param list<array<string, mixed>> $patch RFC 6902 operations against the activity's content
     * @return array<string, mixed>
     */
    public static function activityDelta(string $messageId, string $activityType, array $patch): array
    {
        return self::event(EventType::ActivityDelta, ['messageId' => $messageId, 'activityType' => $activityType, 'patch' => $patch]);
    }

    // ---- subagents ------------------------------------------------------

    /** @return array<string, mixed> */
    public static function subagentStarted(
        string $subagentRunId,
        string $name,
        ?string $description = null,
        ?string $parentSubagentRunId = null,
        ?string $parentToolCallId = null,
        ?string $parentMessageId = null,
    ): array {
        return self::event(EventType::SubagentStarted, ['subagentRunId' => $subagentRunId, 'name' => $name], [
            'description' => $description,
            'parentSubagentRunId' => $parentSubagentRunId,
            'parentToolCallId' => $parentToolCallId,
            'parentMessageId' => $parentMessageId,
        ]);
    }

    /**
     * @param list<string>|null $suspendedFor interrupt ids of a suspended invocation; null = success
     * @return array<string, mixed>
     */
    public static function subagentFinished(string $subagentRunId, mixed $result = null, ?array $suspendedFor = null): array
    {
        $outcome = null;
        if ($suspendedFor !== null) {
            $outcome = $suspendedFor === [] ? ['type' => 'suspended'] : ['type' => 'suspended', 'interruptIds' => $suspendedFor];
        }
        return self::event(EventType::SubagentFinished, ['subagentRunId' => $subagentRunId], ['result' => $result, 'outcome' => $outcome]);
    }

    /** @return array<string, mixed> */
    public static function subagentError(string $subagentRunId, string $message, ?string $code = null): array
    {
        return self::event(EventType::SubagentError, ['subagentRunId' => $subagentRunId, 'message' => $message], ['code' => $code]);
    }

    // ---- passthrough ----------------------------------------------------

    /**
     * An application's own event. Names should carry a vendor prefix;
     * unprefixed names are reserved for the protocol.
     *
     * @return array<string, mixed>
     */
    public static function custom(string $name, mixed $value): array
    {
        return self::event(EventType::Custom, ['name' => $name, 'value' => $value]);
    }

    /** @return array<string, mixed> */
    public static function raw(mixed $event, ?string $source = null): array
    {
        return self::event(EventType::Raw, ['event' => $event], ['source' => $source]);
    }

    // ---- envelope -------------------------------------------------------

    /**
     * The same event with metadata. The `ag-ui` key is reserved; prefix your
     * own keys.
     *
     * @param array<string, mixed> $event
     * @param array<string, mixed> $metadata
     * @return array<string, mixed>
     */
    public static function withMetadata(array $event, array $metadata): array
    {
        $event['metadata'] = Json::object($metadata);
        return $event;
    }

    /**
     * The same event attributed to a subagent invocation.
     *
     * @param array<string, mixed> $event
     * @return array<string, mixed>
     */
    public static function attributedTo(array $event, string $subagentRunId): array
    {
        $type = EventType::tryFrom(is_string($event['type'] ?? null) ? $event['type'] : '');
        if ($type === null || !$type->attributable()) {
            throw new \LogicException(sprintf('%s events cannot be attributed to a subagent.', $type->value ?? 'Unknown'), 1758700120);
        }
        $event['subagentRunId'] = $subagentRunId;
        return $event;
    }

    /** A fresh opaque identifier, e.g. `msg_3f9a…`. */
    public static function mintId(string $prefix): string
    {
        return $prefix . '_' . bin2hex(random_bytes(8));
    }

    /** Milliseconds since the Unix epoch, as an integer. */
    public static function now(): int
    {
        return (int)floor(microtime(true) * 1000);
    }

    /**
     * @param array<string, mixed> $required kept even when null (CUSTOM.value may be null)
     * @param array<string, mixed> $optional left out when null
     * @return array<string, mixed>
     */
    private static function event(EventType $type, array $required, array $optional = []): array
    {
        return ['type' => $type->value] + $required + self::compact($optional) + ['timestamp' => self::now()];
    }

    /**
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    private static function compact(array $fields): array
    {
        return array_filter($fields, static fn(mixed $value): bool => $value !== null);
    }
}
