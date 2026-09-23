<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Agui\Protocol;

/**
 * A RunAgentInput the producer rejects before the run starts.
 *
 * The specification sends such a rejection through the transport's error
 * path, not as RUN_ERROR: the run never starts and no stream exists. The HTTP
 * binding answers with `$status` and a JSON error body.
 */
final class InvalidRunInput extends \RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $reason,
        string $message,
        public readonly string $pointer = '',
    ) {
        parent::__construct($message, 1758700130);
    }
}
