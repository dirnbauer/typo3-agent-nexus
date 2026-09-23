<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\A2a\Protocol;

/**
 * Every error this agent can answer with, and how each binding spells it.
 *
 * The A2A-specific errors and their codes, HTTP statuses and gRPC statuses
 * come from specification section 5.4; the reason is the error name in
 * UPPER_SNAKE_CASE without "Error" (section 11.6). The JSON-RPC errors are the
 * standard ones of JSON-RPC 2.0 with the messages section 9.5 names.
 *
 * {@see RateLimited} is this installation's own: A2A files rate limiting under
 * "system errors" without a code of its own, so it takes -32000, the one code of
 * the JSON-RPC server range that A2A leaves unused, and travels with HTTP 429.
 */
enum A2aError: string
{
    case TaskNotFound = 'TASK_NOT_FOUND';
    case TaskNotCancelable = 'TASK_NOT_CANCELABLE';
    case PushNotificationNotSupported = 'PUSH_NOTIFICATION_NOT_SUPPORTED';
    case UnsupportedOperation = 'UNSUPPORTED_OPERATION';
    case ContentTypeNotSupported = 'CONTENT_TYPE_NOT_SUPPORTED';
    case InvalidAgentResponse = 'INVALID_AGENT_RESPONSE';
    case ExtendedAgentCardNotConfigured = 'EXTENDED_AGENT_CARD_NOT_CONFIGURED';
    case ExtensionSupportRequired = 'EXTENSION_SUPPORT_REQUIRED';
    case VersionNotSupported = 'VERSION_NOT_SUPPORTED';
    case ParseError = 'PARSE_ERROR';
    case InvalidRequest = 'INVALID_REQUEST';
    case MethodNotFound = 'METHOD_NOT_FOUND';
    case InvalidParams = 'INVALID_PARAMS';
    case Internal = 'INTERNAL';
    case RateLimited = 'RATE_LIMIT_EXCEEDED';

    /** The `domain` of the google.rpc.ErrorInfo of an A2A-specific error. */
    public const string A2A_DOMAIN = 'a2a-protocol.org';

    /** The `domain` of the ErrorInfo of this installation's own errors. */
    public const string OWN_DOMAIN = 'agent-nexus.webconsulting.at';

    public function code(): int
    {
        return match ($this) {
            self::TaskNotFound => -32001,
            self::TaskNotCancelable => -32002,
            self::PushNotificationNotSupported => -32003,
            self::UnsupportedOperation => -32004,
            self::ContentTypeNotSupported => -32005,
            self::InvalidAgentResponse => -32006,
            self::ExtendedAgentCardNotConfigured => -32007,
            self::ExtensionSupportRequired => -32008,
            self::VersionNotSupported => -32009,
            self::ParseError => -32700,
            self::InvalidRequest => -32600,
            self::MethodNotFound => -32601,
            self::InvalidParams => -32602,
            self::Internal => -32603,
            self::RateLimited => -32000,
        };
    }

    public function httpStatus(): int
    {
        return match ($this) {
            self::TaskNotFound, self::MethodNotFound => 404,
            self::InvalidAgentResponse, self::Internal => 500,
            self::RateLimited => 429,
            default => 400,
        };
    }

    /** The google.rpc.Code name the HTTP+JSON binding puts in `error.status`. */
    public function rpcStatus(): string
    {
        return match ($this) {
            self::TaskNotFound, self::MethodNotFound => 'NOT_FOUND',
            self::ContentTypeNotSupported, self::ParseError, self::InvalidRequest, self::InvalidParams => 'INVALID_ARGUMENT',
            self::InvalidAgentResponse, self::Internal => 'INTERNAL',
            self::RateLimited => 'RESOURCE_EXHAUSTED',
            default => 'FAILED_PRECONDITION',
        };
    }

    /** One of the nine errors A2A defines (codes -32001 to -32009). */
    public function isA2aSpecific(): bool
    {
        return $this->code() <= -32001 && $this->code() >= -32099;
    }

    /** The error name as the specification writes it. */
    public function specName(): string
    {
        return match ($this) {
            self::ParseError => 'JSONParseError',
            self::Internal => 'InternalError',
            self::RateLimited => 'RateLimitExceeded',
            default => str_replace('_', '', ucwords(strtolower($this->value), '_')) . 'Error',
        };
    }

    public function defaultMessage(): string
    {
        return match ($this) {
            self::TaskNotFound => 'Task not found',
            self::TaskNotCancelable => 'Task cannot be canceled',
            self::PushNotificationNotSupported => 'Push notifications are not supported',
            self::UnsupportedOperation => 'This operation is not supported',
            self::ContentTypeNotSupported => 'Incompatible content types',
            self::InvalidAgentResponse => 'Invalid agent response',
            self::ExtendedAgentCardNotConfigured => 'Extended agent card is not configured',
            self::ExtensionSupportRequired => 'Extension support required',
            self::VersionNotSupported => 'Version not supported',
            self::ParseError => 'Invalid JSON payload',
            self::InvalidRequest => 'Request payload validation error',
            self::MethodNotFound => 'Method not found',
            self::InvalidParams => 'Invalid parameters',
            self::Internal => 'Internal error',
            self::RateLimited => 'Rate limit exceeded',
        };
    }
}
