<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Tests\Conformance\Ap2;

use PHPUnit\Framework\Attributes\Test;
use Webconsulting\AgentNexus\Ap2\Crypto\EcKey;
use Webconsulting\AgentNexus\Ap2\Sandbox\Role;
use Webconsulting\AgentNexus\Ap2\Service\UcpMandateBridge;
use Webconsulting\AgentNexus\Tests\Unit\Ap2\Fixtures\Sandbox;

/**
 * The public keys the sandbox publishes (GET /api/agent-nexus/ap2/jwks.json,
 * cnf.jwk in every open mandate, the business key UCP puts in its profile)
 * against AP2's JWK schema and UCP's profile JWK definition.
 */
final class JwksConformanceTest extends Ap2ConformanceTestCase
{
    private Sandbox $sandbox;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sandbox = new Sandbox();
    }

    #[Test]
    public function everyRoleKeyIsAPublicP256KeyForBothSchemas(): void
    {
        $keys = $this->sandbox->keys->jwks()['keys'];

        self::assertCount(count(Role::cases()), $keys);
        self::assertSame(
            array_map(fn(Role $role): string => $this->sandbox->keys->signer($role)->kid, Role::cases()),
            array_column($keys, 'kid'),
            'One key per role, in role order.',
        );
        foreach ($keys as $jwk) {
            self::assertAp2Conforms(self::JWK, $jwk);
            self::assertConformsTo(self::UCP_JWK, $jwk);
            self::assertEquals(['kty' => 'EC', 'crv' => 'P-256', 'alg' => 'ES256', 'use' => 'sig'], array_intersect_key($jwk, ['kty' => 1, 'crv' => 1, 'alg' => 1, 'use' => 1]));
            self::assertTrue(EcKey::fromJwk($jwk)->sameKeyAs($this->sandbox->keys->publicKeyFor($jwk['kid']) ?? EcKey::generate('none')));
        }
    }

    #[Test]
    public function theAgentKeyInCnfConforms(): void
    {
        $jwk = $this->sandbox->agent->publicJwk();

        self::assertAp2Conforms(self::JWK, $jwk);
        self::assertTrue(EcKey::fromJwk($jwk)->sameKeyAs($this->sandbox->keys->publicKey(Role::ShoppingAgent)));
    }

    #[Test]
    public function theBusinessKeyForTheUcpProfileConforms(): void
    {
        $bridge = new UcpMandateBridge($this->sandbox->keys, $this->sandbox->merchant, $this->sandbox->credentialProvider);

        $jwk = $bridge->merchantJwk();

        self::assertConformsTo(self::UCP_JWK, $jwk);
        self::assertContains($jwk, $this->sandbox->keys->jwks()['keys']);
    }

    #[Test]
    public function aPrivateKeyIsRejectedByBothSchemas(): void
    {
        $jwk = $this->sandbox->keys->jwks()['keys'][0] + ['d' => 'bm90LWEtcmVhbC1wcml2YXRlLWtleS1idXQtbG9uZy1lbm91Z2g'];

        self::assertAp2Violates(self::JWK, $jwk);
        self::assertViolates(self::UCP_JWK, $jwk);
    }
}
