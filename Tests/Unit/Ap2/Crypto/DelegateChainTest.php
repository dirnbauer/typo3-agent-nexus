<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Tests\Unit\Ap2\Crypto;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;
use Webconsulting\AgentNexus\Ap2\Crypto\CryptoException;
use Webconsulting\AgentNexus\Ap2\Crypto\DelegateChain;
use Webconsulting\AgentNexus\Ap2\Crypto\Disclosable;
use Webconsulting\AgentNexus\Ap2\Crypto\EcKey;
use Webconsulting\AgentNexus\Ap2\Crypto\Jws;
use Webconsulting\AgentNexus\Ap2\Crypto\SdJwt;
use Webconsulting\AgentNexus\Ap2\Crypto\SdJwtIssuer;

/**
 * The `~~` serialisation of delegate SD-JWT chains, as the AP2 SDK writes it.
 */
final class DelegateChainTest extends UnitTestCase
{
    #[Test]
    public function twoTokensAreJoinedByADoubleTilde(): void
    {
        [$root, $closed] = self::tokens();
        $chain = DelegateChain::of($root)->append($closed);

        $expected = substr($root->serialize(), 0, -1) . '~~' . $closed->serialize();

        self::assertSame($expected, $chain->serialize());
        self::assertStringEndsWith('~', $chain->serialize());
    }

    #[Test]
    public function parsingGivesBackTheSameTokensAndTheSameString(): void
    {
        [$root, $closed] = self::tokens();
        $serialized = DelegateChain::of($root)->append($closed)->serialize();

        $chain = DelegateChain::parse($serialized);

        self::assertSame(2, $chain->count());
        self::assertSame($root->sdHash(), $chain->root()->sdHash());
        self::assertSame($closed->issuerJwt(), $chain->leaf()->issuerJwt());
        self::assertSame($serialized, $chain->serialize());
        self::assertSame($closed->reference(), $chain->reference());
    }

    #[Test]
    public function aSingleSdJwtIsAChainOfOne(): void
    {
        [$root] = self::tokens();

        $chain = DelegateChain::parse($root->serialize());

        self::assertSame(1, $chain->count());
        self::assertSame($root->serialize(), $chain->serialize());
    }

    #[Test]
    public function aTrailingKeyBindingJwtIsNotPartOfAnAp2Chain(): void
    {
        [$root, $closed] = self::tokens();
        $keyBinding = Jws::sign(['typ' => 'kb+jwt'], ['nonce' => 'n'], EcKey::generate('holder'));

        $this->expectException(CryptoException::class);
        DelegateChain::parse(DelegateChain::of($root)->append($closed)->serialize() . $keyBinding);
    }

    #[Test]
    public function chainsDeeperThanFourTokensAreRefused(): void
    {
        [$root, $closed] = self::tokens();
        $segment = substr($closed->serialize(), 0, -1);

        $this->expectException(CryptoException::class);
        DelegateChain::parse(substr($root->serialize(), 0, -1) . str_repeat('~~' . $segment, 4) . '~');
    }

    #[Test]
    public function garbageIsRefusedWithoutAnError(): void
    {
        foreach (['', '~~', 'not-a-token', 'a.b.c~~d.e.f~'] as $input) {
            try {
                DelegateChain::parse($input);
                self::fail('Parsed "' . $input . '".');
            } catch (CryptoException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /**
     * @return array{0: SdJwt, 1: SdJwt}
     */
    private static function tokens(): array
    {
        $issuer = EcKey::generate('issuer');
        $agent = EcKey::generate('agent');
        $root = SdJwtIssuer::issue(
            ['delegate_payload' => [new Disclosable(['vct' => 'mandate.payment.open.1', 'cnf' => ['jwk' => $agent->publicJwk()]])]],
            ['typ' => 'dc+sd-jwt', 'kid' => $issuer->kid],
            $issuer,
        );
        $closed = SdJwtIssuer::issue(
            ['delegate_payload' => [new Disclosable(['vct' => 'mandate.payment.1'])], 'sd_hash' => $root->sdHash(), 'aud' => 'credential-provider', 'nonce' => 'n', 'iat' => time()],
            ['typ' => 'kb+sd-jwt'],
            $agent,
        );
        return [$root, $closed];
    }
}
