<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Tests\Conformance\Ap2;

use PHPUnit\Framework\Attributes\Test;
use Webconsulting\AgentNexus\Ap2\Crypto\CryptoException;
use Webconsulting\AgentNexus\Ap2\Crypto\DelegateChain;
use Webconsulting\AgentNexus\Ap2\Crypto\Digest;
use Webconsulting\AgentNexus\Ap2\Crypto\EcKey;
use Webconsulting\AgentNexus\Ap2\Crypto\Json;
use Webconsulting\AgentNexus\Ap2\Crypto\Jws;
use Webconsulting\AgentNexus\Ap2\Crypto\SdJwt;
use Webconsulting\AgentNexus\Ap2\Mandate\CheckoutVerifier;
use Webconsulting\AgentNexus\Ap2\Mandate\CheckType;
use Webconsulting\AgentNexus\Ap2\Mandate\ConstraintEvaluator;
use Webconsulting\AgentNexus\Ap2\Mandate\MandateSchema;
use Webconsulting\AgentNexus\Ap2\Mandate\MandateType;
use Webconsulting\AgentNexus\Ap2\Mandate\PaymentVerifier;
use Webconsulting\AgentNexus\Ap2\Mandate\Verdict;
use Webconsulting\AgentNexus\Ap2\Mandate\VerificationContext;
use Webconsulting\AgentNexus\Ap2\Service\UcpMandateBridge;
use Webconsulting\AgentNexus\Tests\Unit\Ap2\Fixtures\InMemoryLedger;

/**
 * The encoded example tokens of the AP2 v0.2.0 specification (see
 * Fixtures/SOURCE.txt), read by this implementation.
 *
 * The issuer key of the open mandates and the merchant key of the checkout
 * are not published, so exactly those two signatures stay unverified. Every
 * other rule holds: the key binding under cnf.jwk, sd_hash, checkout_hash,
 * transaction_id, the payment reference, and the schemas.
 */
final class OfficialExamplesTest extends Ap2ConformanceTestCase
{
    #[Test]
    public function everyExampleParsesAndSerialisesByteForByte(): void
    {
        foreach (['open_checkout_mandate' => 'example+sd-jwt', 'closed_checkout_mandate' => 'kb+sd-jwt', 'open_payment_mandate' => 'example+sd-jwt', 'closed_payment_mandate' => 'kb+sd-jwt'] as $name => $typ) {
            $token = SdJwt::parse(self::example($name));
            self::assertSame(self::example($name), $token->serialize(), $name);
            self::assertSame($typ, $token->typ(), $name);
            self::assertSame('ES256', $token->header()['alg'] ?? null, $name);
        }
        foreach (['checkout', 'payment'] as $kind) {
            $chain = self::chain($kind);
            self::assertSame(self::example('open_plus_closed_' . $kind . '_mandate_chain'), $chain->serialize());
            self::assertSame(2, $chain->count());
            self::assertSame(self::example('open_' . $kind . '_mandate'), $chain->root()->serialize(), 'The chain starts with the open example.');
            self::assertSame(self::example('closed_' . $kind . '_mandate'), $chain->leaf()->serialize(), 'The chain ends with the closed example.');
        }
    }

    #[Test]
    public function theDisclosedMandatesConformToTheSchemas(): void
    {
        [$openCheckout, $checkoutMandate] = self::mandatesOf(self::chain('checkout'));
        [$openPayment, $paymentMandate] = self::mandatesOf(self::chain('payment'));

        foreach ([[MandateType::OpenCheckout, $openCheckout], [MandateType::Checkout, $checkoutMandate], [MandateType::OpenPayment, $openPayment], [MandateType::Payment, $paymentMandate]] as [$type, $mandate]) {
            self::assertAp2Conforms($type->schemaId(), $mandate, $type->value);
            self::assertSame([], MandateSchema::violations($type, $mandate), $type->value);
        }
        self::assertAp2Conforms(self::UCP_CHECKOUT, Jws::decode(Json::string($checkoutMandate['checkout_jwt'] ?? null))->payload());
    }

    #[Test]
    public function theAgentsKeyBindingVerifiesUnderTheOpenMandatesCnf(): void
    {
        foreach (['checkout', 'payment'] as $kind) {
            $chain = self::chain($kind);
            $agentKey = EcKey::fromJwk(Json::map(Json::map(self::mandateOf($chain->root())['cnf'] ?? null)['jwk'] ?? null));

            $payload = Jws::verify($chain->leaf()->issuerJwt(), $agentKey);
            self::assertSame($chain->root()->sdHash(), $payload['sd_hash'] ?? null, $kind . ': sd_hash is the digest of the open mandate as presented.');
            self::assertSame($kind === 'checkout' ? 'merchant' : 'credential-provider', $payload['aud'] ?? null);

            $impostor = EcKey::generate('impostor');
            try {
                Jws::verify($chain->leaf()->issuerJwt(), $impostor);
                self::fail('Another key must not verify the key binding.');
            } catch (CryptoException) {
                // expected
            }
        }
    }

    #[Test]
    public function theHashesBindCheckoutAndPaymentTogether(): void
    {
        $checkoutChain = self::chain('checkout');
        [, $checkoutMandate] = self::mandatesOf($checkoutChain);
        [$openPayment, $paymentMandate] = self::mandatesOf(self::chain('payment'));
        $checkoutJwt = Json::string($checkoutMandate['checkout_jwt'] ?? null);

        self::assertSame(Digest::of($checkoutJwt), $checkoutMandate['checkout_hash'] ?? null, 'checkout_hash is the digest of checkout_jwt.');
        self::assertSame(UcpMandateBridge::checkoutHash($checkoutJwt), $checkoutMandate['checkout_hash'] ?? null);
        self::assertSame($checkoutMandate['checkout_hash'] ?? null, $paymentMandate['transaction_id'] ?? null, 'transaction_id names the checkout.');

        $references = array_values(array_filter(
            Json::objects($openPayment['constraints'] ?? null),
            static fn(array $constraint): bool => ($constraint['type'] ?? null) === 'payment.reference',
        ));
        self::assertCount(1, $references);
        self::assertSame($checkoutChain->root()->sdHash(), $references[0]['conditional_transaction_id'] ?? null, 'The payment is tied to the open checkout mandate as presented.');
    }

    #[Test]
    public function theCheckoutSatisfiesTheOpenCheckoutMandatesConstraints(): void
    {
        [$openCheckout, $checkoutMandate] = self::mandatesOf(self::chain('checkout'));
        $checkout = Jws::decode(Json::string($checkoutMandate['checkout_jwt'] ?? null))->payload();

        $checks = (new ConstraintEvaluator())->checkout($openCheckout, $checkout);

        self::assertSame([CheckType::LineItems, CheckType::AllowedMerchant], array_map(static fn($check): CheckType => $check->type, $checks));
        foreach ($checks as $check) {
            self::assertTrue($check->pass, $check->type->value . ': ' . $check->detail);
        }
    }

    #[Test]
    public function ourCheckoutVerifierFailsOnlyOnTheUnpublishedKeys(): void
    {
        $chain = self::chain('checkout');

        $verdict = (new CheckoutVerifier())->verify($chain, self::context($chain, 'merchant'));

        self::assertSame([CheckType::RootSignature, CheckType::MerchantCheckout], self::failures($verdict));
        self::assertFalse($verdict->valid(), 'An unknown issuer is never trusted.');
    }

    #[Test]
    public function ourPaymentVerifierFailsOnlyOnTheUnpublishedIssuerKey(): void
    {
        $checkoutChain = self::chain('checkout');
        [, $checkoutMandate] = self::mandatesOf($checkoutChain);
        $chain = self::chain('payment');

        $verdict = (new PaymentVerifier())->verify($chain, self::context(
            $chain,
            'credential-provider',
            Json::string($checkoutMandate['checkout_hash'] ?? null),
            $checkoutChain->root()->sdHash(),
        ));

        self::assertSame([CheckType::RootSignature], self::failures($verdict));
        foreach ([CheckType::AgentSignature, CheckType::Binding, CheckType::Audience, CheckType::Transaction, CheckType::SpendingCap, CheckType::AllowedPayee, CheckType::PaymentReference] as $type) {
            self::assertFalse($verdict->failed($type), $type->value);
        }
    }

    #[Test]
    public function aClosedMandateMovedToAnotherOpenMandateIsRefused(): void
    {
        $spliced = new DelegateChain([self::chain('payment')->root(), self::chain('checkout')->leaf()]);

        $verdict = (new CheckoutVerifier())->verify($spliced, self::context($spliced, 'merchant'));

        self::assertFalse($verdict->failed(CheckType::AgentSignature), 'Both examples use one agent key, so the signature itself still verifies.');
        self::assertTrue($verdict->failed(CheckType::Binding), 'But sd_hash names the other open mandate.');
        self::assertFalse($verdict->valid());
    }

    #[Test]
    public function aReplayWithAnotherNonceIsRefused(): void
    {
        $chain = self::chain('checkout');
        $context = self::context($chain, 'merchant');

        $verdict = (new CheckoutVerifier())->verify($chain, new VerificationContext(
            $context->rootKey,
            $context->ledger,
            $context->audience,
            'a-challenge-this-merchant-issued',
            singleUse: false,
            now: $context->now,
        ));

        self::assertTrue($verdict->failed(CheckType::Audience));
    }

    private static function example(string $name): string
    {
        $contents = file_get_contents(__DIR__ . '/Fixtures/' . $name . '.sdjwt.txt');
        self::assertIsString($contents, $name);
        return rtrim($contents, "\n");
    }

    private static function chain(string $kind): DelegateChain
    {
        return DelegateChain::parse(self::example('open_plus_closed_' . $kind . '_mandate_chain'));
    }

    /**
     * A verifier that trusts no issuer (the example key is not published),
     * issued the challenge in the example and checks at the time it was made.
     */
    private static function context(DelegateChain $chain, string $audience, ?string $transactionId = null, ?string $openCheckoutHash = null): VerificationContext
    {
        $binding = $chain->leaf()->payload();
        return new VerificationContext(
            static fn(): ?EcKey => null,
            new InMemoryLedger(),
            $audience,
            Json::string($binding['nonce'] ?? null),
            transactionId: $transactionId,
            openCheckoutHash: $openCheckoutHash,
            singleUse: false,
            now: (Json::int($binding['iat'] ?? null) ?? 0) + 10,
        );
    }

    /**
     * @return list<CheckType>
     */
    private static function failures(Verdict $verdict): array
    {
        $failed = [];
        foreach ($verdict->toArray()['checks'] as $check) {
            if (!$check['pass']) {
                $failed[] = CheckType::from($check['id']);
            }
        }
        return $failed;
    }
}
