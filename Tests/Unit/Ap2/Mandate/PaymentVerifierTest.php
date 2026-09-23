<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Tests\Unit\Ap2\Mandate;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;
use Webconsulting\AgentNexus\Ap2\Mandate\Check;
use Webconsulting\AgentNexus\Ap2\Mandate\CheckType;
use Webconsulting\AgentNexus\Ap2\Mandate\ErrorCode;
use Webconsulting\AgentNexus\Ap2\Mandate\MandateContent;
use Webconsulting\AgentNexus\Ap2\Mandate\MandateType;
use Webconsulting\AgentNexus\Ap2\Mandate\MandateUsage;
use Webconsulting\AgentNexus\Ap2\Mandate\Verdict;
use Webconsulting\AgentNexus\Ap2\Sandbox\Parties;
use Webconsulting\AgentNexus\Tests\Unit\Ap2\Fixtures\Sandbox;

/**
 * The credential provider's and the payment processor's side: the cap, the
 * binding to the checkout, and single use.
 */
final class PaymentVerifierTest extends UnitTestCase
{
    private Sandbox $sandbox;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sandbox = new Sandbox();
    }

    #[Test]
    public function aPaymentWithinTheCapVerifiesAndSettles(): void
    {
        $openCheckout = $this->sandbox->openCheckout();
        $checkout = $this->sandbox->checkout();
        $payment = $this->sandbox->closePayment($this->sandbox->openPayment($openCheckout, 50000), $checkout);

        $verdict = $this->sandbox->credentialProvider->verify($payment['chain'], $payment['nonce'], $checkout->hash, $openCheckout->root()->sdHash());
        self::assertTrue($verdict->valid(), $verdict->errorDescription());
        self::assertSame('€447.00 ≤ €500.00', self::check($verdict, CheckType::SpendingCap)->detail);

        [$settled, $receipt] = $this->sandbox->processor->settle(
            $this->sandbox->credentialProvider->release($payment['chain'], $payment['nonce']),
            $checkout->hash,
            $openCheckout->root()->sdHash(),
            time(),
        );
        self::assertTrue($settled->valid(), $settled->errorDescription());
        self::assertTrue($receipt->successful());
        self::assertSame($payment['chain']->reference(), $receipt->claims['reference']);
    }

    #[Test]
    public function aPaymentOverTheCapIsAnUnresolvedConstraint(): void
    {
        $openCheckout = $this->sandbox->openCheckout();
        $checkout = $this->sandbox->checkout([1, 0, 0]);
        $payment = $this->sandbox->closePayment($this->sandbox->openPayment($openCheckout, 50000), $checkout);

        $verdict = $this->sandbox->credentialProvider->verify($payment['chain'], $payment['nonce'], $checkout->hash, $openCheckout->root()->sdHash());

        self::assertFalse($verdict->valid());
        self::assertSame(ErrorCode::UnresolvedConstraint, $verdict->error());
        self::assertSame('Within the spending cap: €547.00 > €500.00', $verdict->errorDescription());

        $receipt = $this->sandbox->credentialProvider->refusal($verdict, $payment['chain']->reference(), time());
        self::assertFalse($receipt->successful());
        self::assertSame('unresolved_constraint', $receipt->claims['error']);
        self::assertArrayHasKey('payment_id', $receipt->claims);
    }

    #[Test]
    public function aPaymentForAnotherCheckoutFails(): void
    {
        $openCheckout = $this->sandbox->openCheckout();
        $checkout = $this->sandbox->checkout();
        $other = $this->sandbox->checkout([1, 0, 0]);
        $payment = $this->sandbox->closePayment($this->sandbox->openPayment($openCheckout), $checkout);

        $verdict = $this->sandbox->credentialProvider->verify($payment['chain'], $payment['nonce'], $other->hash, $openCheckout->root()->sdHash());

        self::assertTrue($verdict->failed(CheckType::Transaction));
        self::assertSame(ErrorCode::InvalidMandate, $verdict->error());
    }

    #[Test]
    public function thePaymentMustBelongToThePresentedOpenCheckoutMandate(): void
    {
        $openCheckout = $this->sandbox->openCheckout();
        $checkout = $this->sandbox->checkout();
        $payment = $this->sandbox->closePayment($this->sandbox->openPayment($openCheckout), $checkout);

        $other = $this->sandbox->credentialProvider->verify($payment['chain'], $payment['nonce'], $checkout->hash, $this->sandbox->openCheckout()->root()->sdHash());
        $missing = $this->sandbox->credentialProvider->verify($payment['chain'], $payment['nonce'], $checkout->hash, null);

        self::assertTrue($other->failed(CheckType::PaymentReference));
        self::assertTrue($missing->failed(CheckType::PaymentReference));
        self::assertSame(ErrorCode::UnresolvedConstraint, $missing->error());
    }

    #[Test]
    public function aValuePresetInTheOpenMandateCannotChange(): void
    {
        $openCheckout = $this->sandbox->openCheckout();
        $checkout = $this->sandbox->checkout();
        $now = time();
        $open = MandateContent::openPayment(50000, 'EUR', [Parties::MERCHANT], [Parties::INSTRUMENT], $openCheckout->root()->sdHash(), $this->sandbox->agent->publicJwk(), $now, $now + 600);
        $open['payment_amount'] = ['amount' => 100, 'currency' => 'EUR'];
        $payment = $this->sandbox->closePayment($this->sandbox->trustedSurface->sign($open), $checkout);

        $verdict = $this->sandbox->credentialProvider->verify($payment['chain'], $payment['nonce'], $checkout->hash, $openCheckout->root()->sdHash());

        self::assertTrue($verdict->failed(CheckType::PresetValues));
        self::assertSame(ErrorCode::InvalidMandate, $verdict->error());
    }

    #[Test]
    public function anOpenPaymentMandatePaysOnlyOnceWithoutRecurrence(): void
    {
        $openCheckout = $this->sandbox->openCheckout();
        $openPayment = $this->sandbox->openPayment($openCheckout);
        $checkout = $this->sandbox->checkout();
        $this->sandbox->ledger->usage[$openPayment->reference()] = new MandateUsage(1, 44700, time());
        $payment = $this->sandbox->closePayment($openPayment, $checkout);

        $verdict = $this->sandbox->credentialProvider->verify($payment['chain'], $payment['nonce'], $checkout->hash, $openCheckout->root()->sdHash());
        $processor = $this->sandbox->credentialProvider->verify($payment['chain'], $payment['nonce'], $checkout->hash, $openCheckout->root()->sdHash(), false);

        self::assertTrue($verdict->failed(CheckType::SingleUse));
        self::assertTrue($processor->valid(), 'Without the single-use rule the same mandate verifies: ' . $processor->errorDescription());
    }

    #[Test]
    public function aHumanPresentPaymentMandateNeedsNoOpenMandate(): void
    {
        $checkout = $this->sandbox->checkout();
        $mandate = $this->sandbox->trustedSurface->sign(MandateContent::closedPayment($checkout->hash, Parties::MERCHANT, $checkout->total, 'EUR', Parties::INSTRUMENT, time(), time() + 600));

        $verdict = $this->sandbox->credentialProvider->verify($mandate, null, $checkout->hash, null);

        self::assertTrue($verdict->valid(), $verdict->errorDescription());
        self::assertSame([MandateType::Payment->value], array_column($verdict->mandates, 'vct'));
    }

    private static function check(Verdict $verdict, CheckType $type): Check
    {
        $check = array_find($verdict->checks, static fn(Check $check): bool => $check->type === $type);
        self::assertNotNull($check);
        return $check;
    }
}
