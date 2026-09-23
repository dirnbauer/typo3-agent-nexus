<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Tests\Unit\Ucp\Checkout;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Cache\Backend\TransientMemoryBackend;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\VariableFrontend;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;
use Webconsulting\AgentNexus\Tests\Unit\Ucp\Fixtures\UnavailableCacheBackend;
use Webconsulting\AgentNexus\Ucp\Checkout\IdempotencyRecord;
use Webconsulting\AgentNexus\Ucp\Checkout\IdempotencyStore;
use Webconsulting\AgentNexus\Ucp\Checkout\IdempotencyUnavailable;

final class IdempotencyStoreTest extends UnitTestCase
{
    /** The cache frontend registers a log manager. */
    protected bool $resetSingletonInstances = true;

    #[Test]
    public function aRecordIsFoundAgainUnderTheSameScopeAndKey(): void
    {
        $store = $this->store();
        $record = new IdempotencyRecord('create_checkout', '/api/agent-nexus/ucp/checkout-sessions', hash('sha256', '{}'), 201, '{"id":"chk_1"}', 'https://shop.example/x', 1);

        $store->remember('https://platform.example/.well-known/ucp', 'key-1', $record);

        self::assertEquals($record, $store->find('https://platform.example/.well-known/ucp', 'key-1'));
        self::assertNull($store->find('https://other.example/.well-known/ucp', 'key-1'), 'Keys are scoped to the platform that sent them.');
        self::assertNull($store->find('https://platform.example/.well-known/ucp', 'key-2'));
    }

    #[Test]
    public function aRecordMatchesOnlyTheSameOperationPathAndBody(): void
    {
        $record = new IdempotencyRecord('update_checkout', '/p', hash('sha256', 'a'), 200, '{}');

        self::assertTrue($record->matches('update_checkout', '/p', hash('sha256', 'a')));
        self::assertFalse($record->matches('update_checkout', '/p', hash('sha256', 'b')));
        self::assertFalse($record->matches('complete_checkout', '/p', hash('sha256', 'a')));
        self::assertFalse($record->matches('update_checkout', '/q', hash('sha256', 'a')));
    }

    #[Test]
    public function aStorageFailureIsNotSwallowed(): void
    {
        $cacheManager = new CacheManager();
        $cacheManager->registerCache(new VariableFrontend(IdempotencyStore::CACHE, new UnavailableCacheBackend()));
        $store = new IdempotencyStore($cacheManager);

        try {
            $store->find('scope', 'key');
            self::fail('A failing read must surface.');
        } catch (IdempotencyUnavailable) {
        }
        $this->expectException(IdempotencyUnavailable::class);
        $store->remember('scope', 'key', new IdempotencyRecord('cancel_checkout', '/p', '', 200, '{}'));
    }

    #[Test]
    public function aMissingCacheIsAStorageFailureToo(): void
    {
        $this->expectException(IdempotencyUnavailable::class);
        (new IdempotencyStore(new CacheManager()))->find('scope', 'key');
    }

    private function store(): IdempotencyStore
    {
        $cacheManager = new CacheManager();
        $cacheManager->registerCache(new VariableFrontend(IdempotencyStore::CACHE, new TransientMemoryBackend()));
        return new IdempotencyStore($cacheManager);
    }
}
