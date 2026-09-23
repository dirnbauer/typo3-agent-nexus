<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Tests\Unit\Ap2\Crypto;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;
use Webconsulting\AgentNexus\Ap2\Crypto\Base64Url;
use Webconsulting\AgentNexus\Ap2\Crypto\CryptoException;
use Webconsulting\AgentNexus\Ap2\Crypto\EcKey;
use Webconsulting\AgentNexus\Ap2\Crypto\EcSignature;
use Webconsulting\AgentNexus\Ap2\Crypto\Jws;

/**
 * ES256 as JOSE wants it: P-256 keys, raw R||S signatures, and nothing else.
 */
final class JwsTest extends UnitTestCase
{
    private static ?EcKey $key = null;

    #[Test]
    public function aSignedTokenVerifiesAndReturnsItsClaims(): void
    {
        $token = Jws::sign(['typ' => 'JWT', 'kid' => 'merchant-1'], ['id' => 'chk_1', 'total' => 4900], self::key());

        self::assertSame(['id' => 'chk_1', 'total' => 4900], Jws::verify($token, self::key()));
        self::assertSame(['alg' => 'ES256', 'typ' => 'JWT', 'kid' => 'merchant-1'], Jws::decode($token)->header);
    }

    #[Test]
    public function signaturesAreRawRAndSOf64Bytes(): void
    {
        $token = Jws::sign([], ['a' => 1], self::key());
        $signature = Base64Url::decode(explode('.', $token)[2]);

        self::assertSame(64, strlen($signature));
    }

    #[Test]
    public function signingTwiceGivesDifferentSignaturesBecauseEcdsaIsRandomised(): void
    {
        // AP2 requires a non-deterministic scheme for the checkout JWT.
        self::assertNotSame(Jws::sign([], ['a' => 1], self::key()), Jws::sign([], ['a' => 1], self::key()));
    }

    #[Test]
    public function aTamperedPayloadDoesNotVerify(): void
    {
        [$header, , $signature] = explode('.', Jws::sign([], ['amount' => 44700], self::key()));
        $forged = $header . '.' . Base64Url::encode('{"amount":1}') . '.' . $signature;

        $this->expectException(CryptoException::class);
        $this->expectExceptionMessage('The signature does not verify.');
        Jws::verify($forged, self::key());
    }

    #[Test]
    public function anotherKeyDoesNotVerify(): void
    {
        $this->expectException(CryptoException::class);
        Jws::verify(Jws::sign([], ['a' => 1], self::key()), EcKey::generate('other'));
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function refusedHeaders(): array
    {
        return [
            'alg none' => [['alg' => 'none']],
            'HS256' => [['alg' => 'HS256']],
            'RS256' => [['alg' => 'RS256']],
            'ES384' => [['alg' => 'ES384']],
            'no alg' => [['typ' => 'JWT']],
            'crit' => [['alg' => 'ES256', 'crit' => 'exp']],
            'jku' => [['alg' => 'ES256', 'jku' => 'https://attacker.example/jwks']],
            'non-string kid' => [['alg' => 'ES256', 'kid' => 7]],
        ];
    }

    /**
     * @param array<string, mixed> $header
     */
    #[Test]
    #[DataProvider('refusedHeaders')]
    public function refusesEverythingButEs256WithKnownHeaderParameters(array $header): void
    {
        $unsigned = Base64Url::encode((string)json_encode($header)) . '.' . Base64Url::encode('{"a":1}');
        $token = $unsigned . '.' . Base64Url::encode(self::key()->sign($unsigned));

        $this->expectException(CryptoException::class);
        Jws::verify($token, self::key());
    }

    #[Test]
    public function aDetachedSignatureCoversTheHeaderAndTheSeparatePayload(): void
    {
        $detached = Jws::signDetached(['kid' => 'merchant-1'], '{"id":"chk_1"}', self::key());

        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]+\.\.[A-Za-z0-9_-]+$/', $detached);
        self::assertSame(['alg' => 'ES256', 'kid' => 'merchant-1'], Jws::verifyDetached($detached, '{"id":"chk_1"}', self::key()));

        $this->expectException(CryptoException::class);
        Jws::verifyDetached($detached, '{"id":"chk_2"}', self::key());
    }

    #[Test]
    public function derAndRawSignaturesConvertBothWays(): void
    {
        $data = 'signing input';
        $privateKey = openssl_pkey_get_private(self::key()->privatePem());
        self::assertNotFalse($privateKey);
        self::assertTrue(openssl_sign($data, $der, $privateKey, OPENSSL_ALGO_SHA256));
        self::assertIsString($der);

        $raw = EcSignature::derToRaw($der);
        self::assertSame(64, strlen($raw));
        self::assertSame($der, EcSignature::rawToDer($raw));
        self::assertTrue(self::key()->verify($data, $raw));
    }

    #[Test]
    public function componentsWithTheHighBitSetGetASignByteInDer(): void
    {
        $raw = str_repeat("\xff", 32) . "\x00" . str_repeat("\x01", 31);
        $der = EcSignature::rawToDer($raw);

        // SEQUENCE(68) { INTEGER(33) 00 ff…, INTEGER(31) 01… }
        self::assertSame('3044022100' . str_repeat('ff', 32) . '021f' . str_repeat('01', 31), bin2hex($der));
        self::assertSame($raw, EcSignature::derToRaw($der));
    }

    #[Test]
    public function malformedDerIsRefused(): void
    {
        $this->expectException(CryptoException::class);
        EcSignature::derToRaw("\x30\x06\x02\x01\x01\x02\x02\x01");
    }

    #[Test]
    public function aPublicJwkRoundTripsAndCannotSign(): void
    {
        $public = EcKey::fromJwk(self::key()->jwk());

        self::assertTrue($public->sameKeyAs(self::key()));
        self::assertFalse($public->hasPrivateKey());
        self::assertSame(self::key()->kid, $public->kid);
        self::assertSame(self::key()->thumbprint(), $public->thumbprint());
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $public->publicJwk()['x']);
        self::assertTrue($public->verify('x', self::key()->sign('x')));

        $this->expectException(CryptoException::class);
        $public->sign('x');
    }

    #[Test]
    public function aGeneratedKeyIsNamedAfterItsThumbprint(): void
    {
        $key = EcKey::generate('merchant');

        self::assertSame('merchant-' . substr($key->thumbprint(), 0, 8), $key->kid);
        self::assertStringStartsWith('-----BEGIN PRIVATE KEY-----', $key->privatePem());
        self::assertTrue(EcKey::fromPrivatePem($key->privatePem(), $key->kid)->sameKeyAs($key));
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function refusedJwks(): array
    {
        $jwk = EcKey::generate('test')->publicJwk();
        return [
            'a private member' => [$jwk + ['d' => 'c2VjcmV0']],
            'another curve' => [['crv' => 'P-384'] + $jwk],
            'another key type' => [['kty' => 'OKP'] + $jwk],
            'another algorithm' => [$jwk + ['alg' => 'ES384']],
            'encryption use' => [$jwk + ['use' => 'enc']],
            'short coordinates' => [['x' => 'AQAB'] + $jwk],
            'unknown member' => [$jwk + ['extra' => true]],
        ];
    }

    /**
     * @param array<string, mixed> $jwk
     */
    #[Test]
    #[DataProvider('refusedJwks')]
    public function onlyPublicP256SigningJwksAreAccepted(array $jwk): void
    {
        $this->expectException(CryptoException::class);
        EcKey::fromJwk($jwk);
    }

    #[Test]
    public function aPointOffTheCurveIsRefused(): void
    {
        $jwk = self::key()->publicJwk();
        $jwk['y'] = Base64Url::encode(str_repeat("\x01", 32));

        $this->expectException(CryptoException::class);
        EcKey::fromJwk($jwk);
    }

    private static function key(): EcKey
    {
        return self::$key ??= EcKey::generate('unit');
    }
}
