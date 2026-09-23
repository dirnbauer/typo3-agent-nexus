<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\A2ui\Domain\Model;

/**
 * One entry of a surface's flat component list.
 *
 * A surface is an adjacency list: every component has a unique id, names its
 * type from the catalogue and carries its properties at the top level; it
 * refers to other components by id (`child`, `children`, `trigger`,
 * `content`, `tabs[].child`) instead of nesting them. The renderer rebuilds
 * the tree from the component with the id "root".
 *
 *     {"id": "email", "component": "TextField", "label": "Email", "value": {"path": "/email"}}
 */
final readonly class Component
{
    public const string ROOT = 'root';

    /**
     * @param string $type                     the catalogue name, e.g. "TextField"
     * @param array<string, mixed> $properties everything but `id` and `component`
     */
    public function __construct(
        public string $id,
        public string $type,
        public array $properties = [],
    ) {}

    public function property(string $name): mixed
    {
        return $this->properties[$name] ?? null;
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->properties);
    }

    public function with(string $name, mixed $value): self
    {
        $properties = $this->properties;
        $properties[$name] = $value;
        return new self($this->id, $this->type, $properties);
    }

    public function without(string $name): self
    {
        $properties = $this->properties;
        unset($properties[$name]);
        return new self($this->id, $this->type, $properties);
    }

    public function withId(string $id): self
    {
        return new self($id, $this->type, $this->properties);
    }

    /**
     * The component in its wire shape: id and component first, then the properties.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['id' => $this->id, 'component' => $this->type] + $this->properties;
    }

    /**
     * A component from its wire shape; null when it has no id or no type.
     *
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): ?self
    {
        $id = $data['id'] ?? null;
        $type = $data['component'] ?? null;
        if (!is_string($id) || $id === '' || !is_string($type) || $type === '') {
            return null;
        }
        $properties = [];
        foreach ($data as $key => $value) {
            if ($key !== 'id' && $key !== 'component' && is_string($key)) {
                $properties[$key] = $value;
            }
        }
        return new self($id, $type, $properties);
    }
}
