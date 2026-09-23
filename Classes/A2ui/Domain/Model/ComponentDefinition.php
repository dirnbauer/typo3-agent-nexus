<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\A2ui\Domain\Model;

/**
 * One component of the official basic catalogue: its name, the properties it
 * takes beyond the common ones (id, accessibility, weight) and whether it
 * accepts validation checks.
 */
final readonly class ComponentDefinition
{
    public const string CATEGORY_DISPLAY = 'display';
    public const string CATEGORY_LAYOUT = 'layout';
    public const string CATEGORY_INPUT = 'input';
    public const string CATEGORY_INTERACTIVE = 'interactive';

    /** @var array<string, PropertyDefinition> */
    public array $properties;

    /**
     * @param list<PropertyDefinition> $properties
     */
    public function __construct(
        public string $name,
        public string $category,
        array $properties,
    ) {
        $indexed = [];
        foreach ($properties as $property) {
            $indexed[$property->name] = $property;
        }
        $this->properties = $indexed;
    }

    public function property(string $name): ?PropertyDefinition
    {
        return $this->properties[$name] ?? null;
    }

    /**
     * @return list<string>
     */
    public function requiredProperties(): array
    {
        $required = [];
        foreach ($this->properties as $property) {
            if ($property->required) {
                $required[] = $property->name;
            }
        }
        return $required;
    }

    public function isCheckable(): bool
    {
        return isset($this->properties['checks']);
    }

    /** Whether the component holds other components (a list of children or a single child). */
    public function isContainer(): bool
    {
        foreach ($this->properties as $property) {
            if ($property->type->isReference()) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return array{name: string, category: string, properties: list<array{name: string, type: string, required: bool, values?: list<string>, default?: string|int|float|bool, minimum?: int}>}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'category' => $this->category,
            'properties' => array_values(array_map(
                static fn(PropertyDefinition $property): array => $property->toArray(),
                $this->properties,
            )),
        ];
    }
}
