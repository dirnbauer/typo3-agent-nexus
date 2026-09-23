<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Tests\Unit\Ucp\Fixtures;

use TYPO3\CMS\Core\Cache\Backend\TransientMemoryBackend;

/**
 * A cache whose storage is gone: every read and write fails.
 */
final class UnavailableCacheBackend extends TransientMemoryBackend
{
    /**
     * @param array<string> $tags
     */
    public function set(string $entryIdentifier, mixed $data, array $tags = [], $lifetime = null): void
    {
        throw new \RuntimeException('The cache storage is unavailable.', 1758709201);
    }

    public function get(string $entryIdentifier): mixed
    {
        throw new \RuntimeException('The cache storage is unavailable.', 1758709202);
    }

    public function has(string $entryIdentifier): bool
    {
        throw new \RuntimeException('The cache storage is unavailable.', 1758709203);
    }
}
