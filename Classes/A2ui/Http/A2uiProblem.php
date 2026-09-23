<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\A2ui\Http;

use Webconsulting\AgentNexus\A2ui\Domain\Model\A2uiVersion;

/**
 * A request the A2UI binding refuses, with the HTTP status and the A2UI error
 * code it answers with.
 *
 * The body of the answer is an A2UI error message — the shape a renderer uses
 * to report errors to an agent: `VALIDATION_FAILED` with the JSON Pointer of
 * the offending field, or a generic error with a code of this binding's own
 * (`SURFACE_NOT_FOUND`, `RATE_LIMITED` …).
 */
final class A2uiProblem extends \RuntimeException
{
    public const string VALIDATION_FAILED = 'VALIDATION_FAILED';

    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly string $surfaceId = '',
        public readonly ?string $path = null,
        public readonly ?A2uiVersion $version = null,
        public readonly int $retryAfter = 0,
    ) {
        parent::__construct($message, $status);
    }

    public static function badRequest(string $message): self
    {
        return new self(400, 'BAD_REQUEST', $message);
    }

    public static function invalid(string $path, string $message, string $surfaceId = '', ?A2uiVersion $version = null): self
    {
        return new self(422, self::VALIDATION_FAILED, $message, $surfaceId, $path, $version);
    }
}
