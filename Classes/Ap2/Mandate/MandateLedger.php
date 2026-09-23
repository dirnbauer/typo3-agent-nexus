<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Ap2\Mandate;

/**
 * What a verifier remembers between presentations.
 *
 * The AP2 SDK verifies a chain in isolation; it never marks a mandate as used
 * and leaves the question "did I issue this checkout?" to the merchant. A
 * verifier that answers neither accepts replays, so the verifiers here ask
 * this ledger.
 */
interface MandateLedger
{
    /** The checkout JWT this merchant issued under a checkout hash, or null. */
    public function issuedCheckout(string $checkoutHash): ?string;

    /** Earlier purchases an open mandate (by its reference) authorised. */
    public function usage(string $openReference): MandateUsage;

    /** Whether a closed mandate (by its reference) was already accepted. */
    public function wasAccepted(string $closedReference): bool;
}
