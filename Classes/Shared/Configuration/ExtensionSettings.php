<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Shared\Configuration;

use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\SingletonInterface;

/**
 * Typed access to the extension configuration (ext_conf_template.txt).
 *
 * Every reader used to fetch the raw array and cast its own way, with its own
 * default. One class with one default per setting keeps ext_conf_template.txt,
 * the documentation and the code in step. An unreadable configuration (the
 * extension is not set up yet, a test without it) falls back to the defaults.
 */
final class ExtensionSettings implements SingletonInterface
{
    public const string EXTENSION_KEY = 'agent_nexus';

    /** @var array<string, mixed>|null */
    private ?array $settings = null;

    public function __construct(
        private readonly ExtensionConfiguration $extensionConfiguration,
    ) {}

    public function trafficEnabled(): bool
    {
        return $this->bool('trafficEnabled', true);
    }

    /** Days a traffic entry is kept; 0 keeps entries until they are deleted by hand. */
    public function trafficRetentionDays(): int
    {
        return max(0, $this->int('trafficRetentionDays', 14));
    }

    public function trafficCaptureBodies(): bool
    {
        return $this->bool('trafficCaptureBodies', true);
    }

    public function trafficRedactPersonalData(): bool
    {
        return $this->bool('trafficRedactPersonalData', true);
    }

    /** Days a protocol object (task, run, checkout, mandate) is kept; 0 = forever. */
    public function objectRetentionDays(): int
    {
        return max(0, $this->int('objectRetentionDays', 90));
    }

    /** Publish /.well-known/agent-card.json and /.well-known/ucp on this host. */
    public function publishWellKnown(): bool
    {
        return $this->bool('publishWellKnown', true);
    }

    /** Path prefix of the protocol API bindings, without a trailing slash. */
    public function apiBasePath(): string
    {
        $path = trim($this->string('apiBasePath', '/api/agent-nexus'));
        $path = '/' . trim($path, '/');
        return $path === '/' ? '/api/agent-nexus' : $path;
    }

    public function bool(string $key, bool $default): bool
    {
        $value = $this->all()[$key] ?? null;
        return match (true) {
            $value === null || $value === '' => $default,
            is_bool($value) => $value,
            is_int($value) => $value !== 0,
            is_string($value) => $value !== '0' && strtolower($value) !== 'false',
            default => $default,
        };
    }

    public function int(string $key, int $default): int
    {
        $value = $this->all()[$key] ?? null;
        return is_numeric($value) ? (int)$value : $default;
    }

    public function float(string $key, float $default): float
    {
        $value = $this->all()[$key] ?? null;
        return is_numeric($value) ? (float)$value : $default;
    }

    public function string(string $key, string $default): string
    {
        $value = $this->all()[$key] ?? null;
        return is_scalar($value) && (string)$value !== '' ? (string)$value : $default;
    }

    /**
     * @return array<string, mixed>
     */
    private function all(): array
    {
        if ($this->settings !== null) {
            return $this->settings;
        }
        try {
            $settings = $this->extensionConfiguration->get(self::EXTENSION_KEY);
        } catch (\Throwable) {
            $settings = [];
        }
        $normalised = [];
        if (is_array($settings)) {
            foreach ($settings as $key => $value) {
                $normalised[(string)$key] = $value;
            }
        }
        return $this->settings = $normalised;
    }
}
