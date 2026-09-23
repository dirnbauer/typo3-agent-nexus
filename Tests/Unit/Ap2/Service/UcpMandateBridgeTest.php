<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Tests\Unit\Ap2\Service;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;
use Webconsulting\AgentNexus\Ap2\Crypto\Base64Url;
use Webconsulting\AgentNexus\Ap2\Crypto\Jcs;
use Webconsulting\AgentNexus\Ap2\Crypto\Jws;
use Webconsulting\AgentNexus\Ap2\Mandate\MandateContent;
use Webconsulting\AgentNexus\Ap2\Mandate\MandateType;
use Webconsulting\AgentNexus\Ap2\Sandbox\Parties;
use Webconsulting\AgentNexus\Ap2\Sandbox\Role;
use Webconsulting\AgentNexus\Ap2\Service\UcpMandateBridge;
use Webconsulting\AgentNexus\Ap2\Service\UcpVerdict;
use Webconsulting\AgentNexus\Tests\Unit\Ap2\Fixtures\Sandbox;

/**
 * The API the UCP checkout uses: merchant_authorization over JCS, the
 * re-attached checkout JWT, and mandate verification with UCP error codes.
 */
final class UcpMandateBridgeTest extends UnitTestCase
{
    private Sandbox $sandbox;
    private UcpMandateBridge $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sandbox = new Sandbox();
        $this->subject = new UcpMandateBridge($this->sandbox->keys, $this->sandbox->merchant, $this->sandbox->credentialProvider);
    }

    #[Test]
    public function theMerchantAuthorizationIsADetachedJwsOverTheJcsOfTheCheckoutWithoutAp2(): void
    {
        $checkout = $this->subject->withAuthorization(self::checkout());
        $authorization = $checkout['ap2']['merchant_authorization'] ?? '';
        self::assertIsString($authorization);

        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]+\.\.[A-Za-z0-9_-]+$/', $authorization);
        self::assertSame(['alg' => 'ES256', 'kid' => $this->sandbox->keys->signer(Role::Merchant)->kid], Jws::verifyDetached(
            $authorization,
            Jcs::canonicalize(self::checkout()),
            $this->sandbox->keys->publicKey(Role::Merchant),
        ));
        self::assertTrue($this->subject->verifyAuthorization($checkout)->valid);
    }

    #[Test]
    public function aChangedCheckoutOrAMissingAuthorizationIsReported(): void
    {
        $checkout = $this->subject->withAuthorization(self::checkout());
        $tampered = $checkout;
        $tampered['totals'][1]['amount'] = 1;

        self::assertSame(UcpVerdict::MERCHANT_AUTHORIZATION_INVALID, $this->subject->verifyAuthorization($tampered)->code);
        self::assertSame(UcpVerdict::MERCHANT_AUTHORIZATION_MISSING, $this->subject->verifyAuthorization(self::checkout())->code);
    }

    #[Test]
    public function theReattachedCheckoutJwtIsACompactJwsTheMerchantSigned(): void
    {
        $checkout = $this->subject->withAuthorization(self::checkout());
        $jwt = $this->subject->checkoutJwt($checkout);
        self::assertIsString($jwt);

        self::assertEquals(self::checkout(), Jws::verify($jwt, $this->sandbox->keys->publicKey(Role::Merchant)));
        self::assertSame(Base64Url::encode(hash('sha256', $jwt, true)), UcpMandateBridge::checkoutHash($jwt));
    }

    #[Test]
    public function mandatesForTheSessionCheckoutVerify(): void
    {
        [$checkout, $checkoutMandate, $paymentMandate] = $this->mandates();

        $verdict = $this->subject->verifyMandates($checkoutMandate, $checkout, $paymentMandate);

        self::assertTrue($verdict->valid, $verdict->message);
        self::assertNull($verdict->message());
    }

    #[Test]
    public function mandateProblemsMapToUcpErrorCodes(): void
    {
        [$checkout, $checkoutMandate, $paymentMandate] = $this->mandates();
        $otherSession = $this->subject->withAuthorization(['id' => 'chk_other'] + self::checkout());
        $forged = $this->sandbox->trustedSurface->sign(MandateContent::closedCheckout('a.b.c', 'x', time(), time() + 60))->serialize();

        self::assertSame(UcpVerdict::MANDATE_REQUIRED, $this->subject->verifyMandates(null, $checkout)->code);
        self::assertSame(UcpVerdict::MANDATE_SCOPE_MISMATCH, $this->subject->verifyMandates($checkoutMandate, $otherSession)->code);
        self::assertSame(UcpVerdict::MANDATE_INVALID_SIGNATURE, $this->subject->verifyMandates('not.a.mandate~', $checkout)->code);
        self::assertSame(UcpVerdict::MANDATE_SCOPE_MISMATCH, $this->subject->verifyMandates($forged, $checkout)->code);
        self::assertSame(UcpVerdict::MANDATE_SCOPE_MISMATCH, $this->subject->verifyMandates($checkoutMandate, $checkout, $checkoutMandate)->code);
        self::assertSame(
            ['type' => 'error', 'code' => 'mandate_required', 'content' => UcpVerdict::CODES['mandate_required'], 'severity' => 'requires_buyer_input'],
            $this->subject->verifyMandates('', $checkout)->message(),
        );
        self::assertTrue($this->subject->verifyMandates($checkoutMandate, $checkout, $paymentMandate)->valid);
    }

    #[Test]
    public function theProfileKeyIsTheMerchantsPublicJwk(): void
    {
        $jwk = $this->subject->merchantJwk();

        self::assertSame($this->sandbox->keys->signer(Role::Merchant)->kid, $jwk['kid']);
        self::assertArrayNotHasKey('d', $jwk);
        self::assertSame(['kty' => 'EC', 'crv' => 'P-256'], array_intersect_key($jwk, ['kty' => 1, 'crv' => 1]));
    }

    /**
     * A UCP checkout session with the AP2 merchant member.
     *
     * @return array<string, mixed>
     */
    private static function checkout(): array
    {
        return [
            'id' => 'chk_session_1',
            'merchant' => Parties::MERCHANT,
            'line_items' => [[
                'id' => 'li_1',
                'item' => ['id' => 'pro-license', 'title' => 'Desiderio Pro Licence', 'price' => 4900],
                'quantity' => 1,
                'totals' => [['type' => 'subtotal', 'amount' => 4900], ['type' => 'total', 'amount' => 4900]],
            ]],
            'status' => 'ready_for_complete',
            'currency' => 'EUR',
            'totals' => [['type' => 'subtotal', 'amount' => 4900], ['type' => 'total', 'amount' => 4900]],
            'links' => [],
        ];
    }

    /**
     * @return array{0: array<string, mixed>, 1: string, 2: string}
     */
    private function mandates(): array
    {
        $checkout = $this->subject->withAuthorization(self::checkout());
        $checkoutJwt = (string)$this->subject->checkoutJwt($checkout);
        $hash = UcpMandateBridge::checkoutHash($checkoutJwt);
        $now = time();

        $openCheckout = $this->sandbox->trustedSurface->sign(MandateContent::openCheckout(
            [['id' => 'licence', 'acceptable' => [['id' => 'pro-license', 'title' => 'Desiderio Pro Licence']], 'quantity' => 1]],
            [Parties::MERCHANT],
            $this->sandbox->agent->publicJwk(),
            $now,
            $now + 600,
        ));
        $openPayment = $this->sandbox->openPayment($openCheckout, 10000);
        $checkoutMandate = $this->sandbox->agent->close($openCheckout, MandateContent::closedCheckout($checkoutJwt, $hash, $now, $now + 600), MandateType::Checkout->audience(), $this->sandbox->merchant->challenge(), $now);
        $paymentMandate = $this->sandbox->agent->close($openPayment, MandateContent::closedPayment($hash, Parties::MERCHANT, 4900, 'EUR', Parties::INSTRUMENT, $now, $now + 600), MandateType::Payment->audience(), $this->sandbox->credentialProvider->challenge(), $now);

        return [$checkout, $checkoutMandate->serialize(), $paymentMandate->serialize()];
    }
}
