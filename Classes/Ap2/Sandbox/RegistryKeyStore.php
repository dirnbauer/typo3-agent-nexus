<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Ap2\Sandbox;

use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use TYPO3\CMS\Core\Locking\LockFactory;
use TYPO3\CMS\Core\Locking\LockingStrategyInterface;
use TYPO3\CMS\Core\Registry;
use Webconsulting\AgentNexus\Ap2\Crypto\EcKey;

/**
 * Keeps the sandbox keys in the TYPO3 registry (namespace `agent_nexus`, key
 * `ap2.keys`): per role the key id, the private key as PEM and the public JWK.
 *
 * The keys are created on first use, per installation; none ships with the
 * extension. They sign demo mandates only — anyone with database access can
 * read them, which is exactly why nothing they sign may ever move money.
 */
#[AsAlias(KeyStore::class)]
final readonly class RegistryKeyStore implements KeyStore
{
    public const string NAMESPACE = 'agent_nexus';
    public const string KEY = 'ap2.keys';

    public function __construct(
        private Registry $registry,
        private LockFactory $lockFactory,
    ) {}

    public function keys(\Closure $complete): array
    {
        $stored = $this->read();
        $completed = $complete($stored);
        if ($completed === $stored) {
            return $stored;
        }

        $lock = $this->lockFactory->createLocker('agent_nexus_ap2_keys', LockingStrategyInterface::LOCK_CAPABILITY_EXCLUSIVE);
        $lock->acquire();
        try {
            // Another request may have stored its keys while this one waited.
            $stored = $this->read();
            $completed = $complete($stored);
            if ($completed !== $stored) {
                $withJwks = array_map(
                    static fn(array $key): array => $key + ['jwk' => EcKey::fromPrivatePem($key['pem'], $key['kid'])->jwk()],
                    $completed,
                );
                $this->registry->set(self::NAMESPACE, self::KEY, ['version' => 1, 'keys' => $withJwks]);
            }
            return $completed;
        } finally {
            $lock->release();
        }
    }

    /**
     * @return array<string, array{kid: string, pem: string}>
     */
    private function read(): array
    {
        $value = $this->registry->get(self::NAMESPACE, self::KEY);
        $keys = is_array($value) && is_array($value['keys'] ?? null) ? $value['keys'] : [];
        $valid = [];
        foreach ($keys as $role => $key) {
            if (is_array($key) && is_string($key['kid'] ?? null) && is_string($key['pem'] ?? null)) {
                $valid[(string)$role] = ['kid' => $key['kid'], 'pem' => $key['pem']];
            }
        }
        return $valid;
    }
}
