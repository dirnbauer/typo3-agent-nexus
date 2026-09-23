<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\A2ui\Domain\Model;

/**
 * What the sanitiser made of a raw component list: components that conform to
 * the catalogue of one version, a data model that is a JSON object, and a note
 * for every change it had to make.
 */
final readonly class SanitizedSurface
{
    /**
     * @param list<Component> $components root first, then in the order they arrived
     * @param array<string, mixed> $dataModel
     * @param list<string> $notes
     */
    public function __construct(
        public array $components,
        public array $dataModel,
        public array $notes,
    ) {}

    /** Whether there is anything a renderer can start from. */
    public function isRenderable(): bool
    {
        return array_any($this->components, static fn(Component $component): bool => $component->id === Component::ROOT);
    }
}
