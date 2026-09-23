<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Ap2\Mandate;

use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Webconsulting\AgentNexus\Ap2\Crypto\DecodedJws;
use Webconsulting\AgentNexus\Ap2\Crypto\EcKey;

/**
 * What a verifier knows besides the mandate itself: whom it trusts, what it
 * asked the agent for, and what it remembers.
 */
#[Exclude]
final readonly class VerificationContext
{
    /**
     * @param \Closure(DecodedJws): ?EcKey $rootKey trusted key for a root token (the Trusted Surface's)
     * @param string $audience the `aud` this verifier expects ("merchant", "credential-provider")
     * @param (\Closure(string): bool)|string|null $nonce the challenge it issued, or a test for "one of mine"
     * @param EcKey|null $merchantKey checkout: the merchant's own public key, to check the checkout JWT
     * @param string|null $checkoutJwt checkout: the checkout of the current session; null accepts any it issued
     * @param string|null $transactionId payment: the checkout hash the payment must name
     * @param string|null $openCheckoutHash payment: digest of the open checkout mandate presented alongside
     * @param bool $singleUse whether to refuse mandates already used (a dry run in the studio still reports it)
     */
    public function __construct(
        public \Closure $rootKey,
        public MandateLedger $ledger,
        public string $audience,
        public \Closure|string|null $nonce = null,
        public ?EcKey $merchantKey = null,
        public ?string $checkoutJwt = null,
        public ?string $transactionId = null,
        public ?string $openCheckoutHash = null,
        public bool $singleUse = true,
        public ?int $now = null,
    ) {}

    public function now(): int
    {
        return $this->now ?? time();
    }
}
