<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Ap2\Sandbox;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * Keys that live only as long as the object: for tests, and for code that
 * needs a working key ring without a database.
 */
#[Exclude]
final class MemoryKeyStore implements KeyStore
{
    /** @var array<string, array{kid: string, pem: string}> */
    private array $keys = [];

    public function keys(\Closure $complete): array
    {
        return $this->keys = $complete($this->keys);
    }
}
