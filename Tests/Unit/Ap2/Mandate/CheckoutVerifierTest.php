<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Tests\Unit\Ap2\Mandate;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;
use Webconsulting\AgentNexus\Ap2\Crypto\DelegateChain;
use Webconsulting\AgentNexus\Ap2\Crypto\Digest;
use Webconsulting\AgentNexus\Ap2\Crypto\Disclosure;
use Webconsulting\AgentNexus\Ap2\Crypto\Jws;
use Webconsulting\AgentNexus\Ap2\Mandate\Check;
use Webconsulting\AgentNexus\Ap2\Mandate\CheckType;
use Webconsulting\AgentNexus\Ap2\Mandate\ErrorCode;
use Webconsulting\AgentNexus\Ap2\Mandate\MandateContent;
use Webconsulting\AgentNexus\Ap2\Mandate\MandateUsage;
use Webconsulting\AgentNexus\Ap2\Mandate\Verdict;
use Webconsulting\AgentNexus\Ap2\Sandbox\Parties;
use Webconsulting\AgentNexus\Ap2\Sandbox\Role;
use Webconsulting\AgentNexus\Tests\Unit\Ap2\Fixtures\Sandbox;

/**
 * The merchant's side: the checkout it signed, the constraints the person set,
 * and a mandate that must not be used twice.
 */
final class CheckoutVerifierTest extends UnitTestCase
{
    private Sandbox $sandbox;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sandbox = new Sandbox();
    }

    #[Test]
    public function anAutonomousCheckoutMandateVerifies(): void
    {
        $checkout = $this->sandbox->checkout();
        $closed = $this->sandbox->closeCheckout($this->sandbox->openCheckout(), $checkout);

        $verdict = $this->sandbox->merchant->verify($closed['chain'], $closed['nonce'], $checkout->jwt);

        self::assertValid($verdict);
        self::assertSame(['mandate.checkout.open.1', 'mandate.checkout.1'], array_column($verdict->mandates, 'vct'));
        self::assertContains('Same approved merchant', array_map(static fn(Check $check): string => $check->label(), $verdict->checks));
    }

    #[Test]
    public function aHumanPresentCheckoutMandateSignedByTheTrustedSurfaceVerifies(): void
    {
        $checkout = $this->sandbox->checkout();
        $mandate = $this->sandbox->trustedSurface->sign(MandateContent::closedCheckout($checkout->jwt, $checkout->hash, time(), time() + 600));

        self::assertValid($this->sandbox->merchant->verify($mandate, null, $checkout->jwt));
    }

    #[Test]
    public function aCheckoutTheMerchantDidNotSignFails(): void
    {
        $forged = Jws::sign(['typ' => 'JWT', 'kid' => 'merchant-1'], ['id' => 'chk_forged', 'merchant' => Parties::MERCHANT, 'line_items' => [], 'status' => 'ready_for_complete', 'currency' => 'EUR', 'totals' => [], 'links' => []], $this->sandbox->keys->signer(Role::ShoppingAgent));
        $now = time();
        $mandate = $this->sandbox->trustedSurface->sign(MandateContent::closedCheckout($forged, Digest::of($forged), $now, $now + 600));

        $verdict = $this->sandbox->merchant->verify($mandate);

        self::assertFailedWith($verdict, CheckType::MerchantCheckout, ErrorCode::InvalidMandate, 'not signed by this merchant');
    }

    #[Test]
    public function aCheckoutFromAnotherSessionFails(): void
    {
        $checkout = $this->sandbox->checkout();
        $other = $this->sandbox->checkout([1, 0, 0]);
        $closed = $this->sandbox->closeCheckout($this->sandbox->openCheckout(), $checkout);

        self::assertFailedWith($this->sandbox->merchant->verify($closed['chain'], $closed['nonce'], $other->jwt), CheckType::MerchantCheckout, ErrorCode::InvalidMandate, 'not the one of this session');
    }

    #[Test]
    public function aCheckoutHashThatDoesNotMatchTheJwtFails(): void
    {
        $checkout = $this->sandbox->checkout();
        $other = $this->sandbox->checkout([1, 0, 0]);
        $mandate = $this->sandbox->trustedSurface->sign(MandateContent::closedCheckout($checkout->jwt, $other->hash, time(), time() + 600));

        self::assertFailedWith($this->sandbox->merchant->verify($mandate), CheckType::CheckoutHash, ErrorCode::InvalidMandate);
    }

    #[Test]
    public function aCheckoutAtAMerchantThePersonDidNotAllowFailsTheConstraint(): void
    {
        $checkout = $this->sandbox->checkout();
        $closed = $this->sandbox->closeCheckout($this->sandbox->openCheckout([Parties::OTHER_MERCHANT]), $checkout);

        $verdict = $this->sandbox->merchant->verify($closed['chain'], $closed['nonce']);

        self::assertFailedWith($verdict, CheckType::AllowedMerchant, ErrorCode::UnresolvedConstraint, 'Desiderio Store (desiderio-store) is not an approved merchant.');
    }

    #[Test]
    public function aWithheldCheckoutJwtCannotBeChecked(): void
    {
        $checkout = $this->sandbox->checkout();
        $closed = $this->sandbox->closeCheckout($this->sandbox->openCheckout(), $checkout)['chain'];
        $leaf = $closed->leaf();
        // Present the closed mandate without the checkout_jwt disclosure.
        $withheld = DelegateChain::of($closed->root())->append($leaf->withDisclosures(array_values(array_filter($leaf->disclosures, static fn(Disclosure $disclosure): bool => $disclosure->name !== 'checkout_jwt'))));

        $verdict = $this->sandbox->merchant->verify($withheld);

        self::assertFalse($verdict->valid());
        self::assertTrue($verdict->failed(CheckType::Content) || $verdict->failed(CheckType::MerchantCheckout));
    }

    #[Test]
    public function aMandateUsedOnceIsRefusedTheSecondTime(): void
    {
        $checkout = $this->sandbox->checkout();
        $open = $this->sandbox->openCheckout();
        $closed = $this->sandbox->closeCheckout($open, $checkout);
        self::assertValid($this->sandbox->merchant->verify($closed['chain'], $closed['nonce']));

        $this->sandbox->ledger->accepted[$closed['chain']->reference()] = true;
        self::assertFailedWith($this->sandbox->merchant->verify($closed['chain'], $closed['nonce']), CheckType::SingleUse, ErrorCode::InvalidMandate, 'already used');

        // A second closed mandate under the same open mandate is refused too.
        $this->sandbox->ledger->usage[$open->reference()] = new MandateUsage(1, $checkout->total, time());
        $again = $this->sandbox->closeCheckout($open, $this->sandbox->checkout());
        self::assertFailedWith($this->sandbox->merchant->verify($again['chain'], $again['nonce']), CheckType::SingleUse, ErrorCode::InvalidMandate, 'already authorised a purchase');
    }

    #[Test]
    public function aPaymentMandateIsNotACheckoutMandate(): void
    {
        $checkout = $this->sandbox->checkout();
        $openCheckout = $this->sandbox->openCheckout();
        $payment = $this->sandbox->closePayment($this->sandbox->openPayment($openCheckout), $checkout);

        $verdict = $this->sandbox->merchant->verify($payment['chain'], $payment['nonce']);

        self::assertFailedWith($verdict, CheckType::Audience, ErrorCode::InvalidCredential);
        self::assertTrue($verdict->failed(CheckType::MandateType));
    }

    private static function assertValid(Verdict $verdict): void
    {
        self::assertTrue($verdict->valid(), $verdict->errorDescription());
        self::assertNull($verdict->error());
    }

    private static function assertFailedWith(Verdict $verdict, CheckType $type, ErrorCode $error, string $detail = ''): void
    {
        self::assertFalse($verdict->valid());
        self::assertTrue($verdict->failed($type), $type->value . ' should fail; ' . $verdict->errorDescription());
        self::assertSame($error, $verdict->error());
        if ($detail !== '') {
            $check = array_find($verdict->checks, static fn(Check $check): bool => $check->type === $type);
            self::assertNotNull($check);
            self::assertStringContainsString($detail, $check->detail);
        }
    }
}
