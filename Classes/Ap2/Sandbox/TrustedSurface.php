<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Ap2\Sandbox;

use Webconsulting\AgentNexus\Ap2\Crypto\DelegateChain;
use Webconsulting\AgentNexus\Ap2\Crypto\Disclosable;
use Webconsulting\AgentNexus\Ap2\Crypto\SdJwtIssuer;

/**
 * The Trusted Surface: shows mandate content to the person and, once they
 * approve, signs it — deterministic code, never a model, as AP2 requires.
 *
 * It signs in the "Trusted Agent Provider" model: a root SD-JWT with the
 * mandate as the one element of `delegate_payload`, itself selectively
 * disclosable, signed with the provider's key. The root `typ` is
 * implementation-defined in AP2 v0.2; this uses `dc+sd-jwt`.
 */
final readonly class TrustedSurface
{
    public const string TYP = 'dc+sd-jwt';

    public function __construct(
        private KeyRing $keys,
    ) {}

    /**
     * @param array<string, mixed> $content mandate content, see {@see \Webconsulting\AgentNexus\Ap2\Mandate\MandateContent}
     */
    public function sign(array $content): DelegateChain
    {
        $key = $this->keys->signer(Role::TrustedSurface);
        return DelegateChain::of(SdJwtIssuer::issue(
            ['delegate_payload' => [new Disclosable($content)]],
            ['typ' => self::TYP, 'kid' => $key->kid],
            $key,
        ));
    }
}
