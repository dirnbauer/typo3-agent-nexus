<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Ap2\Sandbox;

use Webconsulting\AgentNexus\Ap2\Crypto\DelegateChain;
use Webconsulting\AgentNexus\Ap2\Mandate\ErrorCode;
use Webconsulting\AgentNexus\Ap2\Mandate\MandateContent;
use Webconsulting\AgentNexus\Ap2\Mandate\MandateType;
use Webconsulting\AgentNexus\Ap2\Mandate\Money;
use Webconsulting\AgentNexus\Ap2\Mandate\Receipt;
use Webconsulting\AgentNexus\Ap2\Mandate\Verdict;
use Webconsulting\AgentNexus\Shared\Protocol;
use Webconsulting\AgentNexus\Shared\Traffic\TrafficRecorder;

/**
 * AP2's "human not present" flow, every role in this process, nothing charged.
 *
 *  1a. The person approves a shopping list and a spending cap; the Trusted
 *      Surface signs an open checkout mandate and an open payment mandate,
 *      both naming the agent's key, the payment one bound to the checkout one.
 *  1b. The agent shops alone: it picks items, the merchant signs the checkout.
 *  2.  The agent closes both mandates with its own key.
 *  3.  The credential provider verifies the payment mandate and releases a
 *      credential — or refuses with a signed payment receipt.
 *  4.  The merchant verifies the checkout mandate and answers with a checkout
 *      receipt; the payment processor verifies the credential's mandate,
 *      "settles" and signs the payment receipt.
 *
 * A purchase over the cap fails step 3 with `unresolved_constraint`: AP2's
 * signal to fall back to a human-present approval of the exact order.
 */
final readonly class AutonomousFlow
{
    public const string WITHIN = 'within';
    public const string OVER = 'over';

    /** AP2 recommends the shortest lifetime that lets the agent finish. */
    public const int MANDATE_LIFETIME = 900;

    public function __construct(
        private TrustedSurface $trustedSurface,
        private ShoppingAgent $agent,
        private Merchant $merchant,
        private CredentialProvider $credentialProvider,
        private PaymentProcessor $processor,
        private DemoCatalogue $catalogue,
        private MandateRecorder $recorder,
        private TrafficRecorder $traffic,
    ) {}

    /**
     * @param int $cap the spending cap in cents
     * @param string $scenario {@see self::WITHIN} or {@see self::OVER}
     * @param string $merchantId the merchant the person allows ({@see Parties})
     * @return array<string, mixed> every artefact, check and receipt, ready for JSON
     */
    public function run(int $cap, string $scenario, string $merchantId, RecordingContext $context): array
    {
        $now = time();
        $expires = $now + self::MANDATE_LIFETIME;
        $currency = Parties::CURRENCY;
        $allowed = Parties::merchant($merchantId) ?? Parties::MERCHANT;
        $list = $this->catalogue->shoppingList();
        if ($list === []) {
            throw new \RuntimeException('The demo catalogue has no products to shop for.', 1758700401);
        }

        // 1a. The Trusted Surface signs the open mandates the person approved.
        $lines = array_map(static fn(array $line): array => [
            'id' => $line['id'],
            'acceptable' => array_map(static fn(array $option): array => ['id' => $option['id'], 'title' => $option['title']], $line['options']),
            'quantity' => $line['quantity'],
        ], $list);
        $openCheckout = $this->trustedSurface->sign(
            MandateContent::openCheckout($lines, [$allowed], $this->agent->publicJwk(), $now, $expires),
        );
        $chainId = $openCheckout->reference();
        $openPayment = $this->trustedSurface->sign(MandateContent::openPayment(
            $cap,
            $currency,
            [$allowed],
            $this->credentialProvider->instruments(),
            $openCheckout->root()->sdHash(),
            $this->agent->publicJwk(),
            $now,
            $expires,
        ));
        $artefacts = [
            'openCheckoutMandate' => Artefact::mandate($openCheckout, Role::TrustedSurface, sprintf(
                '%s · %d %s · %s',
                MandateType::OpenCheckout->value,
                count($lines),
                count($lines) === 1 ? 'line' : 'lines',
                $allowed['name'],
            )),
            'openPaymentMandate' => Artefact::mandate($openPayment, Role::TrustedSurface, MandateType::OpenPayment->value . ' · up to ' . Money::format($cap, $currency)),
        ];

        // 1b. The agent shops on its own; the merchant signs the checkout.
        $cart = $this->agent->chooseCart($list, $cap, $scenario === self::OVER);
        $checkout = $this->merchant->checkout($cart['lines'], $now, $currency);
        $itemCount = array_sum(array_column($cart['lines'], 'quantity'));
        $artefacts['checkoutJwt'] = Artefact::jwt(Artefact::CHECKOUT_JWT, $checkout->jwt, Role::Merchant, sprintf(
            'checkout_jwt · %s · %d %s',
            Money::format($checkout->total, $currency),
            $itemCount,
            $itemCount === 1 ? 'item' : 'items',
        ), $checkout->hash);
        $this->traffic->recordInProcess(Protocol::Ap2, 'POST', 'in-process://merchant/checkout', 'CreateCheckout', ['line_items' => $cart['lines']], 200, ['ap2.checkout' => ['checkout_jwt' => $checkout->jwt, 'checkout_hash' => $checkout->hash]], $checkout->hash);

        // 2. The agent closes both mandates, answering each verifier's challenge.
        $merchantNonce = $this->merchant->challenge();
        $providerNonce = $this->credentialProvider->challenge();
        $closedCheckout = $this->agent->close(
            $openCheckout,
            MandateContent::closedCheckout($checkout->jwt, $checkout->hash, $now, $expires),
            MandateType::Checkout->audience(),
            $merchantNonce,
            $now,
        );
        $closedPayment = $this->agent->close(
            $openPayment,
            MandateContent::closedPayment($checkout->hash, Parties::MERCHANT, $checkout->total, $currency, Parties::INSTRUMENT, $now, $expires),
            MandateType::Payment->audience(),
            $providerNonce,
            $now,
        );
        $artefacts['closedCheckoutMandate'] = Artefact::mandate($closedCheckout, Role::ShoppingAgent, MandateType::Checkout->value . ' · ' . Money::format($checkout->total, $currency));
        $artefacts['closedPaymentMandate'] = Artefact::mandate($closedPayment, Role::ShoppingAgent, MandateType::Payment->value . ' · ' . Money::format($checkout->total, $currency));
        foreach ($artefacts as $artefact) {
            $this->recorder->record($artefact, $chainId, $context);
        }

        // 3. The credential provider verifies the payment mandate.
        $openCheckoutHash = $closedCheckout->root()->sdHash();
        $providerVerdict = $this->credentialProvider->verify($closedPayment, $providerNonce, $checkout->hash, $openCheckoutHash, true, $now);
        $this->recorder->decide($closedPayment->reference(), $providerVerdict, 'credential provider');
        $verifications = ['credentialProvider' => $providerVerdict, 'merchant' => null, 'paymentProcessor' => null];
        $receipts = ['checkout' => null, 'payment' => null];

        if (!$providerVerdict->valid()) {
            $receipts['payment'] = $this->receipt($this->credentialProvider->refusal($providerVerdict, $closedPayment->reference(), $now), Role::CredentialProvider, $chainId, $context);
            $this->exchange('in-process://credential-provider/payment-mandate', 'PresentPaymentMandate', $closedPayment, $providerVerdict, $receipts['payment'], 422);
        } else {
            $credential = $this->credentialProvider->release($closedPayment, $providerNonce);
            $this->recorder->consume($openPayment->root(), $chainId, $checkout->total, $now, $context);
            $this->exchange('in-process://credential-provider/payment-mandate', 'PresentPaymentMandate', $closedPayment, $providerVerdict, null, 200, ['payment_token' => $credential->token]);

            // 4. The merchant verifies the checkout mandate, the processor settles.
            $merchantVerdict = $this->merchant->verify($closedCheckout, $merchantNonce, $checkout->jwt, true, $now);
            $verifications['merchant'] = $merchantVerdict;
            $this->recorder->decide($closedCheckout->reference(), $merchantVerdict, 'merchant');
            if ($merchantVerdict->valid()) {
                $this->recorder->consume($openCheckout->root(), $chainId, $checkout->total, $now, $context);
            }
            $receipts['checkout'] = $this->receipt($this->merchant->receipt($merchantVerdict, $closedCheckout->reference(), $now), Role::Merchant, $chainId, $context);
            $this->exchange('in-process://merchant/checkout-mandate', 'PresentCheckoutMandate', $closedCheckout, $merchantVerdict, $receipts['checkout'], $merchantVerdict->valid() ? 200 : 422, ['payment_token' => $credential->token]);

            if ($merchantVerdict->valid()) {
                [$processorVerdict, $paymentReceipt] = $this->processor->settle($credential, $checkout->hash, $openCheckoutHash, $now);
                $verifications['paymentProcessor'] = $processorVerdict;
                $receipts['payment'] = $this->receipt($paymentReceipt, Role::PaymentProcessor, $chainId, $context);
                $this->exchange('in-process://payment-processor/settle', 'SettlePayment', $closedPayment, $processorVerdict, $receipts['payment'], $processorVerdict->valid() ? 200 : 422, ['payment_token' => $credential->token]);
            }
        }

        return $this->result($chainId, $scenario, $cap, $currency, $list, $cart, $checkout, $artefacts, $verifications, $receipts);
    }

    private function receipt(Receipt $receipt, Role $role, string $chainId, RecordingContext $context): Artefact
    {
        $status = $receipt->successful() ? 'Success' : 'Error · ' . (string)($receipt->claims['error'] ?? '');
        $artefact = Artefact::jwt($receipt->type, $receipt->token, $role, $receipt->type . ' · ' . $status);
        $this->recorder->record(
            $artefact,
            $chainId,
            $context,
            $receipt->successful() ? MandateRecorder::STATE_VERIFIED : MandateRecorder::STATE_REJECTED,
            'Signed by the ' . strtolower($role->label()),
        );
        return $artefact;
    }

    /**
     * An exchange between two roles that never crossed the network, for the
     * traffic log.
     *
     * @param array<string, mixed> $extra
     */
    private function exchange(string $endpoint, string $operation, DelegateChain $mandate, Verdict $verdict, ?Artefact $receipt, int $status, array $extra = []): void
    {
        // The DataPart keys the AP2 samples use when mandates travel over A2A.
        $key = str_contains($operation, 'CheckoutMandate') ? 'ap2.mandates.CheckoutMandateSdJwt' : 'ap2.mandates.PaymentMandateSdJwt';
        $response = ['verification' => $verdict->toArray()] + $extra;
        if ($receipt !== null) {
            $response[$receipt->kind === Receipt::CHECKOUT ? 'ap2.CheckoutReceipt' : 'ap2.PaymentReceipt'] = $receipt->token;
        }
        $this->traffic->recordInProcess(Protocol::Ap2, 'POST', $endpoint, $operation, [$key => $mandate->serialize()], $status, $response, $mandate->reference());
    }

    /**
     * @param list<array{id: string, title: string, quantity: int, options: list<array{id: string, title: string, price: int}>}> $list
     * @param array{lines: list<array{id: string, title: string, price: int, quantity: int}>, total: int, withinCap: bool} $cart
     * @param array<string, Artefact> $artefacts
     * @param array{credentialProvider: Verdict, merchant: ?Verdict, paymentProcessor: ?Verdict} $verifications
     * @param array{checkout: ?Artefact, payment: ?Artefact} $receipts
     * @return array<string, mixed>
     */
    private function result(
        string $chainId,
        string $scenario,
        int $cap,
        string $currency,
        array $list,
        array $cart,
        SignedCheckout $checkout,
        array $artefacts,
        array $verifications,
        array $receipts,
    ): array {
        $verifiers = ['credentialProvider' => 'credential provider', 'merchant' => 'merchant', 'paymentProcessor' => 'payment processor'];
        $refusedBy = array_find_key($verifiers, static fn(string $name, string $key): bool => $verifications[$key] !== null && !$verifications[$key]->valid());
        $refusal = $refusedBy === null ? null : $verifications[$refusedBy];
        $authorised = $refusal === null && $verifications['paymentProcessor']?->valid() === true;
        $error = $refusal?->error();
        $total = Money::format($checkout->total, $currency);
        $capText = Money::format($cap, $currency);
        $items = array_sum(array_column($cart['lines'], 'quantity'));

        $summary = match (true) {
            $authorised => sprintf('The agent bought %d %s for %s, within your cap of %s. Nothing was charged.', $items, $items === 1 ? 'item' : 'items', $total, $capText),
            $error === ErrorCode::UnresolvedConstraint && !$cart['withinCap'] => sprintf('The agent chose items for %s, but your cap is %s. The credential provider refused the payment mandate, so the agent cannot finish this purchase on its own.', $total, $capText),
            default => sprintf(
                'The %s refused a mandate, so no payment would be made. %s',
                $refusedBy === null ? 'verifier' : $verifiers[$refusedBy],
                $refusal?->errorDescription() ?? '',
            ),
        };
        $note = match (true) {
            $scenario === self::OVER && $cart['withinCap'] => sprintf('Even the most expensive choice (%s) stays within your cap, so the purchase went through. Lower the cap to see a refusal.', $total),
            $scenario === self::WITHIN && !$cart['withinCap'] => sprintf('Nothing on the shopping list fits under your cap of %s.', $capText),
            default => null,
        };

        $steps = [
            ['id' => 'approve', 'role' => Role::TrustedSurface->value, 'title' => 'The Trusted Surface signs your open mandates', 'status' => 'done', 'artefacts' => ['openCheckoutMandate', 'openPaymentMandate']],
            ['id' => 'checkout', 'role' => Role::Merchant->value, 'title' => 'The merchant signs the checkout', 'status' => 'done', 'artefacts' => ['checkoutJwt']],
            ['id' => 'close', 'role' => Role::ShoppingAgent->value, 'title' => 'The agent closes both mandates with its own key', 'status' => 'done', 'artefacts' => ['closedCheckoutMandate', 'closedPaymentMandate']],
            ['id' => 'credentialProvider', 'role' => Role::CredentialProvider->value, 'title' => 'The credential provider checks the payment mandate', 'status' => self::status($verifications['credentialProvider']), 'verification' => 'credentialProvider'],
            ['id' => 'merchant', 'role' => Role::Merchant->value, 'title' => 'The merchant checks the checkout mandate', 'status' => self::status($verifications['merchant']), 'verification' => 'merchant'],
            ['id' => 'paymentProcessor', 'role' => Role::PaymentProcessor->value, 'title' => 'The payment processor settles the payment (simulated)', 'status' => self::status($verifications['paymentProcessor']), 'verification' => 'paymentProcessor'],
        ];

        return [
            'simulated' => true,
            'chainId' => $chainId,
            'scenario' => $scenario,
            'authorised' => $authorised,
            'outcome' => $authorised ? 'authorised' : 'refused',
            'error' => $error?->value,
            'summary' => $summary,
            'note' => $note,
            'fallback' => $error === ErrorCode::UnresolvedConstraint ? [
                'mode' => 'human_present',
                'error' => $error->value,
                'message' => 'The agent would now show you this exact order on the Trusted Surface. If you approve it there, the Trusted Surface signs closed mandates for it directly (human present).',
            ] : null,
            'cap' => ['amount' => $cap, 'currency' => $currency],
            'shoppingList' => $list,
            'cart' => ['lines' => $cart['lines'], 'total' => $checkout->total, 'currency' => $currency, 'withinCap' => $cart['withinCap']],
            'steps' => $steps,
            'artefacts' => array_map(static fn(Artefact $artefact): array => $artefact->toArray(), $artefacts),
            'verifications' => array_map(static fn(?Verdict $verdict): ?array => $verdict?->toArray(), $verifications),
            'receipts' => array_map(static fn(?Artefact $receipt): ?array => $receipt?->toArray(), $receipts),
        ];
    }

    private static function status(?Verdict $verdict): string
    {
        return match (true) {
            $verdict === null => 'skipped',
            $verdict->valid() => 'passed',
            default => 'failed',
        };
    }
}
