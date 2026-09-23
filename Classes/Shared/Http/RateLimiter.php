<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Shared\Http;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\SingletonInterface;

/**
 * Per-client fixed-window rate limiting for the public endpoints.
 *
 * Buckets are independent counters, conventionally `<protocol>` for a
 * protocol's requests and `<protocol>.llm` for the tighter budget of requests
 * that reach a real model. Fails OPEN when the cache is unavailable — these are
 * demo endpoints, and a broken cache must not take the page down with them.
 */
final readonly class RateLimiter implements SingletonInterface
{
    public const string CACHE = 'agentnexus';

    public function __construct(
        private CacheManager $cacheManager,
    ) {}

    public function passes(ServerRequestInterface $request, string $bucket, int $limit, int $windowSeconds): bool
    {
        $ip = (string)($request->getServerParams()['REMOTE_ADDR'] ?? 'unknown');
        $key = 'rl_' . preg_replace('/[^a-z0-9_]/i', '_', $bucket) . '_' . sha1($ip);
        try {
            $cache = $this->cacheManager->getCache(self::CACHE);
            $count = (int)$cache->get($key);
            if ($count >= $limit) {
                return false;
            }
            $cache->set($key, $count + 1, [], $windowSeconds);
        } catch (\Throwable) {
            // Unavailable, unreadable or unwritable: let the request through.
        }
        return true;
    }
}
