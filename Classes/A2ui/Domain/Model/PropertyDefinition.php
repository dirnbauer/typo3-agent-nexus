<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\A2ui\Domain\Model;

/**
 * One property of a catalogue component or one argument of a catalogue
 * function, as the official basic catalogue defines it.
 */
final readonly class PropertyDefinition
{
    /**
     * @param list<string> $values  the allowed values of an enum, in catalogue order
     * @param ?int $minimum          the minimum of an integer, or the minimum item count of a list
     */
    public function __construct(
        public string $name,
        public PropertyType $type,
        public bool $required = false,
        public array $values = [],
        public string|int|float|bool|null $default = null,
        public ?int $minimum = null,
    ) {}

    /**
     * The definition as the catalogue endpoint and screen show it.
     *
     * @return array{name: string, type: string, required: bool, values?: list<string>, default?: string|int|float|bool, minimum?: int}
     */
    public function toArray(): array
    {
        $data = ['name' => $this->name, 'type' => $this->type->value, 'required' => $this->required];
        if ($this->values !== []) {
            $data['values'] = $this->values;
        }
        if ($this->default !== null) {
            $data['default'] = $this->default;
        }
        if ($this->minimum !== null) {
            $data['minimum'] = $this->minimum;
        }
        return $data;
    }
}
