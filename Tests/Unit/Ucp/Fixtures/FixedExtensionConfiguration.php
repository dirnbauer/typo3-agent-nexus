<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Tests\Unit\Ucp\Fixtures;

use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

/**
 * An extension configuration that is exactly what the test says it is.
 */
final readonly class FixedExtensionConfiguration extends ExtensionConfiguration
{
    /**
     * @param array<string, mixed> $settings
     */
    public function __construct(
        private array $settings = [],
    ) {}

    public function get(string $extension, string $path = ''): mixed
    {
        return $path === '' ? $this->settings : ($this->settings[$path] ?? null);
    }
}
