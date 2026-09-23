<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Agui\Protocol;

/**
 * The AG-UI 1.0 event catalogue: all 31 event types, their family and their
 * fields.
 *
 * This is the one place the event set is written down. The event factory
 * builds events from it, the stream verifier checks events against it and the
 * backend event reference prints it; a conformance test holds it against the
 * specification's own schema (spec/1.0/schema.json).
 *
 * Every event may also carry the envelope fields `timestamp`, `rawEvent` and
 * `metadata` ({@see self::ENVELOPE}); attributable events may carry
 * `subagentRunId` ({@see self::attributable()}). Everything else is closed: a
 * field not listed here is invalid.
 */
enum EventType: string
{
    case RunStarted = 'RUN_STARTED';
    case RunFinished = 'RUN_FINISHED';
    case RunError = 'RUN_ERROR';
    case StepStarted = 'STEP_STARTED';
    case StepFinished = 'STEP_FINISHED';
    case TextMessageStart = 'TEXT_MESSAGE_START';
    case TextMessageContent = 'TEXT_MESSAGE_CONTENT';
    case TextMessageEnd = 'TEXT_MESSAGE_END';
    case TextMessageChunk = 'TEXT_MESSAGE_CHUNK';
    case ToolCallStart = 'TOOL_CALL_START';
    case ToolCallArgs = 'TOOL_CALL_ARGS';
    case ToolCallEnd = 'TOOL_CALL_END';
    case ToolCallChunk = 'TOOL_CALL_CHUNK';
    case ToolCallResult = 'TOOL_CALL_RESULT';
    case ReasoningStart = 'REASONING_START';
    case ReasoningMessageStart = 'REASONING_MESSAGE_START';
    case ReasoningMessageContent = 'REASONING_MESSAGE_CONTENT';
    case ReasoningMessageEnd = 'REASONING_MESSAGE_END';
    case ReasoningMessageChunk = 'REASONING_MESSAGE_CHUNK';
    case ReasoningEnd = 'REASONING_END';
    case ReasoningEncryptedValue = 'REASONING_ENCRYPTED_VALUE';
    case StateSnapshot = 'STATE_SNAPSHOT';
    case StateDelta = 'STATE_DELTA';
    case MessagesSnapshot = 'MESSAGES_SNAPSHOT';
    case ActivitySnapshot = 'ACTIVITY_SNAPSHOT';
    case ActivityDelta = 'ACTIVITY_DELTA';
    case SubagentStarted = 'SUBAGENT_STARTED';
    case SubagentFinished = 'SUBAGENT_FINISHED';
    case SubagentError = 'SUBAGENT_ERROR';
    case Raw = 'RAW';
    case Custom = 'CUSTOM';

    /** The protocol version this catalogue describes. */
    public const string PROTOCOL_VERSION = '1.0';

    /** Fields every event may carry, whatever its type (the schema's BaseEvent). */
    public const array ENVELOPE = [
        'timestamp' => FieldType::Integer,
        'rawEvent' => FieldType::JsonValue,
        'metadata' => FieldType::Object,
    ];

    public function family(): EventFamily
    {
        return match ($this) {
            self::RunStarted, self::RunFinished, self::RunError, self::StepStarted, self::StepFinished => EventFamily::Lifecycle,
            self::TextMessageStart, self::TextMessageContent, self::TextMessageEnd, self::TextMessageChunk => EventFamily::TextMessages,
            self::ToolCallStart, self::ToolCallArgs, self::ToolCallEnd, self::ToolCallChunk, self::ToolCallResult => EventFamily::ToolCalls,
            self::ReasoningStart, self::ReasoningMessageStart, self::ReasoningMessageContent, self::ReasoningMessageEnd,
            self::ReasoningMessageChunk, self::ReasoningEnd, self::ReasoningEncryptedValue => EventFamily::Reasoning,
            self::StateSnapshot, self::StateDelta, self::MessagesSnapshot => EventFamily::State,
            self::ActivitySnapshot, self::ActivityDelta => EventFamily::Activity,
            self::SubagentStarted, self::SubagentFinished, self::SubagentError => EventFamily::Subagents,
            self::Raw, self::Custom => EventFamily::Passthrough,
        };
    }

    /**
     * Fields the event must carry, besides `type`.
     *
     * @return array<string, FieldType>
     */
    public function required(): array
    {
        return match ($this) {
            self::RunStarted, self::RunFinished => ['threadId' => FieldType::String, 'runId' => FieldType::String],
            self::RunError => ['message' => FieldType::String],
            self::StepStarted, self::StepFinished => ['stepName' => FieldType::String],
            self::TextMessageStart, self::TextMessageEnd, self::ReasoningStart, self::ReasoningMessageEnd, self::ReasoningEnd => ['messageId' => FieldType::String],
            self::TextMessageContent, self::ReasoningMessageContent => ['messageId' => FieldType::String, 'delta' => FieldType::String],
            self::TextMessageChunk, self::ToolCallChunk, self::ReasoningMessageChunk => [],
            self::ToolCallStart => ['toolCallId' => FieldType::String, 'toolCallName' => FieldType::String],
            self::ToolCallArgs => ['toolCallId' => FieldType::String, 'delta' => FieldType::String],
            self::ToolCallEnd => ['toolCallId' => FieldType::String],
            self::ToolCallResult => ['messageId' => FieldType::String, 'toolCallId' => FieldType::String, 'content' => FieldType::ToolContent],
            self::ReasoningMessageStart => ['messageId' => FieldType::String, 'role' => FieldType::ReasoningRole],
            self::ReasoningEncryptedValue => ['subtype' => FieldType::EncryptedSubtype, 'entityId' => FieldType::String, 'encryptedValue' => FieldType::String],
            self::StateSnapshot => ['snapshot' => FieldType::Json],
            self::StateDelta => ['delta' => FieldType::JsonPatch],
            self::MessagesSnapshot => ['messages' => FieldType::MessageList],
            self::ActivitySnapshot => ['messageId' => FieldType::String, 'activityType' => FieldType::String, 'content' => FieldType::Object],
            self::ActivityDelta => ['messageId' => FieldType::String, 'activityType' => FieldType::String, 'patch' => FieldType::JsonPatch],
            self::SubagentStarted => ['subagentRunId' => FieldType::String, 'name' => FieldType::String],
            self::SubagentFinished => ['subagentRunId' => FieldType::String],
            self::SubagentError => ['subagentRunId' => FieldType::String, 'message' => FieldType::String],
            self::Raw => ['event' => FieldType::Json],
            self::Custom => ['name' => FieldType::String, 'value' => FieldType::Json],
        };
    }

    /**
     * Fields the event may carry, besides the envelope and `subagentRunId`.
     * An optional field without a value is omitted, never sent as `null`.
     *
     * @return array<string, FieldType>
     */
    public function optional(): array
    {
        return match ($this) {
            self::RunStarted => ['protocolVersion' => FieldType::String, 'parentRunId' => FieldType::String, 'input' => FieldType::RunInput],
            self::RunFinished => ['result' => FieldType::JsonValue, 'outcome' => FieldType::RunOutcome, 'usage' => FieldType::TokenUsageList],
            self::RunError => ['code' => FieldType::String, 'usage' => FieldType::TokenUsageList],
            self::TextMessageStart => ['role' => FieldType::TextRole, 'name' => FieldType::String],
            self::TextMessageChunk => ['messageId' => FieldType::String, 'role' => FieldType::TextRole, 'delta' => FieldType::String, 'name' => FieldType::String],
            self::ToolCallStart => ['parentMessageId' => FieldType::String],
            self::ToolCallChunk => ['toolCallId' => FieldType::String, 'toolCallName' => FieldType::String, 'parentMessageId' => FieldType::String, 'delta' => FieldType::String],
            self::ToolCallResult => ['role' => FieldType::ToolRole],
            self::ReasoningMessageChunk => ['messageId' => FieldType::String, 'delta' => FieldType::String],
            self::ActivitySnapshot => ['replace' => FieldType::Boolean],
            self::SubagentStarted => [
                'description' => FieldType::String,
                'parentSubagentRunId' => FieldType::String,
                'parentToolCallId' => FieldType::String,
                'parentMessageId' => FieldType::String,
            ],
            self::SubagentFinished => ['result' => FieldType::JsonValue, 'outcome' => FieldType::SubagentOutcome],
            self::SubagentError => ['code' => FieldType::String],
            self::Raw => ['source' => FieldType::String],
            default => [],
        };
    }

    /**
     * Whether the event can belong to a subagent's work and so may carry an
     * optional `subagentRunId`. Run-scoped events (RUN_*, MESSAGES_SNAPSHOT)
     * describe the run as a whole; the SUBAGENT_* events carry the id as a
     * required field of their own.
     */
    public function attributable(): bool
    {
        return match ($this) {
            self::RunStarted, self::RunFinished, self::RunError, self::MessagesSnapshot,
            self::SubagentStarted, self::SubagentFinished, self::SubagentError => false,
            default => true,
        };
    }

    /** The `$anchor` of this event's definition in the 1.0 schema. */
    public function schemaAnchor(): string
    {
        return $this->name . 'Event';
    }

    /**
     * Every field the event may carry, in wire order: `type`, the required
     * fields, the optional ones, then attribution and the envelope.
     *
     * @return list<string>
     */
    public function fieldNames(): array
    {
        $names = ['type', ...array_keys($this->required()), ...array_keys($this->optional())];
        if ($this->attributable()) {
            $names[] = 'subagentRunId';
        }
        return [...$names, ...array_keys(self::ENVELOPE)];
    }
}
