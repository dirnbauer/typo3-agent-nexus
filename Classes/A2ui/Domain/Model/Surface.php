<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\A2ui\Domain\Model;

/**
 * A surface an agent wants a renderer to show: its components, its data model
 * and whether the renderer should send the data model back with every action.
 *
 * The surface itself is version-neutral; {@see \Webconsulting\AgentNexus\A2ui\Service\MessageBuilder}
 * turns it into the messages of one A2UI version — createSurface,
 * updateComponents and updateDataModel for v0.9.1, one createSurface with
 * everything inline for the v1.0 candidate. Structure and data stay apart: the
 * inputs bind to the data model with JSON Pointer paths.
 */
final readonly class Surface
{
    /**
     * @param list<Component> $components
     * @param array<string, mixed> $dataModel a JSON object
     * @param string $title                  a short name for lists, never sent to the renderer
     * @param array<string, string> $theme   v0.9.1 theme properties (primaryColor, iconUrl, agentDisplayName)
     */
    public function __construct(
        public string $surfaceId,
        public array $components,
        public array $dataModel = [],
        public bool $sendDataModel = true,
        public string $title = '',
        public array $theme = [],
    ) {}

    public function component(string $id): ?Component
    {
        return array_find($this->components, static fn(Component $component): bool => $component->id === $id);
    }

    public function root(): ?Component
    {
        return $this->component(Component::ROOT);
    }

    public function withSurfaceId(string $surfaceId): self
    {
        return new self($surfaceId, $this->components, $this->dataModel, $this->sendDataModel, $this->title, $this->theme);
    }

    /**
     * @param list<Component> $components
     * @param array<string, mixed> $dataModel
     */
    public function withContent(array $components, array $dataModel): self
    {
        return new self($this->surfaceId, $components, $dataModel, $this->sendDataModel, $this->title, $this->theme);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function componentsToArray(): array
    {
        return array_map(static fn(Component $component): array => $component->toArray(), $this->components);
    }
}
