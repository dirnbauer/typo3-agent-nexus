<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Agui\Protocol;

/**
 * What an event field may hold, as far as AG-UI 1.0's schema says.
 *
 * The event catalogue ({@see EventType}) names one of these per field; the
 * verifier checks values against it and the backend event reference prints
 * {@see self::label()} next to the field name.
 */
enum FieldType: string
{
    case String = 'string';
    case Boolean = 'boolean';
    /** A whole number; timestamps are milliseconds since the Unix epoch, never a float. */
    case Integer = 'integer';
    /** Any JSON value, `null` included: a required payload such as CUSTOM.value. */
    case Json = 'json';
    /** Any JSON value except `null`: an optional payload such as RUN_FINISHED.result. */
    case JsonValue = 'json-value';
    /** A JSON object; open by key. */
    case Object = 'object';
    case JsonPatch = 'json-patch';
    case TokenUsageList = 'token-usage';
    case RunOutcome = 'run-outcome';
    case SubagentOutcome = 'subagent-outcome';
    case RunInput = 'run-input';
    case MessageList = 'messages';
    /** A tool result: a string or a list of content parts. */
    case ToolContent = 'tool-content';
    case TextRole = 'text-role';
    case ReasoningRole = 'reasoning-role';
    case ToolRole = 'tool-role';
    case EncryptedSubtype = 'encrypted-subtype';

    /** The type as the event reference shows it. */
    public function label(): string
    {
        return match ($this) {
            self::String => 'string',
            self::Boolean => 'boolean',
            self::Integer => 'integer',
            self::Json => 'JSON',
            self::JsonValue => 'JSON, not null',
            self::Object => 'object',
            self::JsonPatch => 'JSON Patch (RFC 6902)',
            self::TokenUsageList => 'TokenUsage[]',
            self::RunOutcome => 'success | interrupt | cancelled',
            self::SubagentOutcome => 'success | suspended',
            self::RunInput => 'RunAgentInput',
            self::MessageList => 'Message[]',
            self::ToolContent => 'string | ContentPart[]',
            self::TextRole => 'developer | system | assistant | user',
            self::ReasoningRole => '"reasoning"',
            self::ToolRole => '"tool"',
            self::EncryptedSubtype => 'tool-call | message',
        };
    }
}
