<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Ap2\Mandate;

use Webconsulting\AgentNexus\Ap2\Crypto\CryptoException;
use Webconsulting\AgentNexus\Ap2\Crypto\DelegateChain;
use Webconsulting\AgentNexus\Ap2\Crypto\Digest;
use Webconsulting\AgentNexus\Ap2\Crypto\Json;
use Webconsulting\AgentNexus\Ap2\Crypto\Jws;

/**
 * The merchant's verification of a checkout mandate (AP2 specification,
 * "Verification: Merchant"):
 *
 *  - the delegate SD-JWT chain ({@see ChainVerifier}), audience "merchant";
 *  - mandate types, content and pre-set values;
 *  - the checkout JWT is the merchant's own — its signature, and a checkout
 *    it issued for this session — and `checkout_hash` is its hash;
 *  - every constraint of the open mandate holds for that checkout;
 *  - the mandate was not used before.
 */
final class CheckoutVerifier
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

        $typeCheck = MandateChecks::types($result->mandates, MandateType::Checkout);
        $verdict = $verdict->with($typeCheck);
        if (!$typeCheck->pass) {
            return $verdict;
        }
        $closed = $verdict->closed();
        $open = $delegated ? $verdict->open() : null;
        $verdict = $verdict->with(MandateChecks::content(MandateType::Checkout, $closed, $open));
        if ($open !== null) {
            $verdict = $verdict->with(MandateChecks::presets($open, $closed, ['checkout_jwt', 'checkout_hash']));
        }

        $checkoutJwt = Json::string($closed['checkout_jwt'] ?? null);
        if ($checkoutJwt === '') {
            return $verdict->with(Check::fail(CheckType::MerchantCheckout, 'The checkout JWT was not disclosed.'));
        }
        $verdict = $verdict->with($this->checkoutHash($checkoutJwt, Json::string($closed['checkout_hash'] ?? null), $chain));

        [$merchantCheck, $checkout] = $this->merchantCheckout($checkoutJwt, $context, $now);
        $verdict = $verdict->with($merchantCheck);
        if ($open !== null && $checkout !== null) {
            $verdict = $verdict->with(...$this->constraints->checkout($open, $checkout));
        }

        return $context->singleUse ? $verdict->with($this->singleUse($verdict, $context)) : $verdict;
    }

    private function checkoutHash(string $checkoutJwt, string $claimed, DelegateChain $chain): Check
    {
        try {
            $actual = Digest::of($checkoutJwt, $chain->leaf()->algorithm());
        } catch (CryptoException $e) {
            return Check::fail(CheckType::CheckoutHash, $e->getMessage());
        }
        return hash_equals($actual, $claimed)
            ? Check::pass(CheckType::CheckoutHash, substr($actual, 0, 12) . '…')
            : Check::fail(CheckType::CheckoutHash, 'checkout_hash is not the hash of the disclosed checkout JWT.');
    }

    /**
     * @return array{0: Check, 1: array<string, mixed>|null} the check and, when the signature holds, the checkout
     */
    private function merchantCheckout(string $checkoutJwt, VerificationContext $context, int $now): array
    {
        if ($context->merchantKey === null) {
            return [Check::fail(CheckType::MerchantCheckout, 'This verifier has no merchant key.'), null];
        }
        try {
            $checkout = Jws::verify($checkoutJwt, $context->merchantKey);
        } catch (CryptoException $e) {
            return [Check::fail(CheckType::MerchantCheckout, 'The checkout is not signed by this merchant: ' . $e->getMessage()), null];
        }

        $issued = $context->checkoutJwt ?? $context->ledger->issuedCheckout(Digest::of($checkoutJwt));
        if ($issued === null || !hash_equals($issued, $checkoutJwt)) {
            return [Check::fail(CheckType::MerchantCheckout, $context->checkoutJwt !== null
                ? 'The checkout is not the one of this session.'
                : 'This merchant did not issue this checkout.'), $checkout];
        }
        $exp = $checkout['exp'] ?? null;
        if ((is_int($exp) || is_float($exp)) && $now > $exp + ChainVerifier::CLOCK_SKEW) {
            return [Check::fail(CheckType::MerchantCheckout, 'The checkout expired on ' . gmdate('j M Y, H:i', (int)$exp) . ' UTC.'), $checkout];
        }

        $total = null;
        foreach (Json::objects($checkout['totals'] ?? null) as $line) {
            if (($line['type'] ?? null) === 'total') {
                $total = Json::int($line['amount'] ?? null);
            }
        }
        $detail = Json::string($checkout['id'] ?? null, 'checkout');
        if ($total !== null) {
            $detail .= ' · ' . Money::format($total, Json::string($checkout['currency'] ?? null, 'EUR'));
        }
        return [Check::pass(CheckType::MerchantCheckout, $detail . ' · signed with ' . $context->merchantKey->kid), $checkout];
    }

    private function singleUse(Verdict $verdict, VerificationContext $context): Check
    {
        if ($verdict->reference() !== '' && $context->ledger->wasAccepted($verdict->reference())) {
            return Check::fail(CheckType::SingleUse, 'This checkout mandate was already used.');
        }
        if ($verdict->openReference() !== '' && $context->ledger->usage($verdict->openReference())->uses > 0) {
            return Check::fail(CheckType::SingleUse, 'The open checkout mandate already authorised a purchase.');
        }
        return Check::pass(CheckType::SingleUse, 'First use');
    }
}
