<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Ap2\Sandbox;

use Webconsulting\AgentNexus\Ap2\Crypto\DelegateChain;
use Webconsulting\AgentNexus\Ap2\Mandate\MandateLedger;
use Webconsulting\AgentNexus\Ap2\Mandate\MandateType;
use Webconsulting\AgentNexus\Ap2\Mandate\PaymentVerifier;
use Webconsulting\AgentNexus\Ap2\Mandate\Receipt;
use Webconsulting\AgentNexus\Ap2\Mandate\Verdict;
use Webconsulting\AgentNexus\Ap2\Mandate\VerificationContext;

/**
 * The credential provider: holds the (sandbox) payment instrument, verifies
 * payment mandates, and releases a payment credential only for one that
 * verified. When it refuses, it answers with a signed payment receipt that
 * carries the error, as AP2 requires.
 */
final readonly class CredentialProvider
{
    public function __construct(
        private KeyRing $keys,
        private Challenges $challenges,
        private MandateLedger $ledger,
        private PaymentVerifier $verifier,
    ) {}

    /**
     * @return list<array{id: string, type: string, description: string}>
     */
    public function instruments(): array
    {
        return [Parties::INSTRUMENT];
    }

    public function challenge(): string
    {
        return $this->challenges->issue(MandateType::Payment->audience());
    }

    /**
     * @param string|null $nonce the challenge it issued for this payment; null accepts any it issued
     * @param string|null $transactionId the checkout hash being paid for; null accepts any checkout the merchant issued
     * @param string|null $openCheckoutHash digest of the open checkout mandate the agent presented with the purchase
     */
    public function verify(DelegateChain $chain, ?string $nonce, ?string $transactionId, ?string $openCheckoutHash, bool $singleUse = true, ?int $now = null): Verdict
    {
        $audience = MandateType::Payment->audience();
        return $this->verifier->verify($chain, new VerificationContext(
            rootKey: $this->keys->trustedRoots(),
            ledger: $this->ledger,
            audience: $audience,
            nonce: $nonce ?? fn(string $candidate): bool => $this->challenges->issued($audience, $candidate),
            transactionId: $transactionId,
            openCheckoutHash: $openCheckoutHash,
            singleUse: $singleUse,
            now: $now,
        ));
    }

    public function release(DelegateChain $mandate, string $nonce): PaymentCredential
    {
        return new PaymentCredential('sandbox_token_' . bin2hex(random_bytes(8)), $mandate, $nonce);
    }

    public function refusal(Verdict $verdict, string $reference, int $now): Receipt
    {
        return Receipt::payment(
            $verdict,
            $reference,
            Parties::CREDENTIAL_PROVIDER_ISSUER,
            'pay_' . bin2hex(random_bytes(8)),
            $this->keys->signer(Role::CredentialProvider),
            $now,
        );
    }
}
