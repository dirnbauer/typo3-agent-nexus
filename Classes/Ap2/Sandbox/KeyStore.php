<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Ap2\Sandbox;

/**
 * Where the sandbox keys live between requests.
 */
interface KeyStore
{
    /**
     * Load the stored keys, let `$complete` add any that are missing, and
     * store the result when it changed — atomically, so two first requests do
     * not each create their own keys.
     *
     * @param \Closure(array<string, array{kid: string, pem: string}>): array<string, array{kid: string, pem: string}> $complete
     * @return array<string, array{kid: string, pem: string}> role value => key
     */
    public function keys(\Closure $complete): array;
}
