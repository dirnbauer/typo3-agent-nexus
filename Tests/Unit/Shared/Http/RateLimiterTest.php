<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Tests\Unit\Shared\Http;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;
use Webconsulting\AgentNexus\Shared\Http\RateLimiter;

final class RateLimiterTest extends UnitTestCase
{
    #[Test]
    public function requestsPassUntilTheLimitIsReachedAndAreRefusedAfterwards(): void
    {
        $subject = new RateLimiter($this->cacheManager($this->countingCache()));
        $request = $this->requestFrom('203.0.113.10');

        self::assertTrue($subject->passes($request, 'a2ui', 3, 60));
        self::assertTrue($subject->passes($request, 'a2ui', 3, 60));
        self::assertTrue($subject->passes($request, 'a2ui', 3, 60));
        self::assertFalse($subject->passes($request, 'a2ui', 3, 60), 'The fourth request is over the limit.');
    }

    #[Test]
    public function twoClientsGetSeparateBudgets(): void
    {
        $subject = new RateLimiter($this->cacheManager($this->countingCache()));

        self::assertTrue($subject->passes($this->requestFrom('203.0.113.10'), 'a2ui', 1, 60));
        self::assertFalse($subject->passes($this->requestFrom('203.0.113.10'), 'a2ui', 1, 60));
        self::assertTrue($subject->passes($this->requestFrom('198.51.100.7'), 'a2ui', 1, 60));
    }

    #[Test]
    public function bucketsAreCountedSeparatelyWithinOneCache(): void
    {
        $subject = new RateLimiter($this->cacheManager($this->countingCache()));
        $request = $this->requestFrom('203.0.113.10');

        self::assertTrue($subject->passes($request, 'a2ui', 1, 60));
        self::assertFalse($subject->passes($request, 'a2ui', 1, 60));
        self::assertTrue($subject->passes($request, 'a2ui.llm', 1, 60), 'An exhausted bucket must not block another one.');
    }

    #[Test]
    public function countingARequestDoesNotExtendTheWindow(): void
    {
        $now = 1_000;
        $subject = new RateLimiter($this->cacheManager($this->countingCache()), static function () use (&$now): int {
            return $now;
        });
        $request = $this->requestFrom('203.0.113.10');

        self::assertTrue($subject->passes($request, 'a2ui', 2, 60));
        $now = 1_050;
        self::assertTrue($subject->passes($request, 'a2ui', 2, 60));
        self::assertFalse($subject->passes($request, 'a2ui', 2, 60), 'The window that started at 1000 is full.');
        $now = 1_060;
        self::assertTrue($subject->passes($request, 'a2ui', 2, 60), 'The window ends 60 seconds after its first request, not after its last.');
    }

    #[Test]
    public function anEntryFromBeforeThisVersionStartsAFreshWindow(): void
    {
        $cache = $this->countingCache();
        $cache->set('rl_a2ui_' . sha1('203.0.113.10'), 99);
        $subject = new RateLimiter($this->cacheManager($cache));

        self::assertTrue($subject->passes($this->requestFrom('203.0.113.10'), 'a2ui', 1, 60));
    }

    #[Test]
    public function aBrokenCacheFailsOpenSoADemoPageStaysUp(): void
    {
        $cacheManager = self::createStub(CacheManager::class);
        $cacheManager->method('getCache')->willThrowException(new \RuntimeException('no such cache', 1751400100));

        $subject = new RateLimiter($cacheManager);

        self::assertTrue($subject->passes($this->requestFrom('203.0.113.10'), 'a2ui', 1, 60));
        self::assertTrue($subject->passes($this->requestFrom('203.0.113.10'), 'a2ui', 1, 60));
    }

    private function requestFrom(string $ip): ServerRequest
    {
        return new ServerRequest('https://example.org/', 'POST', null, [], ['REMOTE_ADDR' => $ip]);
    }

    /** An in-memory stand-in for a TYPO3 cache frontend. */
    private function countingCache(): FrontendInterface
    {
        /** @var \ArrayObject<string, mixed> $store */
        $store = new \ArrayObject();
        $cache = self::createStub(FrontendInterface::class);
        $cache->method('get')->willReturnCallback(
            static fn(string $entryIdentifier): mixed => $store[$entryIdentifier] ?? false,
        );
        $cache->method('set')->willReturnCallback(
            static function (string $entryIdentifier, mixed $data) use ($store): void {
                $store[$entryIdentifier] = $data;
            },
        );
        return $cache;
    }

    private function cacheManager(FrontendInterface $cache): CacheManager
    {
        $cacheManager = self::createStub(CacheManager::class);
        $cacheManager->method('getCache')->willReturn($cache);
        return $cacheManager;
    }
}
