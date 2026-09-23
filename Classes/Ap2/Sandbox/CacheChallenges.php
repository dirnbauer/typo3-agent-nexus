<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Ap2\Sandbox;

use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\SingletonInterface;

/**
 * Remembers issued nonces for an hour in the `agentnexus` cache, and for the
 * rest of the request in memory — so a flow that runs in one request works
 * even when the cache is unavailable.
 */
#[AsAlias(Challenges::class)]
final class CacheChallenges implements Challenges, SingletonInterface
{
    private const int LIFETIME = 3600;
    private const string CACHE = 'agentnexus';

    /** @var array<string, true> */
    private array $issued = [];

    public function __construct(
        private readonly CacheManager $cacheManager,
    ) {}

    public function issue(string $audience): string
    {
        $nonce = bin2hex(random_bytes(16));
        $key = self::key($audience, $nonce);
        $this->issued[$key] = true;
        $this->cache()?->set($key, 1, [], self::LIFETIME);
        return $nonce;
    }

    public function issued(string $audience, string $nonce): bool
    {
        if (preg_match('/^[0-9a-f]{32}$/', $nonce) !== 1) {
            return false;
        }
        $key = self::key($audience, $nonce);
        return isset($this->issued[$key]) || $this->cache()?->get($key) === 1;
    }

    private static function key(string $audience, string $nonce): string
    {
        return 'ap2_nonce_' . sha1($audience . '|' . $nonce);
    }

    private function cache(): ?FrontendInterface
    {
        try {
            return $this->cacheManager->getCache(self::CACHE);
        } catch (\Throwable) {
            return null;
        }
    }
}
