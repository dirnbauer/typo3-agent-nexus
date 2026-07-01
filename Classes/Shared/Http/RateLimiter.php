<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Shared\Http;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\SingletonInterface;

/**
 * Per-IP fixed-window rate limiting for the frontend eID endpoints, one
 * implementation instead of six copies. Fails OPEN when the cache is
 * unavailable — these are demo endpoints; a broken cache must not take the
 * page down with them.
 */
final class RateLimiter implements SingletonInterface
{
    public function __construct(
        private readonly CacheManager $cacheManager,
    ) {}

    /**
     * @param string $cacheName one of the protocol caches (a2ui, agui, a2a, ucp, ap2)
     * @param string $bucket separates limits within one cache (e.g. 'default' vs 'llm')
     */
    public function passes(
        ServerRequestInterface $request,
        string $cacheName,
        int $limit,
        int $windowSeconds,
        string $bucket = 'default',
    ): bool {
        try {
            $cache = $this->cacheManager->getCache($cacheName);
        } catch (\Throwable) {
            return true;
        }

        $ip = (string)($request->getServerParams()['REMOTE_ADDR'] ?? 'unknown');
        $key = 'rl_' . $bucket . '_' . sha1($ip);
        $count = (int)$cache->get($key);
        if ($count >= $limit) {
            return false;
        }
        $cache->set($key, $count + 1, [], $windowSeconds);
        return true;
    }
}
