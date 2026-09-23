<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Ucp\Checkout;

use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\SingletonInterface;

/**
 * Idempotency records for the REST binding, kept 24 hours as UCP asks.
 *
 * They live in the extension's own cache ("agentnexus"), which belongs to no
 * cache group: clearing the frontend or system caches does not forget them,
 * only a full flush does. A key is scoped to the platform that sent it (the
 * UCP-Agent profile), so two platforms cannot collide on the same UUID.
 *
 * Idempotency is a guarantee, so a storage failure is not swallowed: it
 * surfaces as {@see IdempotencyUnavailable} and the endpoint answers 503
 * instead of risking a second order.
 */
final class IdempotencyStore implements SingletonInterface
{
    public const int LIFETIME = 86400;
    public const string CACHE = 'agentnexus';

    public function __construct(
        private readonly CacheManager $cacheManager,
    ) {}

    /**
     * @throws IdempotencyUnavailable
     */
    public function find(string $scope, string $key): ?IdempotencyRecord
    {
        try {
            $entry = $this->cache()->get($this->identifier($scope, $key));
        } catch (\Throwable $e) {
            throw new IdempotencyUnavailable('Idempotency records cannot be read.', 1758700101, $e);
        }
        return is_array($entry) ? IdempotencyRecord::fromArray($entry) : null;
    }

    /**
     * @throws IdempotencyUnavailable
     */
    public function remember(string $scope, string $key, IdempotencyRecord $record): void
    {
        try {
            $this->cache()->set($this->identifier($scope, $key), $record->toArray(), [], self::LIFETIME);
        } catch (\Throwable $e) {
            throw new IdempotencyUnavailable('Idempotency records cannot be written.', 1758700102, $e);
        }
    }

    private function cache(): FrontendInterface
    {
        return $this->cacheManager->getCache(self::CACHE);
    }

    private function identifier(string $scope, string $key): string
    {
        return 'ucp_idem_' . hash('sha256', $scope . "\n" . $key);
    }
}
