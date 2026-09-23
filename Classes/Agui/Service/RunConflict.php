<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Agui\Service;

/**
 * A run input that is well formed but cannot start: it reuses a run id,
 * answers an interrupt that is not open, or starts a new run while an
 * interrupt waits for an answer. Refused with 409 before any stream opens.
 */
final class RunConflict extends \RuntimeException
{
    public function __construct(
        public readonly string $reason,
        string $message,
    ) {
        parent::__construct($message, 1758700140);
    }
}
