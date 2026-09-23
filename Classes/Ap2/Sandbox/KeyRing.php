<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Ap2\Sandbox;

use TYPO3\CMS\Core\SingletonInterface;
use Webconsulting\AgentNexus\Ap2\Crypto\DecodedJws;
use Webconsulting\AgentNexus\Ap2\Crypto\EcKey;

/**
 * One P-256 key per sandbox role, created on first use and then kept (see
 * {@see RegistryKeyStore}).
 *
 * Roles only ever see each other's public keys, the way separate parties
 * would; the JWK Set endpoint publishes them.
 */
final class KeyRing implements SingletonInterface
{
    /** @var array<string, EcKey>|null */
    private ?array $keys = null;

    public function __construct(
        private readonly KeyStore $store,
    ) {}

    /** The role's key, able to sign. */
    public function signer(Role $role): EcKey
    {
        return $this->all()[$role->value];
    }

    /** The role's public key. */
    public function publicKey(Role $role): EcKey
    {
        return EcKey::fromJwk($this->signer($role)->jwk());
    }

    /** The public key with this id, when a sandbox role has it. */
    public function publicKeyFor(string $kid): ?EcKey
    {
        $role = $this->roleOf($kid);
        return $role === null ? null : $this->publicKey($role);
    }

    /**
     * The trust list of the sandbox verifiers: a root mandate must be signed
     * by the Trusted Surface (the agent provider's key, AP2's "Trusted Agent
     * Provider" model), found by the `kid` in its header.
     *
     * @return \Closure(DecodedJws): ?EcKey
     */
    public function trustedRoots(): \Closure
    {
        $trustedSurface = $this->publicKey(Role::TrustedSurface);
        return static fn(DecodedJws $jws): ?EcKey => $jws->kid() !== '' && hash_equals($trustedSurface->kid, $jws->kid()) ? $trustedSurface : null;
    }

    public function roleOf(string $kid): ?Role
    {
        foreach ($this->all() as $role => $key) {
            if ($kid !== '' && hash_equals($key->kid, $kid)) {
                return Role::from($role);
            }
        }
        return null;
    }

    /**
     * The JWK Set of all roles (RFC 7517 section 5), public keys only.
     *
     * @return array{keys: list<array{kty: string, crv: string, x: string, y: string, kid: string, use: string, alg: string}>}
     */
    public function jwks(): array
    {
        return ['keys' => array_values(array_map(static fn(EcKey $key): array => $key->jwk(), $this->all()))];
    }

    /**
     * @return array<string, EcKey> role value => key, in role order
     */
    private function all(): array
    {
        if ($this->keys !== null) {
            return $this->keys;
        }
        $stored = $this->store->keys(static function (array $keys): array {
            foreach (Role::cases() as $role) {
                if (!isset($keys[$role->value])) {
                    $key = EcKey::generate($role->value);
                    $keys[$role->value] = ['kid' => $key->kid, 'pem' => $key->privatePem()];
                }
            }
            return $keys;
        });
        $keys = [];
        foreach (Role::cases() as $role) {
            $keys[$role->value] = EcKey::fromPrivatePem($stored[$role->value]['pem'], $stored[$role->value]['kid']);
        }
        return $this->keys = $keys;
    }
}
