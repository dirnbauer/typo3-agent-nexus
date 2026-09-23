<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\A2ui\Domain\Model;

/**
 * The A2UI versions Agent Nexus speaks.
 *
 * v0.9.1 is the current stable release (29 May 2026) and the default. v1.0 is
 * a release candidate that is still changing; a client asks for it explicitly,
 * by version or by advertising its capabilities.
 *
 * Both v0.9 and v0.9.1 messages carry a `version` of "v0.9" or "v0.9.1" and
 * share one catalogue id and one capabilities key ("v0.9"), so an incoming
 * "v0.9" is read as this implementation's v0.9.1. Agent Nexus always emits
 * "v0.9.1".
 */
enum A2uiVersion: string
{
    case V0_9_1 = 'v0.9.1';
    case V1_0 = 'v1.0';

    public const self DEFAULT = self::V0_9_1;

    /** The version a client sent on the wire, or null when this implementation does not speak it. */
    public static function fromWire(mixed $wire): ?self
    {
        return match ($wire) {
            'v0.9', 'v0.9.1' => self::V0_9_1,
            'v1.0' => self::V1_0,
            default => null,
        };
    }

    /** The id of the official basic catalogue of this version. */
    public function catalogId(): string
    {
        return match ($this) {
            self::V0_9_1 => 'https://a2ui.org/specification/v0_9/catalogs/basic/catalog.json',
            self::V1_0 => 'https://a2ui.org/specification/v1_0/catalogs/basic/catalog.json',
        };
    }

    /**
     * Catalogue ids a renderer may advertise for this version's basic
     * catalogue. The v0.9.1 prose spells the id with "v0_9_1" in places while
     * the catalogue itself says "v0_9"; both mean the same catalogue.
     *
     * @return list<string>
     */
    public function catalogIds(): array
    {
        return match ($this) {
            self::V0_9_1 => [$this->catalogId(), 'https://a2ui.org/specification/v0_9_1/catalogs/basic/catalog.json'],
            self::V1_0 => [$this->catalogId()],
        };
    }

    /** The key of this version inside a capabilities object. */
    public function capabilitiesKey(): string
    {
        return match ($this) {
            self::V0_9_1 => 'v0.9',
            self::V1_0 => 'v1.0',
        };
    }

    /** The transport metadata member a renderer describes itself in. */
    public function capabilitiesMember(): string
    {
        return match ($this) {
            self::V0_9_1 => 'a2uiClientCapabilities',
            self::V1_0 => 'a2uiRendererCapabilities',
        };
    }

    /** The transport metadata member that carries the data model when `sendDataModel` is on. */
    public function dataModelMember(): string
    {
        return match ($this) {
            self::V0_9_1 => 'a2uiClientDataModel',
            self::V1_0 => 'a2uiRendererDataModel',
        };
    }

    /** The URI of the A2UI extension for A2A. */
    public function extensionUri(): string
    {
        return 'https://a2ui.org/a2a-extension/a2ui/' . $this->value;
    }

    /** Whether a `version` a client sent belongs to this version. */
    public function accepts(mixed $wire): bool
    {
        return self::fromWire($wire) === $this;
    }

    public function isStable(): bool
    {
        return $this === self::V0_9_1;
    }

    /**
     * @return list<string>
     */
    public function wireVersions(): array
    {
        return match ($this) {
            self::V0_9_1 => ['v0.9', 'v0.9.1'],
            self::V1_0 => ['v1.0'],
        };
    }
}
