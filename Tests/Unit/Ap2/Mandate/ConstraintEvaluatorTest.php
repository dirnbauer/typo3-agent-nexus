<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Tests\Unit\Ap2\Mandate;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;
use Webconsulting\AgentNexus\Ap2\Mandate\Check;
use Webconsulting\AgentNexus\Ap2\Mandate\CheckType;
use Webconsulting\AgentNexus\Ap2\Mandate\ConstraintEvaluator;
use Webconsulting\AgentNexus\Ap2\Mandate\MandateUsage;

/**
 * One test per constraint type of AP2 v0.2, pass and fail.
 */
final class ConstraintEvaluatorTest extends UnitTestCase
{
    private const array MERCHANT = ['id' => 'desiderio-store', 'name' => 'Desiderio Store', 'website' => 'https://webconsulting.at'];
    private const array PAYMENT = [
        'vct' => 'mandate.payment.1',
        'transaction_id' => 'hash',
        'payee' => self::MERCHANT,
        'payment_amount' => ['amount' => 44700, 'currency' => 'EUR'],
        'payment_instrument' => ['id' => 'sandbox-card-4242', 'type' => 'card', 'description' => 'Sandbox card ending 4242'],
    ];

    private ConstraintEvaluator $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = new ConstraintEvaluator();
    }

    #[Test]
    public function merchantsMatchByIdFirstThenByNameAndWebsite(): void
    {
        self::assertTrue(ConstraintEvaluator::merchantMatches(['id' => 'm-1', 'name' => 'A'], ['id' => 'm-1', 'name' => 'B']));
        self::assertFalse(ConstraintEvaluator::merchantMatches(['id' => 'm-1', 'name' => 'A', 'website' => 'https://a.example'], ['id' => 'm-2', 'name' => 'A', 'website' => 'https://a.example']));
        self::assertTrue(ConstraintEvaluator::merchantMatches(['name' => 'A', 'website' => 'https://a.example'], ['id' => 'm-2', 'name' => 'A', 'website' => 'https://a.example']));
        self::assertFalse(ConstraintEvaluator::merchantMatches(['name' => 'A'], ['name' => 'A']));
    }

    #[Test]
    public function theCheckoutMerchantMustBeOneTheUserApproved(): void
    {
        $open = ['constraints' => [['type' => 'checkout.allowed_merchants', 'allowed' => [self::MERCHANT]]]];

        $pass = $this->subject->checkout($open, ['merchant' => self::MERCHANT, 'line_items' => []]);
        $fail = $this->subject->checkout($open, ['merchant' => ['id' => 'other-shop', 'name' => 'Another shop']]);
        $hidden = $this->subject->checkout(['constraints' => [['type' => 'checkout.allowed_merchants', 'allowed' => []]]], ['merchant' => self::MERCHANT]);

        self::assertCheck($pass, CheckType::AllowedMerchant, true, 'Desiderio Store (desiderio-store)');
        self::assertCheck($fail, CheckType::AllowedMerchant, false, 'Another shop (other-shop) is not an approved merchant.');
        self::assertCheck($hidden, CheckType::AllowedMerchant, false, 'No approved merchant was disclosed.');
        self::assertSame('Same approved merchant', $pass[0]->label());
    }

    #[Test]
    public function lineItemsAreMatchedAgainstTheSignedCheckout(): void
    {
        $open = ['constraints' => [['type' => 'checkout.line_items', 'items' => [
            ['id' => 'licence', 'acceptable_items' => [['id' => 'pro-license', 'title' => 'Pro'], ['id' => 'agency-bundle', 'title' => 'Agency']], 'quantity' => 1],
        ]]]];
        $line = static fn(string $id, int $quantity): array => ['id' => 'li', 'item' => ['id' => $id, 'title' => $id, 'price' => 100], 'quantity' => $quantity, 'totals' => []];

        self::assertCheck($this->subject->checkout($open, ['line_items' => [$line('agency-bundle', 1)]]), CheckType::LineItems, true, '1 item on the approved list');
        self::assertCheck($this->subject->checkout($open, ['line_items' => [$line('agency-bundle', 2)]]), CheckType::LineItems, false);
        self::assertCheck($this->subject->checkout($open, ['line_items' => [$line('support-pack', 1)]]), CheckType::LineItems, false);
    }

    #[Test]
    public function theAmountMustStayWithinTheCapInTheSameCurrency(): void
    {
        $cap = static fn(int $max, ?int $min = null, string $currency = 'EUR'): array => ['constraints' => [
            array_filter(['type' => 'payment.amount_range', 'currency' => $currency, 'max' => $max, 'min' => $min], static fn(mixed $value): bool => $value !== null),
        ]];

        self::assertCheck($this->payment($cap(50000)), CheckType::SpendingCap, true, '€447.00 ≤ €500.00');
        self::assertCheck($this->payment($cap(44700)), CheckType::SpendingCap, true);
        self::assertCheck($this->payment($cap(44699)), CheckType::SpendingCap, false, '€447.00 > €446.99');
        self::assertCheck($this->payment($cap(50000, 45000)), CheckType::SpendingCap, false, '€447.00 < €450.00');
        self::assertCheck($this->payment($cap(50000, null, 'USD')), CheckType::SpendingCap, false, 'Paid in EUR, but the cap is in USD.');
    }

    #[Test]
    public function payeeInstrumentAndProviderMustBeApproved(): void
    {
        $constraints = static fn(array ...$constraints): array => ['constraints' => array_values($constraints)];

        self::assertCheck($this->payment($constraints(['type' => 'payment.allowed_payees', 'allowed' => [self::MERCHANT]])), CheckType::AllowedPayee, true);
        self::assertCheck($this->payment($constraints(['type' => 'payment.allowed_payees', 'allowed' => [['id' => 'other-shop', 'name' => 'Another shop']]])), CheckType::AllowedPayee, false);
        self::assertCheck($this->payment($constraints(['type' => 'payment.allowed_payment_instruments', 'allowed' => [['id' => 'sandbox-card-4242', 'type' => 'card']]])), CheckType::PaymentMethod, true, 'Sandbox card ending 4242');
        self::assertCheck($this->payment($constraints(['type' => 'payment.allowed_payment_instruments', 'allowed' => [['id' => 'other-card', 'type' => 'card']]])), CheckType::PaymentMethod, false);
        self::assertCheck($this->payment($constraints(['type' => 'payment.allowed_pisps', 'allowed' => [['legal_name' => 'P Ltd', 'brand_name' => 'P', 'domain_name' => 'p.example']]])), CheckType::PaymentProvider, false, 'The payment mandate names no payment initiation service provider.');
    }

    #[Test]
    public function theReferenceBindsThePaymentToItsOpenCheckoutMandate(): void
    {
        $open = ['constraints' => [['type' => 'payment.reference', 'conditional_transaction_id' => 'abc']]];

        self::assertCheck($this->subject->payment($open, self::PAYMENT, 'abc', new MandateUsage(), time()), CheckType::PaymentReference, true);
        self::assertCheck($this->subject->payment($open, self::PAYMENT, 'xyz', new MandateUsage(), time()), CheckType::PaymentReference, false, 'The payment belongs to a different checkout mandate.');
        self::assertCheck($this->subject->payment($open, self::PAYMENT, null, new MandateUsage(), time()), CheckType::PaymentReference, false);
    }

    #[Test]
    public function executionDatesMustFallInTheWindow(): void
    {
        $open = ['constraints' => [['type' => 'payment.execution_date', 'not_before' => '2026-10-01T00:00:00Z', 'not_after' => '2026-10-31T23:59:59Z']]];

        self::assertCheck($this->subject->payment($open, self::PAYMENT, null, new MandateUsage(), time()), CheckType::ExecutionDate, true, 'Immediate payment');
        self::assertCheck($this->subject->payment($open, self::PAYMENT + ['execution_date' => '2026-10-15T09:00:00Z'], null, new MandateUsage(), time()), CheckType::ExecutionDate, true);
        self::assertCheck($this->subject->payment($open, self::PAYMENT + ['execution_date' => '2026-11-02T09:00:00Z'], null, new MandateUsage(), time()), CheckType::ExecutionDate, false);
    }

    #[Test]
    public function repeatUseNeedsACapAndABudgetAndRespectsBoth(): void
    {
        $recurrence = ['type' => 'payment.agent_recurrence', 'frequency' => 'MONTHLY', 'max_occurrences' => 3];
        $range = ['type' => 'payment.amount_range', 'currency' => 'EUR', 'max' => 50000];
        $budget = ['type' => 'payment.budget', 'max' => 1000, 'currency' => 'EUR'];

        $unbounded = $this->subject->payment(['constraints' => [$recurrence]], self::PAYMENT, null, new MandateUsage(), time());
        self::assertCheck($unbounded, CheckType::Recurrence, false, 'Repeat use needs a payment.amount_range and a payment.budget constraint.');

        $open = ['constraints' => [$range, $budget, $recurrence]];
        $firstUse = $this->subject->payment($open, self::PAYMENT, null, new MandateUsage(), time());
        self::assertCheck($firstUse, CheckType::Recurrence, true);
        self::assertCheck($firstUse, CheckType::Budget, true, '€447.00 of €1,000.00 spent');

        $thisMonthAlready = $this->subject->payment($open, self::PAYMENT, null, new MandateUsage(1, 44700, time()), time());
        self::assertCheck($thisMonthAlready, CheckType::Recurrence, false);

        $overBudget = $this->subject->payment($open, self::PAYMENT, null, new MandateUsage(2, 60000, strtotime('-2 months')), time());
        self::assertCheck($overBudget, CheckType::Budget, false, '€1,047.00 of €1,000.00 spent');

        $usedUp = $this->subject->payment($open, self::PAYMENT, null, new MandateUsage(3, 0, strtotime('-2 months')), time());
        self::assertCheck($usedUp, CheckType::Recurrence, false, 'Used 3 of 3 times.');
    }

    #[Test]
    public function anUnknownConstraintAlwaysFails(): void
    {
        $checks = $this->subject->payment(['constraints' => [['type' => 'payment.loyalty_points', 'min' => 10]]], self::PAYMENT, null, new MandateUsage(), time());
        $misplaced = $this->subject->checkout(['constraints' => [['type' => 'payment.amount_range', 'currency' => 'EUR', 'max' => 1]]], []);

        self::assertCheck($checks, CheckType::UnknownConstraint, false, '"payment.loyalty_points" is not a constraint this verifier knows, so it fails.');
        self::assertCheck($misplaced, CheckType::UnknownConstraint, false, '"payment.amount_range" does not apply to a checkout mandate.');
    }

    /**
     * @param array<string, mixed> $open
     * @return list<Check>
     */
    private function payment(array $open): array
    {
        return $this->subject->payment($open, self::PAYMENT, null, new MandateUsage(), time());
    }

    /**
     * @param list<Check> $checks
     */
    private static function assertCheck(array $checks, CheckType $type, bool $pass, ?string $detail = null): void
    {
        $check = array_find($checks, static fn(Check $check): bool => $check->type === $type);
        self::assertNotNull($check, 'No ' . $type->value . ' check among ' . implode(', ', array_map(static fn(Check $check): string => $check->type->value, $checks)));
        self::assertSame($pass, $check->pass, $check->detail);
        if ($detail !== null) {
            self::assertSame($detail, $check->detail);
        }
    }
}
