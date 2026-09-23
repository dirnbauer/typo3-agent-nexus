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
 * that reach a real model. A window starts with a client's first request and
 * ends `$windowSeconds` later, however many requests follow: the entry keeps
 * its end, so counting a request never extends it.
 *
 * Fails OPEN when the cache is unavailable — these are demo endpoints, and a
 * broken cache must not take the page down with them. The cache is in no flush
 * group, so clearing caches during a demo does not reset a visitor's budget.
 */
final readonly class RateLimiter implements SingletonInterface
{
    public const string CACHE = 'agentnexus';

    /**
     * @param (\Closure(): int)|null $clock the current Unix time; tests pass their own
     */
    public function __construct(
        private CacheManager $cacheManager,
        private ?\Closure $clock = null,
    ) {}

    public function passes(ServerRequestInterface $request, string $bucket, int $limit, int $windowSeconds): bool
    {
        $ip = (string)($request->getServerParams()['REMOTE_ADDR'] ?? 'unknown');
        $key = 'rl_' . preg_replace('/[^a-z0-9_]/i', '_', $bucket) . '_' . sha1($ip);
        try {
            $cache = $this->cacheManager->getCache(self::CACHE);
            $now = $this->now();
            $count = 0;
            $endsAt = $now + max(1, $windowSeconds);
            $window = $cache->get($key);
            if (is_array($window) && is_int($window['endsAt'] ?? null) && $window['endsAt'] > $now) {
                $count = is_int($window['count'] ?? null) ? $window['count'] : 0;
                $endsAt = $window['endsAt'];
            }
            if ($count >= $limit) {
                return false;
            }
            $cache->set($key, ['count' => $count + 1, 'endsAt' => $endsAt], [], $endsAt - $now);
        } catch (\Throwable) {
            // Unavailable, unreadable or unwritable: let the request through.
        }
        return true;
    }

    private function now(): int
    {
        return $this->clock !== null ? ($this->clock)() : time();
    }
}
