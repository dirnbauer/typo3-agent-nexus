<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Agui\Protocol;

/**
 * An AG-UI stream broke a rule of the 1.0 specification.
 *
 * Raised by {@see EventVerifier}; the message names the event, its position
 * in the stream and what was wrong, so a failing test reads as a diagnosis.
 */
final class ProtocolViolation extends \RuntimeException
{
    public function __construct(
        public readonly OrderingRule $rule,
        string $message,
        public readonly int $position = -1,
    ) {
        parent::__construct(
            sprintf('[%s]%s %s', $rule->value, $position >= 0 ? ' event #' . $position . ':' : '', $message),
            1758700100,
        );
    }
}
