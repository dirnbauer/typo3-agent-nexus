<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Ap2\Sandbox;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * A checkout the merchant signed: the UCP checkout object, the compact JWT
 * AP2 binds mandates to, and its hash (`checkout_hash`, and the payment
 * mandate's `transaction_id`).
 */
#[Exclude]
final readonly class SignedCheckout
{
    /**
     * @param array<string, mixed> $checkout
     */
    public function __construct(
        public array $checkout,
        public string $jwt,
        public string $hash,
        public int $total,
        public string $currency,
    ) {}
}
