<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Tests\Unit\Ap2\Mandate;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;
use Webconsulting\AgentNexus\Ap2\Mandate\Check;
use Webconsulting\AgentNexus\Ap2\Mandate\CheckType;
use Webconsulting\AgentNexus\Ap2\Mandate\ErrorCode;
use Webconsulting\AgentNexus\Ap2\Mandate\MandateSchema;
use Webconsulting\AgentNexus\Ap2\Mandate\MandateType;
use Webconsulting\AgentNexus\Ap2\Mandate\Money;
use Webconsulting\AgentNexus\Ap2\Mandate\Verdict;

final class VerdictAndMoneyTest extends UnitTestCase
{
    #[Test]
    public function aVerdictIsValidOnlyWhenItRanChecksAndAllPassed(): void
    {
        self::assertFalse(new Verdict([])->valid());
        self::assertTrue(new Verdict([Check::pass(CheckType::Format)])->valid());
        self::assertFalse(new Verdict([Check::pass(CheckType::Format), Check::fail(CheckType::SpendingCap, 'over')])->valid());
    }

    #[Test]
    public function theMostSeriousFailureNamesTheError(): void
    {
        $verdict = new Verdict([
            Check::fail(CheckType::SpendingCap, '€547.00 > €500.00'),
            Check::fail(CheckType::SingleUse, 'already used'),
            Check::fail(CheckType::Lifetime, 'expired'),
        ]);

        self::assertSame(ErrorCode::InvalidCredential, $verdict->error());
        self::assertSame('Not expired: expired', $verdict->errorDescription());
        self::assertSame(ErrorCode::InvalidMandate, new Verdict(array_slice($verdict->checks, 0, 2))->error());
        self::assertSame(ErrorCode::UnresolvedConstraint, new Verdict(array_slice($verdict->checks, 0, 1))->error());
        self::assertSame(['valid' => false, 'error' => 'unresolved_constraint', 'errorDescription' => 'Within the spending cap: €547.00 > €500.00', 'checks' => [
            ['id' => 'spending_cap', 'label' => 'Within the spending cap', 'pass' => false, 'detail' => '€547.00 > €500.00'],
        ]], new Verdict(array_slice($verdict->checks, 0, 1))->toArray());
    }

    #[Test]
    public function amountsAreFormattedAndParsedInMinorUnits(): void
    {
        self::assertSame('€447.00', Money::format(44700));
        self::assertSame('€1,490.05', Money::format(149005));
        self::assertSame('-€0.99', Money::format(-99));
        self::assertSame('USD 199.00', Money::format(19900, 'USD'));

        self::assertSame(50000, Money::parse('500'));
        self::assertSame(49990, Money::parse('499.9'));
        self::assertSame(49990, Money::parse('499,90'));
        self::assertNull(Money::parse('4.999'));
        self::assertNull(Money::parse('-5'));
        self::assertNull(Money::parse('1e3'));
        self::assertNull(Money::parse('2000000', 100000000));
    }

    #[Test]
    public function mandateContentIsCheckedAgainstTheSchemaCatalogue(): void
    {
        self::assertSame([], MandateSchema::violations(MandateType::Payment, [
            'vct' => 'mandate.payment.1',
            'transaction_id' => 'h',
            'payee' => ['id' => 'm', 'name' => 'M'],
            'payment_amount' => ['amount' => 100, 'currency' => 'EUR'],
            'payment_instrument' => ['id' => 'c', 'type' => 'card'],
        ]));
        self::assertSame(
            ['payment_amount.amount must be an integer.', 'payment_instrument is missing.'],
            MandateSchema::violations(MandateType::Payment, [
                'vct' => 'mandate.payment.1',
                'transaction_id' => 'h',
                'payee' => ['id' => 'm', 'name' => 'M'],
                'payment_amount' => ['amount' => 1.5, 'currency' => 'EUR'],
            ]),
        );
        self::assertSame(['vct is "mandate.payment.2", expected "mandate.payment.1".'], MandateSchema::violations(MandateType::Payment, ['vct' => 'mandate.payment.2']));
        self::assertContains(
            'The open mandate must contain a payment.reference constraint.',
            MandateSchema::violations(MandateType::OpenPayment, ['vct' => 'mandate.payment.open.1', 'constraints' => [], 'cnf' => ['jwk' => []]]),
        );
    }
}
