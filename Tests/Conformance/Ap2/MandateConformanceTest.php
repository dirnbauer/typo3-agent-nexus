<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Tests\Conformance\Ap2;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Webconsulting\AgentNexus\Ap2\Crypto\Json;
use Webconsulting\AgentNexus\Ap2\Crypto\Jws;
use Webconsulting\AgentNexus\Ap2\Mandate\Check;
use Webconsulting\AgentNexus\Ap2\Mandate\CheckType;
use Webconsulting\AgentNexus\Ap2\Mandate\ConstraintType;
use Webconsulting\AgentNexus\Ap2\Mandate\MandateContent;
use Webconsulting\AgentNexus\Ap2\Mandate\MandateSchema;
use Webconsulting\AgentNexus\Ap2\Mandate\MandateType;
use Webconsulting\AgentNexus\Ap2\Mandate\Receipt;
use Webconsulting\AgentNexus\Ap2\Mandate\Verdict;
use Webconsulting\AgentNexus\Ap2\Sandbox\Parties;
use Webconsulting\AgentNexus\Ap2\Sandbox\Role;
use Webconsulting\AgentNexus\Tests\Unit\Ap2\Fixtures\Sandbox;

/**
 * Everything the sandbox signs, taken out of its token again and validated
 * against the AP2 v0.2.0 schemas: the four mandates, the merchant's checkout
 * and the receipts. The PHP catalogue the verifiers use must agree on each.
 */
final class MandateConformanceTest extends Ap2ConformanceTestCase
{
    private const string REMOVE = "\0remove";

    private Sandbox $sandbox;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sandbox = new Sandbox();
    }

    #[Test]
    public function theOpenCheckoutMandateTheTrustedSurfaceSignsConforms(): void
    {
        $mandate = self::mandateOf($this->sandbox->openCheckout()->root());

        self::assertAp2Conforms(self::OPEN_CHECKOUT, $mandate);
        self::assertSame([], MandateSchema::violations(MandateType::OpenCheckout, $mandate));
        self::assertAp2Conforms(self::JWK, Json::map($mandate['cnf'] ?? null)['jwk'] ?? null, 'cnf.jwk is a public JWK.');
    }

    #[Test]
    public function theOpenPaymentMandateTheTrustedSurfaceSignsConforms(): void
    {
        $mandate = self::mandateOf($this->sandbox->openPayment($this->sandbox->openCheckout())->root());

        self::assertAp2Conforms(self::OPEN_PAYMENT, $mandate);
        self::assertSame([], MandateSchema::violations(MandateType::OpenPayment, $mandate));
    }

    #[Test]
    public function theClosedMandatesTheAgentSignsConform(): void
    {
        $openCheckout = $this->sandbox->openCheckout();
        $checkout = $this->sandbox->checkout();

        $closedCheckout = self::mandatesOf($this->sandbox->closeCheckout($openCheckout, $checkout)['chain']);
        $closedPayment = self::mandatesOf($this->sandbox->closePayment($this->sandbox->openPayment($openCheckout), $checkout)['chain']);

        self::assertAp2Conforms(self::OPEN_CHECKOUT, $closedCheckout[0]);
        self::assertAp2Conforms(self::CHECKOUT, $closedCheckout[1]);
        self::assertSame([], MandateSchema::violations(MandateType::Checkout, $closedCheckout[1]));
        self::assertAp2Conforms(self::OPEN_PAYMENT, $closedPayment[0]);
        self::assertAp2Conforms(self::PAYMENT, $closedPayment[1]);
        self::assertSame([], MandateSchema::violations(MandateType::Payment, $closedPayment[1]));
    }

    #[Test]
    public function theMerchantsCheckoutJwtCarriesAUcpCheckout(): void
    {
        $checkout = $this->sandbox->checkout();
        $decoded = Jws::decode($checkout->jwt);

        self::assertSame(['alg' => 'ES256', 'typ' => 'JWT', 'kid' => $this->sandbox->keys->signer(Role::Merchant)->kid], $decoded->header);
        self::assertAp2Conforms(self::UCP_CHECKOUT, $decoded->payload());
        self::assertSame(Parties::MERCHANT, $decoded->payload()['merchant'] ?? null, 'The merchant names itself, so allowed_merchants can match.');
    }

    /**
     * Every constraint type AP2 v0.2 defines, in one open payment mandate.
     */
    #[Test]
    public function anOpenPaymentMandateWithEveryConstraintConforms(): void
    {
        $mandate = self::everyPaymentConstraint();

        self::assertSame(
            array_map(static fn(ConstraintType $type): string => $type->value, ConstraintType::forMandate(MandateType::OpenPayment)),
            array_column(Json::objects($mandate['constraints'] ?? null), 'type'),
        );
        self::assertAp2Conforms(self::OPEN_PAYMENT, $mandate);
        self::assertSame([], MandateSchema::violations(MandateType::OpenPayment, $mandate));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function receipts(): iterable
    {
        yield 'checkout, success' => [Receipt::CHECKOUT, true];
        yield 'checkout, error' => [Receipt::CHECKOUT, false];
        yield 'payment, success' => [Receipt::PAYMENT, true];
        yield 'payment, error' => [Receipt::PAYMENT, false];
    }

    #[Test]
    #[DataProvider('receipts')]
    public function theReceiptsConform(string $type, bool $success): void
    {
        $verdict = new Verdict($success
            ? [Check::pass(CheckType::SpendingCap, '€447.00 ≤ €500.00')]
            : [Check::fail(CheckType::SpendingCap, '€547.00 > €500.00')], null, []);
        $key = $this->sandbox->keys->signer($type === Receipt::CHECKOUT ? Role::Merchant : Role::CredentialProvider);
        $receipt = $type === Receipt::CHECKOUT
            ? Receipt::checkout($verdict, 'reference', 'urn:example:merchant', 'order-1', $key, 1790000000)
            : Receipt::payment($verdict, 'reference', 'urn:example:credential-provider', 'pay-1', $key, 1790000000);

        $claims = Jws::verify($receipt->token, $key);
        self::assertSame($receipt->toArray()['claims'], $claims);
        self::assertAp2Conforms(MandateSchema::RECEIPT_SCHEMAS[$type], $claims);
        self::assertSame([], MandateSchema::receiptViolations($type, $claims));
        self::assertSame($success, $receipt->successful());
    }

    /**
     * A path into the mandate and what to put there; `#<type>` picks the
     * constraint of that type.
     *
     * @return iterable<string, array{MandateType, list<string|int>, mixed}>
     */
    public static function brokenMandates(): iterable
    {
        yield 'open checkout without a line_items constraint' => [MandateType::OpenCheckout, ['constraints', '#checkout.line_items'], self::REMOVE];
        yield 'open checkout without cnf' => [MandateType::OpenCheckout, ['cnf'], self::REMOVE];
        yield 'open checkout with a quantity as a word' => [MandateType::OpenCheckout, ['constraints', '#checkout.line_items', 'items', 0, 'quantity'], 'one'];
        yield 'open checkout with an item without a title' => [MandateType::OpenCheckout, ['constraints', '#checkout.line_items', 'items', 0, 'acceptable_items', 0, 'title'], self::REMOVE];
        yield 'open payment without a reference constraint' => [MandateType::OpenPayment, ['constraints', '#payment.reference'], self::REMOVE];
        yield 'open payment with the cap as a string' => [MandateType::OpenPayment, ['constraints', '#payment.amount_range', 'max'], '500.00'];
        yield 'open payment with a payee without a name' => [MandateType::OpenPayment, ['constraints', '#payment.allowed_payees', 'allowed', 0, 'name'], self::REMOVE];
        yield 'closed checkout without checkout_hash' => [MandateType::Checkout, ['checkout_hash'], self::REMOVE];
        yield 'closed payment without a payee' => [MandateType::Payment, ['payee'], self::REMOVE];
        yield 'closed payment with the amount as a string' => [MandateType::Payment, ['payment_amount', 'amount'], '447.00'];
        yield 'closed payment with the open vct' => [MandateType::Payment, ['vct'], MandateType::OpenPayment->value];
    }

    /**
     * @param list<string|int> $path
     */
    #[Test]
    #[DataProvider('brokenMandates')]
    public function aBrokenMandateFailsBothTheSchemaAndTheCatalogue(MandateType $type, array $path, mixed $value): void
    {
        $mandate = Json::map(self::alter($this->valid($type), $path, $value));

        self::assertAp2Violates($type->schemaId(), $mandate);
        self::assertNotSame([], MandateSchema::violations($type, $mandate), 'The verifiers must refuse it too.');
    }

    #[Test]
    public function anErrorReceiptWithoutADescriptionFailsBothTheSchemaAndTheCatalogue(): void
    {
        $claims = ['status' => 'Error', 'iss' => 'urn:example', 'iat' => 1790000000, 'reference' => 'reference', 'payment_id' => 'pay-1', 'error' => 'invalid_mandate'];

        self::assertAp2Violates(self::PAYMENT_RECEIPT, $claims);
        self::assertNotSame([], MandateSchema::receiptViolations(Receipt::PAYMENT, $claims));
    }

    #[Test]
    public function anUnknownRecurrenceFrequencyViolatesTheSchema(): void
    {
        $mandate = self::everyPaymentConstraint('HOURLY');

        self::assertAp2Violates(self::OPEN_PAYMENT, $mandate);
        self::assertNotContains('HOURLY', MandateSchema::FREQUENCIES);
    }

    /**
     * @return array<string, mixed>
     */
    private function valid(MandateType $type): array
    {
        $openCheckout = $this->sandbox->openCheckout();
        return match ($type) {
            MandateType::OpenCheckout => self::mandateOf($openCheckout->root()),
            MandateType::OpenPayment => self::mandateOf($this->sandbox->openPayment($openCheckout)->root()),
            MandateType::Checkout => self::mandateOf($this->sandbox->closeCheckout($openCheckout, $this->sandbox->checkout())['chain']->leaf()),
            MandateType::Payment => self::mandateOf($this->sandbox->closePayment($this->sandbox->openPayment($openCheckout), $this->sandbox->checkout())['chain']->leaf()),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private static function everyPaymentConstraint(string $frequency = 'MONTHLY'): array
    {
        $plain = MandateContent::plain([
            'vct' => MandateType::OpenPayment->value,
            'constraints' => [
                ['type' => 'payment.amount_range', 'currency' => 'EUR', 'max' => 50000, 'min' => 0],
                ['type' => 'payment.allowed_payees', 'allowed' => [Parties::MERCHANT]],
                ['type' => 'payment.allowed_payment_instruments', 'allowed' => [Parties::INSTRUMENT]],
                ['type' => 'payment.allowed_pisps', 'allowed' => [['legal_name' => 'Sandbox PISP Ltd', 'brand_name' => 'Sandbox PISP', 'domain_name' => 'pisp.example']]],
                ['type' => 'payment.budget', 'max' => 1200.5, 'currency' => 'EUR'],
                ['type' => 'payment.agent_recurrence', 'frequency' => $frequency, 'max_occurrences' => 12],
                ['type' => 'payment.execution_date', 'not_before' => '2026-10-01', 'not_after' => '2026-12-31'],
                ['type' => 'payment.reference', 'conditional_transaction_id' => 'FzLoxbbtgQGYZxoSM2NJYJtkFTSsdfUBoVEQ12k7JN8'],
            ],
            'cnf' => ['jwk' => ['kty' => 'EC', 'crv' => 'P-256', 'x' => 'QpSyxPQHy38xckyvDr54gZ3T42zj9iLtV4koyb5U27c', 'y' => '37HLd7JJinxjJIn8J7HijssoecBlfhdW-gUL7feI9lw']],
            'iat' => 1790000000,
            'exp' => 1790000900,
        ]);
        return Json::map($plain);
    }

    /**
     * @param array<array-key, mixed> $data
     * @param list<string|int> $path
     * @return array<array-key, mixed>
     */
    private static function alter(array $data, array $path, mixed $value): array
    {
        $key = array_shift($path);
        self::assertNotNull($key, 'The path is not empty.');
        if (is_string($key) && str_starts_with($key, '#')) {
            $key = array_search(substr($key, 1), array_column(Json::objects($data), 'type'), true);
            self::assertIsInt($key, 'The mandate has that constraint.');
        }
        if ($path !== []) {
            $child = $data[$key] ?? null;
            self::assertIsArray($child);
            $data[$key] = self::alter($child, $path, $value);
            return $data;
        }
        if ($value !== self::REMOVE) {
            $data[$key] = $value;
            return $data;
        }
        $list = array_is_list($data);
        unset($data[$key]);
        return $list ? array_values($data) : $data;
    }
}
