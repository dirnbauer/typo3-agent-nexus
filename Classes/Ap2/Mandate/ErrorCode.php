<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Ap2\Mandate;

/**
 * The action-authorization errors AP2 v0.2 defines; a receipt with status
 * `Error` carries one of them.
 */
enum ErrorCode: string
{
    /** The mandate fails verification. Terminal. */
    case InvalidCredential = 'invalid_credential';
    /** The mandate is valid but does not approve this action. Terminal. */
    case InvalidMandate = 'invalid_mandate';
    /**
     * A constraint is unknown, or the closed mandate cannot be shown to meet
     * it. A signal to fall back to a mandate the user approves directly.
     */
    case UnresolvedConstraint = 'unresolved_constraint';
    /** The verifier does not accept mandates for this action. */
    case MandatesNotSupported = 'mandates_not_supported';

    public function isTerminal(): bool
    {
        return $this === self::InvalidCredential || $this === self::InvalidMandate;
    }

    /** Lower wins when several checks fail. */
    public function precedence(): int
    {
        return match ($this) {
            self::InvalidCredential => 0,
            self::InvalidMandate => 1,
            self::UnresolvedConstraint => 2,
            self::MandatesNotSupported => 3,
        };
    }

    /** English explanation, for receipts and API responses. */
    public function description(): string
    {
        return match ($this) {
            self::InvalidCredential => 'The mandate failed verification.',
            self::InvalidMandate => 'The mandate does not approve this action.',
            self::UnresolvedConstraint => 'The purchase does not meet a constraint the user set, so the user must approve it directly.',
            self::MandatesNotSupported => 'This verifier does not accept mandates for this action.',
        };
    }
}
