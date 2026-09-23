<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Ap2\Sandbox;

/**
 * Nonces a verifier hands to the agent before it closes a mandate. The
 * closing token must carry one of them, so a closed mandate made for one
 * verifier, or long ago, is refused.
 */
interface Challenges
{
    /** A fresh nonce for this audience ("merchant", "credential-provider"). */
    public function issue(string $audience): string;

    /** Whether this audience issued the nonce within the last hour. */
    public function issued(string $audience, string $nonce): bool;
}
