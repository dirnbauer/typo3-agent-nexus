<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Tests\Unit\Ap2\Service;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;
use Webconsulting\AgentNexus\Ap2\Service\Jwt;
use Webconsulting\AgentNexus\Ap2\Service\MandateService;

/**
 * The chain is the whole point of AP2: a valid signature on each mandate is not
 * enough — the cart has to reference the intent, name an allowed merchant and
 * stay inside the cap. Each test below breaks exactly one of those links.
 */
final class MandateServiceTest extends UnitTestCase
{
    private MandateService $subject;
    private Jwt $jwt;

    protected function setUp(): void
    {
        parent::setUp();
        $this->jwt = new Jwt();
        $this->subject = new MandateService($this->jwt);
    }

    #[Test]
    public function aWellFormedChainAuthorizesAndPassesEveryCheck(): void
    {
        $chain = $this->chain();

        self::assertTrue($chain['authorized']);
        foreach ($chain['checks'] as $check) {
            self::assertTrue($check['pass'], $check['label'] . ' should pass');
        }
    }

    #[Test]
    public function aCartOverTheIntentCapIsRefused(): void
    {
        $chain = $this->chain(capCents: 10000, totalCents: 44800);

        self::assertFalse($chain['authorized']);
        self::assertFalse($this->check($chain, 'Within the spending cap'));
    }

    #[Test]
    public function aCartForAnUnauthorizedMerchantIsRefused(): void
    {
        $chain = $this->chain(merchant: 'some-other-store');

        self::assertFalse($chain['authorized']);
        self::assertFalse($this->check($chain, 'Same authorized merchant'));
    }

    #[Test]
    public function aCartThatReferencesAnotherIntentIsRefused(): void
    {
        $intent = $this->subject->mintIntentMandate(['maxAmountCents' => 50000, 'merchants' => ['desiderio-store']]);
        $cart = $this->subject->mintCartMandate(
            ['items' => [], 'totalCents' => 1000, 'currency' => 'EUR', 'merchant' => 'desiderio-store'],
            'im-someone-elses-intent',
        );

        $chain = $this->subject->verifyChain($intent['jwt'], $cart['jwt']);

        self::assertFalse($chain['authorized']);
        self::assertFalse($this->check($chain, 'Cart references the Intent'));
    }

    #[Test]
    public function aTamperedCartMandateFailsItsSignatureCheck(): void
    {
        $intent = $this->subject->mintIntentMandate(['maxAmountCents' => 50000, 'merchants' => ['desiderio-store']]);
        $cart = $this->subject->mintCartMandate(
            ['items' => [], 'totalCents' => 1000, 'currency' => 'EUR', 'merchant' => 'desiderio-store'],
            (string)$intent['claims']['jti'],
        );

        // Re-sign nothing: swap the payload and keep the original signature.
        [$header, $payload, $signature] = explode('.', $cart['jwt']);
        $claims = json_decode($this->jwt->b64UrlDecode($payload), true);
        $claims['cart']['totalCents'] = 1;
        $tampered = $header . '.' . $this->jwt->b64UrlEncode((string)json_encode($claims)) . '.' . $signature;

        $chain = $this->subject->verifyChain($intent['jwt'], $tampered);

        self::assertFalse($chain['authorized']);
        self::assertFalse($this->check($chain, 'Cart Mandate signature'));
    }

    #[Test]
    public function anExpiredIntentMandateInvalidatesTheWholeChain(): void
    {
        $expired = $this->jwt->sign([
            'typ' => 'IntentMandate',
            'aud' => 'desiderio-store',
            'jti' => 'im-expired',
            'constraints' => ['maxAmountCents' => 50000, 'currency' => 'EUR', 'merchants' => ['desiderio-store']],
            'exp' => time() - 60,
        ]);
        $cart = $this->subject->mintCartMandate(
            ['items' => [], 'totalCents' => 1000, 'currency' => 'EUR', 'merchant' => 'desiderio-store'],
            'im-expired',
        );

        $chain = $this->subject->verifyChain($expired, $cart['jwt']);

        self::assertFalse($chain['authorized']);
        self::assertFalse($this->check($chain, 'Intent Mandate signature'));
    }

    /**
     * @return array{authorized: bool, checks: array<int, array{label: string, pass: bool, detail: string}>, intent: array<string, mixed>, cart: array<string, mixed>}
     */
    private function chain(int $capCents = 50000, int $totalCents = 44800, string $merchant = 'desiderio-store'): array
    {
        $intent = $this->subject->mintIntentMandate([
            'maxAmountCents' => $capCents,
            'currency' => 'EUR',
            'merchants' => ['desiderio-store'],
        ]);
        $cart = $this->subject->mintCartMandate(
            ['items' => [], 'totalCents' => $totalCents, 'currency' => 'EUR', 'merchant' => $merchant],
            (string)$intent['claims']['jti'],
        );

        return $this->subject->verifyChain($intent['jwt'], $cart['jwt']);
    }

    /**
     * @param array{checks: array<int, array{label: string, pass: bool, detail: string}>} $chain
     */
    private function check(array $chain, string $label): bool
    {
        foreach ($chain['checks'] as $check) {
            if ($check['label'] === $label) {
                return $check['pass'];
            }
        }
        self::fail('No check labelled "' . $label . '" in the chain.');
    }
}
