<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Ap2\Sandbox;

use Webconsulting\AgentNexus\Ap2\Mandate\MandateLedger;
use Webconsulting\AgentNexus\Ap2\Mandate\MandateType;
use Webconsulting\AgentNexus\Ap2\Mandate\PaymentVerifier;
use Webconsulting\AgentNexus\Ap2\Mandate\Receipt;
use Webconsulting\AgentNexus\Ap2\Mandate\Verdict;
use Webconsulting\AgentNexus\Ap2\Mandate\VerificationContext;

/**
 * The merchant's payment processor: checks that the credential it was handed
 * is scoped to this checkout — the payment mandate inside it verifies and
 * names this checkout's hash and the open checkout mandate — then "settles"
 * and signs the payment receipt. Nothing is charged; there is no network.
 */
final readonly class PaymentProcessor
{
    public function __construct(
        private KeyRing $keys,
        private MandateLedger $ledger,
        private PaymentVerifier $verifier,
    ) {}

    /**
     * @return array{0: Verdict, 1: Receipt}
     */
    public function settle(PaymentCredential $credential, string $checkoutHash, ?string $openCheckoutHash, int $now): array
    {
        $verdict = $this->verifier->verify($credential->mandate, new VerificationContext(
            rootKey: $this->keys->trustedRoots(),
            ledger: $this->ledger,
            audience: MandateType::Payment->audience(),
            nonce: $credential->nonce,
            transactionId: $checkoutHash,
            openCheckoutHash: $openCheckoutHash,
            // The credential provider enforced single use when it released the credential.
            singleUse: false,
            now: $now,
        ));
        $receipt = Receipt::payment(
            $verdict,
            $credential->mandate->reference(),
            Parties::PAYMENT_PROCESSOR_ISSUER,
            'pay_' . bin2hex(random_bytes(8)),
            $this->keys->signer(Role::PaymentProcessor),
            $now,
        );
        return [$verdict, $receipt];
    }
}
