<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\A2ui\Domain\Model;

/**
 * An A2UI v1.0 "surface": one self-contained message describing a UI an agent
 * wants the client to render.
 *
 * The wire format is a single `createSurface` envelope:
 *
 *   {
 *     "version": "v1.0",
 *     "createSurface": {
 *       "surfaceId": "...",
 *       "catalogId": "...",
 *       "sendDataModel": true,
 *       "components": [ ...flat list, one with id "root"... ],
 *       "dataModel": { ... }
 *     }
 *   }
 *
 * Structure (the components) is kept strictly separate from data (the dataModel),
 * which is the core idea of A2UI: inputs bind to the data model via JSON Pointer
 * paths and only sync back to the agent when an action fires.
 */
final class Surface
{
    public const VERSION = 'v1.0';
    public const DEFAULT_CATALOG = 'https://a2ui.org/specification/v1_0/catalogs/basic/catalog.json';
    public const ROOT_ID = 'root';

    /**
     * @param array<int, Component> $components Flat list of components (must contain exactly one with id "root")
     * @param array<string, mixed>  $dataModel  Initial application state addressed by JSON Pointer
     * @param array<string, mixed>  $surfaceProperties Extensible, framework-delegated styling/branding hints (v1.0)
     */
    public function __construct(
        private readonly string $surfaceId,
        private array $components = [],
        private readonly array $dataModel = [],
        private readonly bool $sendDataModel = true,
        private readonly string $catalogId = self::DEFAULT_CATALOG,
        private readonly array $surfaceProperties = [],
    ) {}

    public function getSurfaceId(): string
    {
        return $this->surfaceId;
    }

    public function getCatalogId(): string
    {
        return $this->catalogId;
    }

    /**
     * @return array<int, Component>
     */
    public function getComponents(): array
    {
        return $this->components;
    }

    public function getComponent(string $id): ?Component
    {
        foreach ($this->components as $component) {
            if ($component->getId() === $id) {
                return $component;
            }
        }
        return null;
    }

    public function getRoot(): ?Component
    {
        return $this->getComponent(self::ROOT_ID);
    }

    /**
     * @return array<string, mixed>
     */
    public function getDataModel(): array
    {
        return $this->dataModel;
    }

    public function withComponents(Component ...$components): self
    {
        $clone = clone $this;
        $clone->components = array_values($components);
        return $clone;
    }

    /**
     * Emit the canonical A2UI v1.0 `createSurface` message.
     *
     * @return array<string, mixed>
     */
    public function toMessage(): array
    {
        $createSurface = [
            'surfaceId' => $this->surfaceId,
            'catalogId' => $this->catalogId,
            'sendDataModel' => $this->sendDataModel,
            'components' => array_map(static fn(Component $c): array => $c->toArray(), $this->components),
            'dataModel' => (object)$this->dataModel,
        ];
        if ($this->surfaceProperties !== []) {
            $createSurface['surfaceProperties'] = $this->surfaceProperties;
        }

        return [
            'version' => self::VERSION,
            'createSurface' => $createSurface,
        ];
    }

    public function toJson(): string
    {
        return (string)json_encode($this->toMessage(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Parse a surface back from an A2UI v1.0 message (or a bare createSurface body).
     *
     * @param array<string, mixed> $message
     */
    public static function fromMessage(array $message): self
    {
        $body = $message['createSurface'] ?? $message;

        $components = [];
        foreach ((array)($body['components'] ?? []) as $componentData) {
            if (is_array($componentData)) {
                $components[] = Component::fromArray($componentData);
            }
        }

        return new self(
            (string)($body['surfaceId'] ?? 'surface'),
            $components,
            (array)($body['dataModel'] ?? []),
            (bool)($body['sendDataModel'] ?? true),
            (string)($body['catalogId'] ?? self::DEFAULT_CATALOG),
            (array)($body['surfaceProperties'] ?? []),
        );
    }
}
