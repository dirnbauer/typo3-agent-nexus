<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Tests\Unit\Ap2\Sandbox;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;
use Webconsulting\AgentNexus\Ap2\Crypto\Jws;
use Webconsulting\AgentNexus\Ap2\Sandbox\KeyRing;
use Webconsulting\AgentNexus\Ap2\Sandbox\MemoryKeyStore;
use Webconsulting\AgentNexus\Ap2\Sandbox\Role;
use Webconsulting\AgentNexus\Tests\Unit\Ap2\Fixtures\Sandbox;

/**
 * The key ring and the scripted agent's choices.
 */
final class SandboxRolesTest extends UnitTestCase
{
    #[Test]
    public function everyRoleGetsItsOwnKeyOnceAndKeepsIt(): void
    {
        $store = new MemoryKeyStore();
        $first = new KeyRing($store);
        $second = new KeyRing($store);

        $kids = array_map(static fn(Role $role): string => $first->signer($role)->kid, Role::cases());

        self::assertCount(5, array_unique($kids));
        foreach (Role::cases() as $role) {
            self::assertStringStartsWith($role->value . '-', $first->signer($role)->kid);
            self::assertTrue($second->signer($role)->sameKeyAs($first->signer($role)));
            self::assertSame($role, $first->roleOf($first->signer($role)->kid));
        }
    }

    #[Test]
    public function theJwkSetPublishesPublicKeysOnly(): void
    {
        $jwks = new KeyRing(new MemoryKeyStore())->jwks();

        self::assertCount(5, $jwks['keys']);
        foreach ($jwks['keys'] as $key) {
            self::assertSame(['kty', 'crv', 'x', 'y', 'kid', 'use', 'alg'], array_keys($key));
            self::assertSame('ES256', $key['alg']);
            self::assertSame('sig', $key['use']);
        }
    }

    #[Test]
    public function onlyTheTrustedSurfaceIsARootOfTrust(): void
    {
        $keys = new KeyRing(new MemoryKeyStore());
        $roots = $keys->trustedRoots();

        $fromTrustedSurface = Jws::decode(Jws::sign(['kid' => $keys->signer(Role::TrustedSurface)->kid], ['a' => 1], $keys->signer(Role::TrustedSurface)));
        $fromMerchant = Jws::decode(Jws::sign(['kid' => $keys->signer(Role::Merchant)->kid], ['a' => 1], $keys->signer(Role::Merchant)));

        self::assertNotNull($roots($fromTrustedSurface));
        self::assertNull($roots($fromMerchant));
    }

    #[Test]
    public function theAgentFillsTheCartUpToTheCapOrJustOverIt(): void
    {
        $agent = new Sandbox()->agent;

        $within = $agent->chooseCart(Sandbox::SHOPPING_LIST, 50000, false);
        $over = $agent->chooseCart(Sandbox::SHOPPING_LIST, 50000, true);
        $generous = $agent->chooseCart(Sandbox::SHOPPING_LIST, 60000, false);
        $tight = $agent->chooseCart(Sandbox::SHOPPING_LIST, 30000, false);
        $overButRich = $agent->chooseCart(Sandbox::SHOPPING_LIST, 60000, true);

        self::assertSame([44700, true], [$within['total'], $within['withinCap']]);
        self::assertSame(['pro-license', 'onboarding-addon', 'support-pack'], array_column($within['lines'], 'id'));
        self::assertSame([54700, false], [$over['total'], $over['withinCap']]);
        self::assertSame(54700, $generous['total']);
        self::assertSame([44700, false], [$tight['total'], $tight['withinCap']], 'Nothing fits: the cheapest cart.');
        self::assertSame([54700, true], [$overButRich['total'], $overButRich['withinCap']], 'Nothing exceeds: the dearest cart.');
    }

    #[Test]
    public function theMerchantSignsAUcpCheckoutWithEntropy(): void
    {
        $sandbox = new Sandbox();
        $first = $sandbox->checkout();
        $second = $sandbox->checkout();

        $claims = Jws::verify($first->jwt, $sandbox->keys->publicKey(Role::Merchant));

        self::assertSame('ready_for_complete', $claims['status']);
        self::assertSame(44700, $first->total);
        self::assertSame([['type' => 'subtotal', 'amount' => 44700], ['type' => 'total', 'amount' => 44700]], $claims['totals']);
        self::assertArrayHasKey('jti', $claims);
        self::assertNotSame($first->hash, $second->hash, 'The same cart twice must not give the same checkout hash.');
    }
}
