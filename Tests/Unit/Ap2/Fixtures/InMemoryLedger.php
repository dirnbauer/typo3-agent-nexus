<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Tests\Unit\Ap2\Fixtures;

use Webconsulting\AgentNexus\Ap2\Crypto\Digest;
use Webconsulting\AgentNexus\Ap2\Mandate\MandateLedger;
use Webconsulting\AgentNexus\Ap2\Mandate\MandateUsage;

/**
 * A verifier memory the tests fill by hand.
 */
final class InMemoryLedger implements MandateLedger
{
    /** @var array<string, string> checkout hash => checkout JWT */
    public array $checkouts = [];

    /** @var array<string, MandateUsage> */
    public array $usage = [];

    /** @var array<string, true> */
    public array $accepted = [];

    public function issue(string $checkoutJwt): void
    {
        $this->checkouts[Digest::of($checkoutJwt)] = $checkoutJwt;
    }

    public function issuedCheckout(string $checkoutHash): ?string
    {
        return $this->checkouts[$checkoutHash] ?? null;
    }

    public function usage(string $openReference): MandateUsage
    {
        return $this->usage[$openReference] ?? new MandateUsage();
    }

    public function wasAccepted(string $closedReference): bool
    {
        return isset($this->accepted[$closedReference]);
    }
}
