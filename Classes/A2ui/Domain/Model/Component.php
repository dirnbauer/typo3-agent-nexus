<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\A2ui\Domain\Model;

/**
 * A single node of an A2UI v1.0 surface.
 *
 * In A2UI v1.0 a surface is a *flat adjacency list*: every component carries a
 * unique `id`, names a `component` from the catalog, holds its own properties at
 * the top level, and references its children by their ids (not by nesting). The
 * renderer reconstructs the tree from this flat list starting at `id: "root"`.
 *
 * Example serialisation:
 *   {"id": "title", "component": "TextField", "label": "Page title", "value": {"path": "/title"}}
 *   {"id": "root",  "component": "Column", "children": ["title", "submit"]}
 */
final class Component
{
    /**
     * @param string               $id         Unique component id (exactly one component must use "root")
     * @param string               $component  Catalog component name, e.g. "TextField", "Button", "Column"
     * @param array<string, mixed> $properties Component properties (label, text, value-binding, options, ...)
     * @param array<int, string>   $children   Ids of child components (containers only)
     * @param array<string, mixed>|null $action Optional A2UI action ({event: {...}} or {functionCall: {...}})
     */
    public function __construct(
        private readonly string $id,
        private readonly string $component,
        private readonly array $properties = [],
        private readonly array $children = [],
        private readonly ?array $action = null,
    ) {}

    public function getId(): string
    {
        return $this->id;
    }

    public function getComponent(): string
    {
        return $this->component;
    }

    /**
     * @return array<string, mixed>
     */
    public function getProperties(): array
    {
        return $this->properties;
    }

    public function getProperty(string $key, mixed $default = null): mixed
    {
        return $this->properties[$key] ?? $default;
    }

    /**
     * Raw children: either a list of child ids, or an A2UI template descriptor
     * ({"path": "/items", "componentId": "tpl"}).
     *
     * @return array<int|string, mixed>
     */
    public function getChildren(): array
    {
        return $this->children;
    }

    /**
     * Child ids for static containers (empty for a template/List descriptor).
     *
     * @return array<int, string>
     */
    public function getChildIds(): array
    {
        return array_is_list($this->children) ? array_map(strval(...), $this->children) : [];
    }

    /**
     * The List template descriptor, if children is the templating form.
     *
     * @return array{path: string, componentId: string}|null
     */
    public function getChildrenTemplate(): ?array
    {
        if (isset($this->children['componentId'])) {
            return [
                'path' => (string)($this->children['path'] ?? ''),
                'componentId' => (string)$this->children['componentId'],
            ];
        }
        return null;
    }

    public function hasChildren(): bool
    {
        return $this->children !== [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getAction(): ?array
    {
        return $this->action;
    }

    /**
     * Serialise to the flat A2UI v1.0 component shape (properties live at the top level).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = ['id' => $this->id, 'component' => $this->component];
        // Properties are emitted at the top level alongside id/component, per the v1.0 catalog.
        $data += $this->properties;

        if ($this->children !== []) {
            // Preserve both shapes: a list of ids, or a template descriptor object.
            $data['children'] = $this->children;
        }
        if ($this->action !== null) {
            $data['action'] = $this->action;
        }

        return $data;
    }

    /**
     * Build a component from its flat A2UI v1.0 representation.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $id = (string)($data['id'] ?? '');
        $component = (string)($data['component'] ?? '');
        // Keep children raw: a list of ids, or a {path, componentId} template object.
        $children = is_array($data['children'] ?? null) ? $data['children'] : [];
        $action = isset($data['action']) && is_array($data['action']) ? $data['action'] : null;

        // Everything that is not a reserved key is a component property.
        $properties = $data;
        unset($properties['id'], $properties['component'], $properties['children'], $properties['action']);

        return new self($id, $component, $properties, $children, $action);
    }
}
