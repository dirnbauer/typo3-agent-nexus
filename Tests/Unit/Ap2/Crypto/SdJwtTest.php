<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Tests\Unit\Ap2\Crypto;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;
use Webconsulting\AgentNexus\Ap2\Crypto\Base64Url;
use Webconsulting\AgentNexus\Ap2\Crypto\CryptoException;
use Webconsulting\AgentNexus\Ap2\Crypto\Digest;
use Webconsulting\AgentNexus\Ap2\Crypto\Disclosable;
use Webconsulting\AgentNexus\Ap2\Crypto\Disclosure;
use Webconsulting\AgentNexus\Ap2\Crypto\EcKey;
use Webconsulting\AgentNexus\Ap2\Crypto\Json;
use Webconsulting\AgentNexus\Ap2\Crypto\Jws;
use Webconsulting\AgentNexus\Ap2\Crypto\SdJwt;
use Webconsulting\AgentNexus\Ap2\Crypto\SdJwtIssuer;
use Webconsulting\AgentNexus\Ap2\Crypto\SdJwtProcessor;

/**
 * SD-JWT issuance and processing (RFC 9901): what is concealed, what a holder
 * may reveal, and every rule that stops a holder from adding claims.
 */
final class SdJwtTest extends UnitTestCase
{
    private static ?EcKey $issuer = null;

    #[Test]
    public function digestsMatchTheRfc9901Examples(): void
    {
        // RFC 9901 sections 4.2.1, 4.2.2 and 4.2.3.
        $objectProperty = 'WyJfMjZiYzRMVC1hYzZxMktJNmNCVzVlcyIsICJmYW1pbHlfbmFtZSIsICJNw7ZiaXVzIl0';
        $arrayElement = 'WyJsa2x4RjVqTVlsR1RQVW92TU5JdkNBIiwgIkZSIl0';

        self::assertSame('X9yH0Ajrdm1Oij4tWso9UzzKJvPoDxwmuEcO3XAdRC0', Digest::of($objectProperty));
        self::assertSame('w0I8EKcdCtUPkGCNUrfwVp2xEgNjtoIDlOxc9-PlOhs', Digest::of($arrayElement));

        $property = Disclosure::decode($objectProperty);
        self::assertSame('_26bc4LT-ac6q2KI6cBW5es', $property->salt);
        self::assertSame('family_name', $property->name);
        self::assertSame('Möbius', $property->value);
        self::assertTrue(Disclosure::decode($arrayElement)->isArrayElement());
    }

    #[Test]
    public function disclosablesAreConcealedAndComeBackWhenDisclosed(): void
    {
        $token = self::issue();
        $payload = $token->payload();

        self::assertSame('sha-256', $payload['_sd_alg']);
        self::assertArrayNotHasKey('secret', $payload);
        self::assertSame([$token->disclosures[1]->digest], Json::list($payload['_sd'] ?? null));
        self::assertSame(['kept', ['...' => $token->disclosures[0]->digest]], $payload['items']);
        self::assertCount(2, $token->disclosures);

        self::assertSame(
            ['plain' => 'visible', 'items' => ['kept', 'hidden element'], 'nested' => ['inner' => 'deep'], 'secret' => 'hidden claim'],
            SdJwtProcessor::verify(SdJwt::parse($token->serialize()), self::issuer()),
        );
    }

    #[Test]
    public function innerDisclosuresComeBeforeTheOuterOnes(): void
    {
        $token = SdJwtIssuer::issue(['delegate_payload' => [new Disclosable(['allowed' => [new Disclosable('merchant')]])]], [], self::issuer());

        self::assertSame(['allowed' => [['...' => $token->disclosures[0]->digest]]], $token->disclosures[1]->value);
    }

    #[Test]
    public function withheldDisclosuresSimplyDisappear(): void
    {
        $token = self::issue();

        $withoutElement = SdJwtProcessor::process(SdJwt::parse($token->withDisclosures([$token->disclosures[1]])->serialize()));
        $withoutClaim = SdJwtProcessor::process(SdJwt::parse($token->withDisclosures([$token->disclosures[0]])->serialize()));

        self::assertSame(['kept'], $withoutElement['items']);
        self::assertSame('hidden claim', $withoutElement['secret']);
        self::assertSame(['kept', 'hidden element'], $withoutClaim['items']);
        self::assertArrayNotHasKey('secret', $withoutClaim);
    }

    #[Test]
    public function aDisclosureTheTokenDoesNotReferenceIsRefused(): void
    {
        $stray = Disclosure::create('smuggled', 'role');
        $token = self::issue();

        $this->expectException(CryptoException::class);
        $this->expectExceptionMessage('not referenced');
        SdJwtProcessor::process($token->withDisclosures([...$token->disclosures, $stray]));
    }

    #[Test]
    public function aDisclosurePresentedTwiceIsRefused(): void
    {
        $token = self::issue();

        $this->expectException(CryptoException::class);
        SdJwtProcessor::process($token->withDisclosures([...$token->disclosures, $token->disclosures[0]]));
    }

    #[Test]
    public function aDisclosedClaimMayNotOverwriteASignedOne(): void
    {
        $disclosure = Disclosure::create('attacker value', 'plain');
        $jwt = Jws::sign([], ['plain' => 'signed value', '_sd' => [$disclosure->digest], '_sd_alg' => 'sha-256'], self::issuer());

        $this->expectException(CryptoException::class);
        $this->expectExceptionMessage('already exists');
        SdJwtProcessor::process(SdJwt::fromParts($jwt, [$disclosure]));
    }

    #[Test]
    public function reservedClaimNamesCannotBeDisclosed(): void
    {
        $disclosure = Disclosure::create(['x'], '_sd');
        $jwt = Jws::sign([], ['_sd' => [$disclosure->digest], '_sd_alg' => 'sha-256'], self::issuer());

        $this->expectException(CryptoException::class);
        SdJwtProcessor::process(SdJwt::fromParts($jwt, [$disclosure]));
    }

    #[Test]
    public function anArrayElementDisclosureCannotStandInForAProperty(): void
    {
        $element = Disclosure::create('value');
        $jwt = Jws::sign([], ['_sd' => [$element->digest], '_sd_alg' => 'sha-256'], self::issuer());

        $this->expectException(CryptoException::class);
        SdJwtProcessor::process(SdJwt::fromParts($jwt, [$element]));
    }

    #[Test]
    public function aDigestThatAppearsTwiceIsRefused(): void
    {
        $disclosure = Disclosure::create('value');
        $jwt = Jws::sign([], ['a' => [['...' => $disclosure->digest]], 'b' => [['...' => $disclosure->digest]], '_sd_alg' => 'sha-256'], self::issuer());

        $this->expectException(CryptoException::class);
        SdJwtProcessor::process(SdJwt::fromParts($jwt, [$disclosure]));
    }

    #[Test]
    public function aForgedIssuerSignatureIsRefused(): void
    {
        $this->expectException(CryptoException::class);
        SdJwtProcessor::verify(self::issue(), EcKey::generate('other'));
    }

    #[Test]
    public function theSdHashCoversTheIssuerJwtAndEveryPresentedDisclosure(): void
    {
        $token = self::issue();
        $expected = Base64Url::encode(hash('sha256', $token->serialize(), true));

        self::assertStringEndsWith('~', $token->serialize());
        self::assertSame($expected, $token->sdHash());
        self::assertNotSame($token->sdHash(), $token->withDisclosures([$token->disclosures[0]])->sdHash());
        self::assertSame(Base64Url::encode(hash('sha256', $token->issuerJwt(), true)), $token->issuerJwtHash());
    }

    #[Test]
    public function aKeyBindingJwtIsParsedButKeptApart(): void
    {
        $token = self::issue();
        $keyBinding = Jws::sign(['typ' => 'kb+jwt'], ['sd_hash' => $token->sdHash()], self::issuer());

        $parsed = SdJwt::parse($token->serialize() . $keyBinding);

        self::assertSame($keyBinding, $parsed->keyBindingJwt);
        self::assertSame($token->sdHash(), $parsed->sdHash());
    }

    #[Test]
    public function aPlainJwtIsNotAnSdJwt(): void
    {
        $this->expectException(CryptoException::class);
        SdJwt::parse(Jws::sign([], ['a' => 1], self::issuer()));
    }

    private static function issue(): SdJwt
    {
        return SdJwtIssuer::issue([
            'plain' => 'visible',
            'items' => ['kept', new Disclosable('hidden element')],
            'nested' => ['inner' => 'deep'],
            'secret' => new Disclosable('hidden claim'),
        ], ['typ' => 'dc+sd-jwt'], self::issuer());
    }

    private static function issuer(): EcKey
    {
        return self::$issuer ??= EcKey::generate('issuer');
    }
}
