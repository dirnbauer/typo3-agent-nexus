<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Ap2\Mandate;

use Webconsulting\AgentNexus\Ap2\Crypto\DelegateChain;
use Webconsulting\AgentNexus\Ap2\Crypto\Json;

/**
 * The credential provider's and payment processor's verification of a
 * payment mandate (AP2 specification, "Verification: Credential Provider",
 * "Merchant Payment Processor"):
 *
 *  - the delegate SD-JWT chain ({@see ChainVerifier}), audience
 *    "credential-provider";
 *  - mandate types, content and pre-set values;
 *  - `transaction_id` is the hash of the checkout being paid for;
 *  - every constraint of the open mandate holds, `payment.reference` against
 *    the open checkout mandate presented with the purchase;
 *  - the mandate was not used before (the credential provider enforces this;
 *    the processor receives the mandate inside the credential it released).
 */
final class PaymentVerifier
{
    public function __construct(
        private readonly ChainVerifier $chains = new ChainVerifier(),
        private readonly ConstraintEvaluator $constraints = new ConstraintEvaluator(),
    ) {}

    public function verify(DelegateChain $chain, VerificationContext $context): Verdict
    {
        $now = $context->now();
        $delegated = $chain->count() > 1;
        $result = $this->chains->verify(
            $chain,
            $context->rootKey,
            $delegated ? $context->audience : null,
            $delegated ? $context->nonce : null,
            $now,
        );
        $verdict = new Verdict($result->checks, $chain, $result->mandates);
        if (!$result->readable) {
            return $verdict;
        }

        $typeCheck = MandateChecks::types($result->mandates, MandateType::Payment);
        $verdict = $verdict->with($typeCheck);
        if (!$typeCheck->pass) {
            return $verdict;
        }
        $closed = $verdict->closed();
        $open = $delegated ? $verdict->open() : null;
        $verdict = $verdict->with(MandateChecks::content(MandateType::Payment, $closed, $open));
        if ($open !== null) {
            $verdict = $verdict->with(MandateChecks::presets(
                $open,
                $closed,
                ['transaction_id', 'payee', 'payment_amount', 'payment_instrument', 'pisp', 'execution_date'],
            ));
        }

        $verdict = $verdict->with($this->transaction(Json::string($closed['transaction_id'] ?? null), $context));

        $usage = $verdict->openReference() !== '' ? $context->ledger->usage($verdict->openReference()) : new MandateUsage();
        if ($open !== null) {
            $verdict = $verdict->with(...$this->constraints->payment($open, $closed, $context->openCheckoutHash, $usage, $now));
        }

        return $context->singleUse ? $verdict->with($this->singleUse($verdict, $open, $usage, $context)) : $verdict;
    }

    private function transaction(string $transactionId, VerificationContext $context): Check
    {
        if ($transactionId === '') {
            return Check::fail(CheckType::Transaction, 'The payment mandate names no transaction.');
        }
        if ($context->transactionId !== null) {
            return hash_equals($context->transactionId, $transactionId)
                ? Check::pass(CheckType::Transaction, 'checkout_hash ' . substr($transactionId, 0, 12) . '…')
                : Check::fail(CheckType::Transaction, 'The payment is for a different checkout.');
        }
        return $context->ledger->issuedCheckout($transactionId) !== null
            ? Check::pass(CheckType::Transaction, 'A checkout the merchant issued (' . substr($transactionId, 0, 12) . '…)')
            : Check::fail(CheckType::Transaction, 'No checkout with this hash was issued.');
    }

    /**
     * @param array<string, mixed>|null $open
     */
    private function singleUse(Verdict $verdict, ?array $open, MandateUsage $usage, VerificationContext $context): Check
    {
        if ($verdict->reference() !== '' && $context->ledger->wasAccepted($verdict->reference())) {
            return Check::fail(CheckType::SingleUse, 'This payment mandate was already used.');
        }
        $recurring = $open !== null && array_any(
            Json::objects($open['constraints'] ?? null),
            static fn(array $constraint): bool => ($constraint['type'] ?? null) === ConstraintType::AgentRecurrence->value,
        );
        // With payment.agent_recurrence the open mandate may be reused; that
        // constraint's own check limits how often.
        if ($open !== null && !$recurring && $usage->uses > 0) {
            return Check::fail(CheckType::SingleUse, 'The open payment mandate already paid for a purchase.');
        }
        return Check::pass(CheckType::SingleUse, $recurring ? 'Use ' . ($usage->uses + 1) : 'First use');
    }
}
