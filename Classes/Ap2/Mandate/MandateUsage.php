<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Ap2\Mandate;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * How often an open mandate already authorised a purchase, and for how much
 * — what `payment.agent_recurrence`, `payment.budget` and single use are
 * evaluated against (the SDK's `MandateContext`).
 */
#[Exclude]
final readonly class MandateUsage
{
    public function __construct(
        public int $uses = 0,
        public int $amount = 0,
        public ?int $lastUse = null,
    ) {}

    public function then(int $amount, int $at): self
    {
        return new self($this->uses + 1, $this->amount + $amount, $at);
    }
}
