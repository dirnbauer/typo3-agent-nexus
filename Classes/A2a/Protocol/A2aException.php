<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\A2a\Protocol;

/**
 * A protocol error on its way to the client.
 *
 * The operations throw it; each binding renders it in its own shape — a
 * JSON-RPC error object ({@see toJsonRpcError()}) or a google.rpc.Status body
 * ({@see toRestError()}). Both carry the same details: a google.rpc.ErrorInfo
 * for A2A's own errors (and this installation's rate limit), a
 * google.rpc.BadRequest listing the fields that failed validation.
 */
final class A2aException extends \RuntimeException
{
    private const string ERROR_INFO = 'type.googleapis.com/google.rpc.ErrorInfo';
    private const string BAD_REQUEST = 'type.googleapis.com/google.rpc.BadRequest';

    /**
     * @param array<string, string> $metadata      ErrorInfo metadata (string values only, as google.rpc requires)
     * @param list<array{field: string, description: string}> $fieldViolations
     */
    public function __construct(
        public readonly A2aError $error,
        string $message = '',
        public readonly array $metadata = [],
        public readonly array $fieldViolations = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message !== '' ? $message : $error->defaultMessage(), $error->code(), $previous);
    }

    public static function taskNotFound(string $taskId): self
    {
        return new self(A2aError::TaskNotFound, sprintf('Task "%s" does not exist or has been deleted.', $taskId), ['taskId' => $taskId]);
    }

    public static function invalidParams(string $field, string $description): self
    {
        return new self(A2aError::InvalidParams, 'Invalid parameters: ' . $field . ' ' . $description, [], [
            ['field' => $field, 'description' => $description],
        ]);
    }

    public static function pushNotificationsNotSupported(): self
    {
        return new self(
            A2aError::PushNotificationNotSupported,
            'This agent does not send push notifications. Stream the task or poll it with GetTask instead.',
        );
    }

    /**
     * The details every binding attaches: an ErrorInfo for A2A errors and
     * this installation's own, a BadRequest for validation failures.
     *
     * @return list<array<string, mixed>>
     */
    public function details(): array
    {
        $details = [];
        if ($this->error->isA2aSpecific() || $this->error === A2aError::RateLimited) {
            $metadata = $this->metadata;
            $metadata['timestamp'] ??= Timestamp::now();
            $details[] = [
                '@type' => self::ERROR_INFO,
                'reason' => $this->error->value,
                'domain' => $this->error->isA2aSpecific() ? A2aError::A2A_DOMAIN : A2aError::OWN_DOMAIN,
                'metadata' => $metadata,
            ];
        }
        if ($this->fieldViolations !== []) {
            $details[] = ['@type' => self::BAD_REQUEST, 'fieldViolations' => $this->fieldViolations];
        }
        return $details;
    }

    /**
     * The JSON-RPC 2.0 error object (the value of the response's `error`).
     *
     * @return array{code: int, message: string, data?: list<array<string, mixed>>}
     */
    public function toJsonRpcError(): array
    {
        $error = ['code' => $this->error->code(), 'message' => $this->getMessage()];
        $details = $this->details();
        if ($details !== []) {
            $error['data'] = $details;
        }
        return $error;
    }

    /**
     * The HTTP+JSON error body: a google.rpc.Status under `error`.
     *
     * @return array{error: array{code: int, status: string, message: string, details: list<array<string, mixed>>}}
     */
    public function toRestError(): array
    {
        return ['error' => [
            'code' => $this->error->httpStatus(),
            'status' => $this->error->rpcStatus(),
            'message' => $this->getMessage(),
            'details' => $this->details(),
        ]];
    }
}
