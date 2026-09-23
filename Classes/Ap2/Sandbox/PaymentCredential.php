<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Ap2\Sandbox;

use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Webconsulting\AgentNexus\Ap2\Crypto\DelegateChain;

/**
 * What the credential provider releases once the payment mandate verified: a
 * token scoped to that mandate. In AP2 a network token or similar; here a
 * sandbox string that carries the closed payment mandate inside it, which is
 * how the specification suggests the processor can check the scope.
 */
#[Exclude]
final readonly class PaymentCredential
{
    public function __construct(
        public string $token,
        public DelegateChain $mandate,
        public string $nonce,
    ) {}
}
