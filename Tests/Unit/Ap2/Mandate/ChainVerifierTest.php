<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Tests\Unit\Ap2\Mandate;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;
use Webconsulting\AgentNexus\Ap2\Crypto\DecodedJws;
use Webconsulting\AgentNexus\Ap2\Crypto\DelegateChain;
use Webconsulting\AgentNexus\Ap2\Crypto\Disclosable;
use Webconsulting\AgentNexus\Ap2\Crypto\EcKey;
use Webconsulting\AgentNexus\Ap2\Crypto\SdJwt;
use Webconsulting\AgentNexus\Ap2\Crypto\SdJwtIssuer;
use Webconsulting\AgentNexus\Ap2\Mandate\ChainResult;
use Webconsulting\AgentNexus\Ap2\Mandate\ChainVerifier;
use Webconsulting\AgentNexus\Ap2\Mandate\Check;
use Webconsulting\AgentNexus\Ap2\Mandate\CheckType;

/**
 * The delegate SD-JWT chain rules, each broken on its own.
 */
final class ChainVerifierTest extends UnitTestCase
{
    private EcKey $issuer;
    private EcKey $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->issuer = EcKey::generate('trusted-surface');
        $this->agent = EcKey::generate('shopping-agent');
    }

    #[Test]
    public function aWellFormedChainPassesEveryCheck(): void
    {
        $result = $this->verify($this->chain());

        self::assertTrue($result->readable);
        foreach ($result->checks as $check) {
            self::assertTrue($check->pass, $check->label() . ': ' . $check->detail);
        }
        self::assertSame(
            [CheckType::Format, CheckType::RootSignature, CheckType::AgentSignature, CheckType::Binding, CheckType::Audience, CheckType::SingleMandate, CheckType::Lifetime],
            array_map(static fn(Check $check): CheckType => $check->type, $result->checks),
        );
        self::assertSame(['mandate.payment.open.1', 'mandate.payment.1'], array_column($result->mandates, 'vct'));
    }

    #[Test]
    public function aRootFromAnUntrustedKeyFails(): void
    {
        $impostor = EcKey::generate('trusted-surface');

        self::assertFailed($this->verify($this->chain(root: $impostor)), CheckType::RootSignature, 'No trusted key has the id');
        // Claiming the trusted key's id does not help: the signature is checked with that key.
        self::assertFailed($this->verify($this->chain(root: $impostor->withKid($this->issuer->kid))), CheckType::RootSignature, 'does not verify');
    }

    #[Test]
    public function aClosingTokenSignedWithAnotherKeyFails(): void
    {
        self::assertFailed($this->verify($this->chain(closer: EcKey::generate('someone-else'))), CheckType::AgentSignature);
    }

    #[Test]
    public function aClosedMandateBoundToAnotherOpenMandateFails(): void
    {
        $other = $this->open();
        $chain = DelegateChain::of($this->open()->root())->append($this->closing($other->root()->sdHash()));

        self::assertFailed($this->verify($chain), CheckType::Binding, 'does not match');
    }

    #[Test]
    public function withholdingADisclosureAfterBindingBreaksTheSdHash(): void
    {
        $chain = $this->chain();
        $open = $chain->root();
        $stripped = DelegateChain::of($open->withDisclosures([...array_slice($open->disclosures, 0, -2), $open->disclosures[count($open->disclosures) - 1]]))->append($chain->leaf());

        self::assertFailed($this->verify($stripped), CheckType::Binding);
    }

    #[Test]
    public function anIssuerJwtHashIsAcceptedInsteadOfAnSdHash(): void
    {
        $open = $this->open();
        $closing = SdJwtIssuer::issue([
            'delegate_payload' => [new Disclosable(['vct' => 'mandate.payment.1'])],
            'iat' => time(),
            'aud' => 'credential-provider',
            'nonce' => 'n-1',
            'issuer_jwt_hash' => $open->root()->issuerJwtHash(),
        ], ['typ' => 'kb+sd-jwt'], $this->agent);

        $result = $this->verify($open->append($closing));

        self::assertTrue(self::check($result, CheckType::Binding)->pass);
    }

    #[Test]
    public function bothOrNeitherBindingClaimFails(): void
    {
        $open = $this->open();
        $neither = SdJwtIssuer::issue(['delegate_payload' => [new Disclosable(['vct' => 'mandate.payment.1'])], 'iat' => time(), 'aud' => 'credential-provider', 'nonce' => 'n-1'], ['typ' => 'kb+sd-jwt'], $this->agent);

        self::assertFailed($this->verify($open->append($neither)), CheckType::Binding, 'exactly one');
    }

    #[Test]
    public function theClosingTokenMustBeForThisVerifierAndItsChallenge(): void
    {
        $chain = $this->chain();

        self::assertFailed($this->verify($chain, audience: 'merchant'), CheckType::Audience, 'aud is "credential-provider", expected "merchant"');
        self::assertFailed($this->verify($chain, nonce: 'another-nonce'), CheckType::Audience, 'The nonce does not match the challenge.');
        self::assertFailed($this->verify($chain, nonce: static fn(string $nonce): bool => false), CheckType::Audience, 'not issued by this verifier');
        self::assertTrue(self::check($this->verify($chain, nonce: static fn(string $nonce): bool => $nonce === 'n-1'), CheckType::Audience)->pass);
    }

    #[Test]
    public function expiryHoldsWithFiveMinutesOfClockSkew(): void
    {
        $withinSkew = $this->chain(openExpires: time() - 200);
        $expired = $this->chain(openExpires: time() - 400);

        self::assertTrue(self::check($this->verify($withinSkew), CheckType::Lifetime)->pass);
        self::assertFailed($this->verify($expired), CheckType::Lifetime, 'expired');
    }

    #[Test]
    public function aTokenIssuedInTheFutureFails(): void
    {
        self::assertFailed($this->verify($this->chain(closedIssuedAt: time() + 3600)), CheckType::Lifetime, 'future');
    }

    #[Test]
    public function everyTokenDisclosesExactlyOneMandate(): void
    {
        $open = $this->open();
        $two = SdJwtIssuer::issue([
            'delegate_payload' => [new Disclosable(['vct' => 'mandate.payment.1']), new Disclosable(['vct' => 'mandate.payment.1'])],
            'iat' => time(),
            'aud' => 'credential-provider',
            'nonce' => 'n-1',
            'sd_hash' => $open->root()->sdHash(),
        ], ['typ' => 'kb+sd-jwt'], $this->agent);

        $result = $this->verify($open->append($two));

        self::assertFalse($result->readable);
        self::assertFailed($result, CheckType::SingleMandate, 'discloses 2 mandates');
    }

    #[Test]
    public function aClosedMandateMayNotDelegateFurther(): void
    {
        $open = $this->open();
        $closing = SdJwtIssuer::issue([
            'delegate_payload' => [new Disclosable(['vct' => 'mandate.payment.1', 'cnf' => ['jwk' => $this->agent->publicJwk()]])],
            'iat' => time(),
            'aud' => 'credential-provider',
            'nonce' => 'n-1',
            'sd_hash' => $open->root()->sdHash(),
        ], ['typ' => 'kb+sd-jwt'], $this->agent);

        self::assertFailed($this->verify($open->append($closing)), CheckType::Format, 'cnf');
    }

    #[Test]
    public function theTokenTypesMustFitTheirPlaceInTheChain(): void
    {
        $open = $this->open();
        $closing = SdJwtIssuer::issue([
            'delegate_payload' => [new Disclosable(['vct' => 'mandate.payment.1'])],
            'iat' => time(),
            'aud' => 'credential-provider',
            'nonce' => 'n-1',
            'sd_hash' => $open->root()->sdHash(),
        ], ['typ' => 'dc+sd-jwt'], $this->agent);

        self::assertFailed($this->verify($open->append($closing)), CheckType::Format, 'expected kb+sd-jwt');
    }

    #[Test]
    public function aHumanPresentMandateIsAChainOfOneWithoutAudienceCheck(): void
    {
        $root = SdJwtIssuer::issue(['delegate_payload' => [new Disclosable(['vct' => 'mandate.payment.1', 'exp' => time() + 60])]], ['typ' => 'dc+sd-jwt', 'kid' => $this->issuer->kid], $this->issuer);

        $result = $this->verify(DelegateChain::of($root));

        self::assertTrue($result->readable);
        self::assertSame([CheckType::Format, CheckType::RootSignature, CheckType::SingleMandate, CheckType::Lifetime], array_map(static fn(Check $check): CheckType => $check->type, $result->checks));
    }

    private function verify(
        DelegateChain $chain,
        ?string $audience = 'credential-provider',
        \Closure|string|null $nonce = 'n-1',
        ?EcKey $rootKey = null,
    ): ChainResult {
        $trusted = $rootKey ?? $this->issuer;
        return new ChainVerifier()->verify(
            $chain,
            static fn(DecodedJws $jws): ?EcKey => $jws->kid() === $trusted->kid ? $trusted : null,
            $audience,
            $nonce,
        );
    }

    private function chain(?EcKey $root = null, ?EcKey $closer = null, ?int $openExpires = null, ?int $closedIssuedAt = null): DelegateChain
    {
        $open = $this->open($root, $openExpires);
        return $open->append($this->closing($open->root()->sdHash(), $closer, $closedIssuedAt));
    }

    private function open(?EcKey $root = null, ?int $expires = null): DelegateChain
    {
        $root ??= $this->issuer;
        return DelegateChain::of(SdJwtIssuer::issue(['delegate_payload' => [new Disclosable([
            'vct' => 'mandate.payment.open.1',
            'constraints' => [['type' => 'payment.allowed_payees', 'allowed' => [new Disclosable(['id' => 'm', 'name' => 'M'])]]],
            'cnf' => ['jwk' => $this->agent->publicJwk()],
            'iat' => time(),
            'exp' => $expires ?? time() + 900,
        ])]], ['typ' => 'dc+sd-jwt', 'kid' => $root->kid], $root));
    }

    private function closing(string $sdHash, ?EcKey $closer = null, ?int $issuedAt = null): SdJwt
    {
        return SdJwtIssuer::issue([
            'delegate_payload' => [new Disclosable(['vct' => 'mandate.payment.1'])],
            'iat' => $issuedAt ?? time(),
            'aud' => 'credential-provider',
            'nonce' => 'n-1',
            'sd_hash' => $sdHash,
        ], ['typ' => 'kb+sd-jwt'], $closer ?? $this->agent);
    }

    private static function check(ChainResult $result, CheckType $type): Check
    {
        $check = array_find($result->checks, static fn(Check $check): bool => $check->type === $type);
        self::assertNotNull($check, 'No ' . $type->value . ' check.');
        return $check;
    }

    private static function assertFailed(ChainResult $result, CheckType $type, string $detail = ''): void
    {
        $check = self::check($result, $type);
        self::assertFalse($check->pass, $type->value . ' should fail');
        if ($detail !== '') {
            self::assertStringContainsString($detail, $check->detail);
        }
    }
}
