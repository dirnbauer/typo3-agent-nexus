<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Ucp\Agent;

/**
 * One answer in RunAgentInput.resume: which interrupt, whether it was
 * answered or abandoned, and the answer itself.
 *
 * The approval asks for `{approved: boolean, email?: string}`. Anything but an
 * explicit `approved: true` in a resolved entry is a no — a missing, malformed
 * or abandoned answer never places an order.
 */
final readonly class ResumeEntry
{
    public const string RESOLVED = 'resolved';
    public const string CANCELLED = 'cancelled';

    public function __construct(
        public string $interruptId,
        public string $status,
        public mixed $payload = null,
    ) {}

    public function approves(): bool
    {
        return $this->status === self::RESOLVED
            && is_array($this->payload)
            && ($this->payload['approved'] ?? null) === true;
    }

    /** The email address the visitor gave with the answer, if any. */
    public function email(): string
    {
        $email = is_array($this->payload) ? ($this->payload['email'] ?? null) : null;
        return is_string($email) ? mb_substr(trim($email), 0, 254) : '';
    }

    /** The same answer again — which a replayed resume must be safe for. */
    public function decision(): string
    {
        return $this->approves() ? 'approved' : 'declined';
    }
}
