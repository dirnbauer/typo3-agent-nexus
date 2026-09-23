<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\A2ui\Domain\Model;

/**
 * Writes a surface in the shape of the v0.9.1 basic catalogue, one component
 * at a time. Every method adds its component (and whatever the catalogue
 * needs with it — a Button's Text label, a field's starting value in the data
 * model) and returns the id, so containers can be written in reading order.
 *
 * Inputs bind to top-level keys of the data model: a field with the key
 * "email" reads and writes `/email`.
 */
final class SurfaceBuilder
{
    /** @var array<string, Component> */
    private array $components = [];

    /** @var array<string, mixed> */
    private array $dataModel = [];

    /** @var list<string> keys of the data model the inputs bind to, in order */
    private array $fields = [];

    /**
     * @param string|array<string, mixed> $text a literal, a binding or a function call
     */
    public function text(string $id, string|array $text, string $variant = 'body'): string
    {
        $properties = ['text' => $text];
        if ($variant !== 'body') {
            $properties['variant'] = $variant;
        }
        return $this->add($id, 'Text', $properties);
    }

    /**
     * @param list<array<string, mixed>> $checks
     */
    public function textField(string $key, string $label, string $variant = 'shortText', array $checks = [], string $initial = ''): string
    {
        $properties = ['label' => $label, 'value' => self::path($key)];
        if ($variant !== 'shortText') {
            $properties['variant'] = $variant;
        }
        if ($checks !== []) {
            $properties['checks'] = $checks;
        }
        return $this->input($key, 'TextField', $properties, $initial);
    }

    /**
     * @param list<array<string, mixed>> $checks
     */
    public function checkBox(string $key, string $label, bool $initial = false, array $checks = []): string
    {
        $properties = ['label' => $label, 'value' => self::path($key)];
        if ($checks !== []) {
            $properties['checks'] = $checks;
        }
        return $this->input($key, 'CheckBox', $properties, $initial);
    }

    /**
     * @param array<string, string> $options value => label
     * @param list<string> $initial
     * @param list<array<string, mixed>> $checks
     */
    public function choice(
        string $key,
        string $label,
        array $options,
        array $initial,
        bool $multiple = false,
        bool $chips = false,
        array $checks = [],
    ): string {
        $properties = ['label' => $label];
        if ($multiple) {
            $properties['variant'] = 'multipleSelection';
        }
        $properties['options'] = [];
        foreach ($options as $value => $optionLabel) {
            $properties['options'][] = ['label' => $optionLabel, 'value' => (string)$value];
        }
        $properties['value'] = self::path($key);
        if ($chips) {
            $properties['displayStyle'] = 'chips';
        }
        if ($checks !== []) {
            $properties['checks'] = $checks;
        }
        return $this->input($key, 'ChoicePicker', $properties, $initial);
    }

    public function slider(string $key, string $label, int|float $min, int|float $max, int|float $initial): string
    {
        return $this->input($key, 'Slider', [
            'label' => $label,
            'min' => $min,
            'max' => $max,
            'value' => self::path($key),
        ], $initial);
    }

    /**
     * @param list<array<string, mixed>> $checks
     */
    public function dateTime(string $key, string $label, bool $date, bool $time, array $checks = []): string
    {
        $properties = ['value' => self::path($key), 'enableDate' => $date, 'enableTime' => $time, 'label' => $label];
        if ($checks !== []) {
            $properties['checks'] = $checks;
        }
        return $this->input($key, 'DateTimeInput', $properties, '');
    }

    /**
     * A button and the Text component that labels it.
     *
     * @param array<string, mixed> $action
     * @param list<array<string, mixed>> $checks
     */
    public function button(string $id, string $label, array $action, string $variant = 'default', array $checks = []): string
    {
        $labelId = $this->text($id . '_label', $label);
        $properties = ['child' => $labelId];
        if ($variant !== 'default') {
            $properties['variant'] = $variant;
        }
        $properties['action'] = $action;
        if ($checks !== []) {
            $properties['checks'] = $checks;
        }
        return $this->add($id, 'Button', $properties);
    }

    public function icon(string $id, string $name, string $label = ''): string
    {
        $properties = ['name' => $name];
        if ($label !== '') {
            $properties = ['accessibility' => ['label' => $label]] + $properties;
        }
        return $this->add($id, 'Icon', $properties);
    }

    /**
     * @param list<string> $children
     */
    public function row(string $id, array $children, string $justify = 'start', string $align = 'stretch'): string
    {
        return $this->add($id, 'Row', $this->layout($children, $justify, $align));
    }

    /**
     * @param list<string> $children
     */
    public function column(string $id, array $children, string $justify = 'start', string $align = 'stretch'): string
    {
        return $this->add($id, 'Column', $this->layout($children, $justify, $align));
    }

    /**
     * A list that repeats one component for every item of a list in the data
     * model; inside it, paths without a leading slash resolve against the item.
     */
    public function list(string $id, string $path, string $componentId, string $direction = 'vertical'): string
    {
        $properties = ['children' => ['componentId' => $componentId, 'path' => $path]];
        if ($direction !== 'vertical') {
            $properties['direction'] = $direction;
        }
        return $this->add($id, 'List', $properties);
    }

    public function card(string $id, string $child): string
    {
        return $this->add($id, 'Card', ['child' => $child]);
    }

    /**
     * @param array<string, string> $tabs title => child id
     */
    public function tabs(string $id, array $tabs): string
    {
        $list = [];
        foreach ($tabs as $title => $child) {
            $list[] = ['title' => (string)$title, 'child' => $child];
        }
        return $this->add($id, 'Tabs', ['tabs' => $list]);
    }

    public function modal(string $id, string $trigger, string $content): string
    {
        return $this->add($id, 'Modal', ['trigger' => $trigger, 'content' => $content]);
    }

    public function divider(string $id, string $axis = 'horizontal'): string
    {
        return $this->add($id, 'Divider', $axis === 'horizontal' ? [] : ['axis' => $axis]);
    }

    /**
     * Any other catalogue component, written out.
     *
     * @param array<string, mixed> $properties
     */
    public function component(string $id, string $type, array $properties): string
    {
        return $this->add($id, $type, $properties);
    }

    /**
     * Set a value of the data model that no input writes (a list a template reads).
     */
    public function data(string $key, mixed $value): void
    {
        $this->dataModel[$key] = $value;
    }

    /**
     * The keys the inputs bind to, in the order they were added.
     *
     * @return list<string>
     */
    public function fields(): array
    {
        return $this->fields;
    }

    public function build(string $surfaceId, string $title): Surface
    {
        return new Surface($surfaceId, array_values($this->components), $this->dataModel, true, $title);
    }

    /**
     * @return array{path: string}
     */
    public static function path(string $key): array
    {
        return ['path' => '/' . $key];
    }

    /**
     * An event for the agent whose context carries the given data model keys.
     *
     * @param list<string> $keys
     * @return array{event: array<string, mixed>}
     */
    public static function event(string $name, array $keys = []): array
    {
        $event = ['name' => $name];
        $context = [];
        foreach ($keys as $key) {
            $context[$key] = self::path($key);
        }
        if ($context !== []) {
            $event['context'] = $context;
        }
        return ['event' => $event];
    }

    /**
     * A check that calls a catalogue function on the field's value.
     *
     * @param array<string, mixed> $arguments further arguments (pattern, min, max)
     * @return array{condition: array{call: string, args: array<string, mixed>}, message: string}
     */
    public static function check(string $function, string $key, string $message, array $arguments = []): array
    {
        return [
            'condition' => ['call' => $function, 'args' => ['value' => self::path($key)] + $arguments],
            'message' => $message,
        ];
    }

    /**
     * A check that passes when a boolean in the data model is true.
     *
     * @return array{condition: array{path: string}, message: string}
     */
    public static function isTrue(string $key, string $message): array
    {
        return ['condition' => self::path($key), 'message' => $message];
    }

    /**
     * @param list<string> $children
     * @return array<string, mixed>
     */
    private function layout(array $children, string $justify, string $align): array
    {
        $properties = ['children' => $children];
        if ($justify !== 'start') {
            $properties['justify'] = $justify;
        }
        if ($align !== 'stretch') {
            $properties['align'] = $align;
        }
        return $properties;
    }

    /**
     * @param array<string, mixed> $properties
     */
    private function input(string $key, string $type, array $properties, mixed $initial): string
    {
        $this->dataModel[$key] = $initial;
        $this->fields[] = $key;
        return $this->add($key, $type, $properties);
    }

    /**
     * @param array<string, mixed> $properties
     */
    private function add(string $id, string $type, array $properties): string
    {
        $this->components[$id] = new Component($id, $type, $properties);
        return $id;
    }
}
