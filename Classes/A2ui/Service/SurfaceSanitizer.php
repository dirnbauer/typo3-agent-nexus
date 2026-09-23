<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\A2ui\Service;

use Webconsulting\AgentNexus\A2ui\Domain\Model\A2uiVersion;
use Webconsulting\AgentNexus\A2ui\Domain\Model\Component;
use Webconsulting\AgentNexus\A2ui\Domain\Model\ComponentDefinition;
use Webconsulting\AgentNexus\A2ui\Domain\Model\PropertyDefinition;
use Webconsulting\AgentNexus\A2ui\Domain\Model\PropertyType;
use Webconsulting\AgentNexus\A2ui\Domain\Model\SanitizedSurface;
use Webconsulting\AgentNexus\A2ui\Domain\Repository\ComponentRegistry;

/**
 * Forces a raw component list onto the official basic catalogue of one A2UI
 * version.
 *
 * Model output is never trusted as it comes: components outside the
 * catalogue are dropped, properties the catalogue does not define are removed,
 * values of the wrong shape are removed or repaired, references to missing
 * components are cut, cycles are broken, and anything the root cannot reach is
 * pruned. What remains validates against the catalogue schema.
 *
 * It also repairs the shapes a model trained on older A2UI drafts tends to
 * produce — a `Textarea`, a `ButtonGroup`, a Button with a `text`, a Card with
 * a `title`, `required: true` on a field, `wantResponse` on an event — and
 * converts between the versions (a v0.9.1 `h2` Text becomes a Markdown
 * heading in v1.0, which has no heading variants). Every change is noted, so
 * the playground can show what the model got wrong.
 */
final class SurfaceSanitizer
{
    public const int MAX_COMPONENTS = 150;
    public const int MAX_TEXT = 4000;
    public const int MAX_DATA_MODEL_BYTES = 65536;
    private const string ID_PATTERN = '/^[A-Za-z0-9_][A-Za-z0-9_.:-]{0,63}$/';
    private const string SVG_PATH_PATTERN = '/^[MmLlHhVvCcSsQqTtAaZz0-9eE\s,.+-]{1,4000}$/';

    /** Default check messages, by function. */
    private const array CHECK_MESSAGES = [
        'required' => 'This field is required.',
        'email' => 'Enter a valid email address.',
        'regex' => 'Check the format of this field.',
        'length' => 'Check the length of this field.',
        'numeric' => 'Enter a number in the allowed range.',
    ];

    /** Icon names of older drafts, mapped to the catalogue. */
    private const array ICON_ALIASES = [
        'user' => 'person', 'calendar' => 'calendarToday', 'clock' => 'event', 'arrow' => 'arrowForward',
        'arrowRight' => 'arrowForward', 'arrowLeft' => 'arrowBack', 'email' => 'mail', 'trash' => 'delete',
        'pencil' => 'edit', 'cart' => 'shoppingCart', 'location' => 'locationOn', 'bell' => 'notifications',
        'eye' => 'visibility', 'gear' => 'settings', 'heart' => 'favorite', 'plus' => 'add', 'x' => 'close',
    ];

    public function __construct(
        private readonly ComponentRegistry $registry,
    ) {}

    public function sanitize(mixed $components, mixed $dataModel, A2uiVersion $version): SanitizedSurface
    {
        $notes = [];
        $list = is_array($components) ? array_values($components) : [];
        if (!is_array($components)) {
            $notes[] = 'The components were not a list.';
        }
        if (count($list) > self::MAX_COMPONENTS) {
            $notes[] = sprintf('Only the first %d components were kept.', self::MAX_COMPONENTS);
            $list = array_slice($list, 0, self::MAX_COMPONENTS);
        }

        /** @var array<string, Component> $byId */
        $byId = [];
        $namedRoot = false;
        foreach ($list as $index => $raw) {
            if (!is_array($raw)) {
                $notes[] = sprintf('Ignored entry %d: it is not a component object.', $index);
                continue;
            }
            $namedRoot = $namedRoot || ($raw['id'] ?? null) === Component::ROOT;
            foreach ($this->normalise($raw, $version, $notes) as $component) {
                if (isset($byId[$component->id])) {
                    $notes[] = sprintf('Dropped a second component with the id "%s".', $component->id);
                    continue;
                }
                $byId[$component->id] = $component;
            }
        }

        $byId = $this->resolveReferences($byId, $version, $notes);
        $byId = $this->ensureRoot($byId, $namedRoot, $version, $notes);
        $components = isset($byId[Component::ROOT]) ? $this->prune($byId, $version, $notes) : [];

        return new SanitizedSurface($components, $this->dataModel($dataModel, $notes), $notes);
    }

    /**
     * One raw component in, the clean component plus any it had to create out.
     *
     * @param array<array-key, mixed> $raw
     * @param list<string> $notes
     * @return list<Component>
     */
    private function normalise(array $raw, A2uiVersion $version, array &$notes): array
    {
        $id = $raw['id'] ?? null;
        $type = $raw['component'] ?? null;
        if (!is_string($type) || $type === '') {
            $notes[] = 'Dropped an entry without a component type.';
            return [];
        }
        if (!is_string($id) || preg_match(self::ID_PATTERN, $id) !== 1) {
            $notes[] = sprintf('Dropped a %s with a missing or invalid id.', $type);
            return [];
        }
        $properties = [];
        foreach ($raw as $key => $value) {
            if (is_string($key) && $key !== 'id' && $key !== 'component') {
                $properties[$key] = $value;
            }
        }

        $generated = [];
        [$type, $properties] = $this->migrate($id, $type, $properties, $version, $generated, $notes);

        $definition = $this->registry->component($type, $version);
        if ($definition === null) {
            $notes[] = sprintf('Dropped "%s" (%s): it is not in the %s basic catalogue.', $id, $type, $version->value);
            return [];
        }

        $clean = [];
        $removed = [];
        foreach ($properties as $name => $value) {
            if ($name === 'accessibility') {
                $accessibility = $this->accessibility($value, $version);
                if ($accessibility !== null) {
                    $clean['accessibility'] = $accessibility;
                }
                continue;
            }
            if ($name === 'weight') {
                if (is_int($value) || is_float($value)) {
                    $clean['weight'] = $value;
                } else {
                    $removed[] = $name;
                }
                continue;
            }
            $property = $definition->property($name);
            if ($property === null) {
                $removed[] = $name;
                continue;
            }
            $sanitised = $name === 'checks'
                ? $this->checks($value, $properties, $version)
                : $this->value($value, $property, $version);
            if ($sanitised === null) {
                $notes[] = sprintf('Removed the invalid "%s" of %s "%s".', $name, $type, $id);
                continue;
            }
            $clean[$name] = $sanitised[0];
        }
        if ($removed !== []) {
            $notes[] = sprintf('Removed from %s "%s" what the %s catalogue does not define: %s.', $type, $id, $version->value, implode(', ', $removed));
        }

        foreach ($definition->requiredProperties() as $required) {
            if (array_key_exists($required, $clean)) {
                continue;
            }
            $default = $this->defaultFor($type, $required, $id);
            if ($default === null) {
                $notes[] = sprintf('Dropped %s "%s": it has no "%s".', $type, $id, $required);
                return [];
            }
            $clean[$required] = $default[0];
            $notes[] = sprintf('Filled in the missing "%s" of %s "%s".', $required, $type, $id);
        }

        return [new Component($id, $type, $this->ordered($clean, $definition)), ...$generated];
    }

    /**
     * Shapes of older A2UI drafts, turned into the catalogue's.
     *
     * @param array<string, mixed> $properties
     * @param list<Component> $generated components created on the way (a Button's label, a Card's column)
     * @param list<string> $notes
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function migrate(string $id, string $type, array $properties, A2uiVersion $version, array &$generated, array &$notes): array
    {
        if ($type === 'Textarea') {
            $notes[] = sprintf('Turned the Textarea "%s" into a TextField with the longText variant.', $id);
            $type = 'TextField';
            $properties['variant'] = 'longText';
        }
        if ($type === 'ButtonGroup') {
            $notes[] = sprintf('Turned the ButtonGroup "%s" into a ChoicePicker shown as chips.', $id);
            $type = 'ChoicePicker';
            $properties['displayStyle'] = 'chips';
        }

        switch ($type) {
            case 'Text':
                $variant = $properties['variant'] ?? null;
                if ($variant === 'lead') {
                    $properties['variant'] = 'body';
                } elseif ($variant === 'muted') {
                    $properties['variant'] = 'caption';
                } elseif ($variant === 'h6') {
                    $properties['variant'] = 'h5';
                }
                $variant = $properties['variant'] ?? null;
                if ($version === A2uiVersion::V1_0 && is_string($variant) && preg_match('/^h([1-5])$/', $variant, $level) === 1) {
                    unset($properties['variant']);
                    if (is_string($properties['text'] ?? null)) {
                        $properties['text'] = str_repeat('#', (int)$level[1]) . ' ' . ltrim($properties['text'], '# ');
                    }
                }
                unset($properties['align']);
                break;

            case 'Image':
            case 'Video':
            case 'AudioPlayer':
                if (!isset($properties['url']) && isset($properties['src'])) {
                    $properties['url'] = $properties['src'];
                }
                if (!isset($properties['description']) && isset($properties['alt']) && $type !== 'Video') {
                    $properties['description'] = $properties['alt'];
                }
                if ($type === 'Video' && $version === A2uiVersion::V1_0 && !isset($properties['posterUrl']) && isset($properties['poster'])) {
                    $properties['posterUrl'] = $properties['poster'];
                }
                unset($properties['src'], $properties['alt'], $properties['poster']);
                break;

            case 'Card':
                if (!isset($properties['child'])) {
                    $children = array_values(array_filter(
                        is_array($properties['children'] ?? null) ? $properties['children'] : [],
                        is_string(...),
                    ));
                    $title = is_string($properties['title'] ?? null) ? $properties['title'] : '';
                    $subtitle = is_string($properties['subtitle'] ?? null) ? $properties['subtitle'] : '';
                    if ($title !== '') {
                        $generated[] = $this->heading($id . '_title', $title, 3, $version);
                        array_unshift($children, $id . '_title');
                    }
                    if ($subtitle !== '') {
                        $generated[] = new Component($id . '_subtitle', 'Text', ['text' => $subtitle, 'variant' => 'caption']);
                        array_splice($children, $title !== '' ? 1 : 0, 0, [$id . '_subtitle']);
                    }
                    if (count($children) === 1) {
                        $properties['child'] = $children[0];
                    } elseif ($children !== []) {
                        $generated[] = new Component($id . '_content', 'Column', ['children' => $children]);
                        $properties['child'] = $id . '_content';
                    }
                }
                unset($properties['title'], $properties['subtitle'], $properties['children']);
                break;

            case 'Button':
                if (!isset($properties['child'])) {
                    $label = $properties['text'] ?? $properties['label'] ?? null;
                    if (is_string($label) && $label !== '') {
                        $generated[] = new Component($id . '_label', 'Text', ['text' => $label]);
                        $properties['child'] = $id . '_label';
                    }
                }
                $variant = $properties['variant'] ?? null;
                if (is_string($variant) && !in_array($variant, ['default', 'primary', 'borderless'], true)) {
                    $properties['variant'] = match ($variant) {
                        'success' => 'primary',
                        'link', 'ghost', 'text', 'tertiary' => 'borderless',
                        default => 'default',
                    };
                }
                unset($properties['text'], $properties['label'], $properties['icon'], $properties['disabled']);
                break;

            case 'TextField':
                $checks = is_array($properties['checks'] ?? null) ? array_values($properties['checks']) : [];
                $inputType = $properties['inputType'] ?? null;
                if (($properties['required'] ?? false) === true) {
                    array_unshift($checks, ['type' => 'required']);
                }
                if ($inputType === 'email') {
                    $checks[] = ['type' => 'email'];
                }
                if (!isset($properties['variant'])) {
                    $properties['variant'] = match (true) {
                        $inputType === 'number' => 'number',
                        $inputType === 'password' => 'obscured',
                        isset($properties['rows']), ($properties['multiline'] ?? false) === true => 'longText',
                        default => 'shortText',
                    };
                }
                $maxLength = $properties['maxlength'] ?? $properties['maxLength'] ?? null;
                if (is_int($maxLength) && $maxLength > 0) {
                    $checks[] = ['type' => 'length', 'max' => $maxLength];
                }
                if ($version === A2uiVersion::V1_0 && is_string($properties['validationRegexp'] ?? null)) {
                    $checks[] = ['type' => 'regex', 'pattern' => $properties['validationRegexp']];
                }
                if ($checks !== []) {
                    $properties['checks'] = $checks;
                }
                unset(
                    $properties['required'],
                    $properties['inputType'],
                    $properties['rows'],
                    $properties['multiline'],
                    $properties['maxlength'],
                    $properties['maxLength'],
                    $properties['helpText'],
                    $properties['disabled'],
                );
                if ($version === A2uiVersion::V1_0) {
                    unset($properties['validationRegexp']);
                }
                break;

            case 'ChoicePicker':
                if (!isset($properties['variant']) && ($properties['multiple'] ?? false) === true) {
                    $properties['variant'] = 'multipleSelection';
                }
                $value = $properties['value'] ?? null;
                if (is_string($value)) {
                    $properties['value'] = [$value];
                }
                if (($properties['required'] ?? false) === true) {
                    $checks = is_array($properties['checks'] ?? null) ? array_values($properties['checks']) : [];
                    array_unshift($checks, ['type' => 'required']);
                    $properties['checks'] = $checks;
                }
                unset($properties['multiple'], $properties['required'], $properties['helpText']);
                break;

            case 'CheckBox':
                if (!array_key_exists('value', $properties) && is_bool($properties['checked'] ?? null)) {
                    $properties['value'] = $properties['checked'];
                }
                unset($properties['checked'], $properties['helpText']);
                break;

            case 'DateTimeInput':
                $mode = $properties['mode'] ?? null;
                if (is_string($mode) && !isset($properties['enableDate']) && !isset($properties['enableTime'])) {
                    $properties['enableDate'] = $mode !== 'time';
                    $properties['enableTime'] = $mode === 'time' || $mode === 'datetime';
                }
                unset($properties['mode'], $properties['required'], $properties['helpText']);
                break;

            case 'Tabs':
                $titles = $properties['tabs'] ?? null;
                $children = $properties['children'] ?? null;
                if (is_array($titles) && is_array($children) && array_is_list($titles) && array_all($titles, is_string(...))) {
                    $tabs = [];
                    foreach (array_values($children) as $index => $child) {
                        if (is_string($child)) {
                            $tabs[] = ['title' => $titles[$index] ?? ('Tab ' . ($index + 1)), 'child' => $child];
                        }
                    }
                    $properties['tabs'] = $tabs;
                }
                unset($properties['children']);
                break;

            case 'Modal':
                if (!isset($properties['content']) && is_array($properties['children'] ?? null)) {
                    $children = array_values(array_filter($properties['children'], is_string(...)));
                    if ($children !== []) {
                        $generated[] = new Component($id . '_content', 'Column', ['children' => $children]);
                        $properties['content'] = $id . '_content';
                    }
                }
                if (!isset($properties['trigger']) && isset($properties['content'])) {
                    $title = is_string($properties['title'] ?? null) && $properties['title'] !== '' ? $properties['title'] : 'Open';
                    $generated[] = new Component($id . '_trigger_label', 'Text', ['text' => $title]);
                    $generated[] = new Component($id . '_trigger', 'Button', [
                        'child' => $id . '_trigger_label',
                        'action' => ['event' => ['name' => 'openModal']],
                    ]);
                    $properties['trigger'] = $id . '_trigger';
                }
                unset($properties['title'], $properties['open'], $properties['children']);
                break;
        }

        return [$type, $properties];
    }

    /**
     * A required property a component may do without: an empty value the
     * renderer fills in, never invented content.
     *
     * @return array{0: mixed}|null
     */
    private function defaultFor(string $type, string $property, string $id): ?array
    {
        return match ($type . '.' . $property) {
            'CheckBox.value' => [false],
            'ChoicePicker.value' => [[]],
            'DateTimeInput.value' => [''],
            'Slider.max' => [100],
            'Row.children', 'Column.children', 'List.children' => [[]],
            'TextField.label', 'CheckBox.label' => [ucfirst(strtolower(trim((string)preg_replace('/(?<=[a-z])(?=[A-Z])|[_\-.]+/', ' ', $id))))],
            default => null,
        };
    }

    /**
     * @return array{0: mixed}|null the clean value wrapped, or null when it cannot be used
     */
    private function value(mixed $value, PropertyDefinition $property, A2uiVersion $version): ?array
    {
        return match ($property->type) {
            PropertyType::DynamicString => $this->dynamicString($value, $version),
            PropertyType::DynamicNumber => $this->dynamicNumber($value, $version),
            PropertyType::DynamicBoolean => $this->dynamicBoolean($value, $version),
            PropertyType::DynamicStringList => $this->dynamicStringList($value, $version),
            PropertyType::DateTime => $this->dateTime($value, $version),
            PropertyType::ComponentId => is_string($value) && preg_match(self::ID_PATTERN, $value) === 1 ? [$value] : null,
            PropertyType::ChildList => $this->childList($value),
            PropertyType::Action => $this->action($value, $version),
            PropertyType::Checks => $this->checks($value, [], $version),
            PropertyType::Enum => is_string($value) && in_array($value, $property->values, true) ? [$value] : null,
            PropertyType::String => is_string($value) ? [mb_substr($value, 0, self::MAX_TEXT)] : null,
            PropertyType::Number => is_int($value) || is_float($value) ? [$value] : (is_numeric($value) ? [0 + $value] : null),
            PropertyType::Integer => $this->integer($value, $property->minimum),
            PropertyType::Boolean => is_bool($value) ? [$value] : ($value === 'true' ? [true] : ($value === 'false' ? [false] : null)),
            PropertyType::IconName => $this->iconName($value, $version),
            PropertyType::Tabs => $this->tabs($value, $version, $property->minimum ?? 1),
            PropertyType::Options => $this->options($value, $version),
            PropertyType::Accessibility => ($accessibility = $this->accessibility($value, $version)) !== null ? [$accessibility] : null,
            PropertyType::Any => $this->anyValue($value, $version),
            PropertyType::DynamicValue => $this->dynamicValue($value, $version),
            PropertyType::Uri => $this->uri($value),
            PropertyType::DynamicUri => $this->uri($value) ?? $this->dynamic($value, ['string'], $version),
            PropertyType::BooleanList => $this->booleanList($value, $version, $property->minimum ?? 2),
            PropertyType::Object => null,
        };
    }

    /**
     * @return array{0: mixed}|null
     */
    private function dynamicString(mixed $value, A2uiVersion $version): ?array
    {
        if (is_string($value)) {
            return [mb_substr($value, 0, self::MAX_TEXT)];
        }
        if (is_int($value) || is_float($value)) {
            return [(string)$value];
        }
        return $this->dynamic($value, ['string'], $version);
    }

    /**
     * @return array{0: mixed}|null
     */
    private function dynamicNumber(mixed $value, A2uiVersion $version): ?array
    {
        if (is_int($value) || is_float($value)) {
            return [$value];
        }
        if (is_string($value) && is_numeric($value)) {
            return [0 + $value];
        }
        return $this->dynamic($value, ['number'], $version);
    }

    /**
     * @return array{0: mixed}|null
     */
    private function dynamicBoolean(mixed $value, A2uiVersion $version): ?array
    {
        if (is_bool($value)) {
            return [$value];
        }
        return $this->dynamic($value, ['boolean', 'validationResult'], $version);
    }

    /**
     * @return array{0: mixed}|null
     */
    private function dynamicStringList(mixed $value, A2uiVersion $version): ?array
    {
        if (is_array($value) && array_is_list($value)) {
            $strings = [];
            foreach ($value as $item) {
                if (is_string($item) || is_int($item) || is_float($item)) {
                    $strings[] = (string)$item;
                }
            }
            return [$strings];
        }
        return $this->dynamic($value, ['array'], $version);
    }

    /**
     * An ISO 8601 date, time or date-time as JSON Schema's formats define them,
     * or a binding.
     *
     * @return array{0: mixed}|null
     */
    private function dateTime(mixed $value, A2uiVersion $version): ?array
    {
        if (is_string($value)) {
            $time = '\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:\d{2})';
            return preg_match('/^(\d{4}-\d{2}-\d{2}|' . $time . '|\d{4}-\d{2}-\d{2}T' . $time . ')$/', $value) === 1 ? [$value] : null;
        }
        return $this->dynamic($value, ['string'], $version);
    }

    /**
     * A data binding or a function call whose result fits the property.
     *
     * @param list<string> $returnTypes
     * @return array{0: array<string, mixed>}|null
     */
    private function dynamic(mixed $value, array $returnTypes, A2uiVersion $version): ?array
    {
        if (!is_array($value)) {
            return null;
        }
        if (isset($value['path'])) {
            return $this->binding($value);
        }
        if (isset($value['call'])) {
            $call = $this->functionCall($value, $returnTypes, $version);
            return $call !== null ? [$call] : null;
        }
        return null;
    }

    /**
     * @param array<array-key, mixed> $value
     * @return array{0: array{path: string}}|null
     */
    private function binding(array $value): ?array
    {
        $path = $value['path'] ?? null;
        if (!is_string($path) || $path === '' || mb_strlen($path) > 512) {
            return null;
        }
        return [['path' => $path]];
    }

    /**
     * A call of a catalogue function with arguments the catalogue defines.
     *
     * @param array<array-key, mixed> $value
     * @param list<string> $returnTypes what the calling property accepts
     * @return array<string, mixed>|null
     */
    private function functionCall(array $value, array $returnTypes, A2uiVersion $version): ?array
    {
        $name = $value['call'] ?? null;
        if (!is_string($name)) {
            return null;
        }
        $definition = $this->registry->function($name, $version);
        if ($definition === null || !in_array($definition->returnType, $returnTypes, true)) {
            return null;
        }
        $arguments = is_array($value['args'] ?? null) ? $value['args'] : [];
        $clean = [];
        foreach ($definition->arguments as $argumentName => $argument) {
            if (!array_key_exists($argumentName, $arguments)) {
                if ($argument->required) {
                    return null;
                }
                continue;
            }
            $sanitised = $this->value($arguments[$argumentName], $argument, $version);
            if ($sanitised === null) {
                if ($argument->required) {
                    return null;
                }
                continue;
            }
            $clean[$argumentName] = $sanitised[0];
        }
        if ($definition->oneOfRequired !== [] && !array_any($definition->oneOfRequired, static fn(string $argument): bool => array_key_exists($argument, $clean))) {
            return null;
        }
        $call = ['call' => $name];
        if ($clean !== []) {
            $call['args'] = $clean;
        } elseif ($name !== ComponentRegistry::INDEX_FUNCTION) {
            return null;
        }
        return $call;
    }

    /**
     * @return array{0: mixed}|null
     */
    private function childList(mixed $value): ?array
    {
        if (!is_array($value)) {
            return null;
        }
        if (array_is_list($value)) {
            $ids = [];
            foreach ($value as $id) {
                if (is_string($id) && preg_match(self::ID_PATTERN, $id) === 1) {
                    $ids[] = $id;
                }
            }
            return [$ids];
        }
        $componentId = $value['componentId'] ?? null;
        $path = $value['path'] ?? null;
        if (is_string($componentId) && preg_match(self::ID_PATTERN, $componentId) === 1 && is_string($path) && $path !== '') {
            return [['componentId' => $componentId, 'path' => $path]];
        }
        return null;
    }

    /**
     * An event for the agent, or a local function call. Keys of older drafts
     * (`wantResponse`, `actionId`) do not survive.
     *
     * @return array{0: array<string, mixed>}|null
     */
    private function action(mixed $value, A2uiVersion $version): ?array
    {
        if (!is_array($value)) {
            return null;
        }
        $event = $value['event'] ?? null;
        if (is_array($event)) {
            $name = $event['name'] ?? null;
            if (!is_string($name) || trim($name) === '' || mb_strlen($name) > 128) {
                return null;
            }
            $clean = ['name' => trim($name)];
            if ($version === A2uiVersion::V1_0 && isset($event['userMessage'])) {
                $message = $this->dynamicString($event['userMessage'], $version);
                if ($message !== null) {
                    $clean['userMessage'] = $message[0];
                }
            }
            $context = [];
            foreach (is_array($event['context'] ?? null) ? $event['context'] : [] as $key => $contextValue) {
                $sanitised = is_string($key) && $key !== '' ? $this->dynamicValue($contextValue, $version) : null;
                if ($sanitised !== null) {
                    $context[$key] = $sanitised[0];
                }
            }
            if ($context !== []) {
                $clean['context'] = $context;
            }
            return [['event' => $clean]];
        }
        $call = $value['functionCall'] ?? null;
        if (is_array($call)) {
            $clean = $this->functionCall($call, ['void'], $version);
            return $clean !== null ? [['functionCall' => $clean]] : null;
        }
        return null;
    }

    /**
     * Validation rules in the catalogue shape `{condition, message}`. The
     * shape of the upstream prose examples (`{call, args, message}`) and the
     * `{type: "required"}` rules of older drafts are converted; the latter
     * need the component's own value binding.
     *
     * @param array<string, mixed> $component the component's other properties
     * @return array{0: list<array<string, mixed>>}|null
     */
    private function checks(mixed $value, array $component, A2uiVersion $version): ?array
    {
        if (!is_array($value) || !array_is_list($value)) {
            return null;
        }
        $binding = is_array($component['value'] ?? null) ? $this->binding($component['value']) : null;
        $rules = [];
        foreach ($value as $rule) {
            if (!is_array($rule)) {
                continue;
            }
            $message = is_string($rule['message'] ?? null) ? trim($rule['message']) : '';
            if ($message === '' && is_string($rule['error'] ?? null)) {
                $message = trim($rule['error']);
            }
            $condition = null;
            if (array_key_exists('condition', $rule)) {
                $condition = $rule['condition'];
            } elseif (isset($rule['call'])) {
                $condition = ['call' => $rule['call'], 'args' => $rule['args'] ?? []];
            } elseif (is_string($rule['type'] ?? null) && $binding !== null) {
                $arguments = ['value' => $binding[0]];
                foreach (['min', 'max', 'pattern'] as $argument) {
                    if (isset($rule[$argument])) {
                        $arguments[$argument] = $rule[$argument];
                    }
                }
                $condition = ['call' => $rule['type'], 'args' => $arguments];
            }

            $clean = $version === A2uiVersion::V1_0
                ? $this->dynamic($condition, ['boolean', 'validationResult'], $version)
                : $this->dynamicBoolean($condition, $version);
            if ($clean === null || is_bool($clean[0])) {
                continue;
            }
            $function = is_array($clean[0]) && is_string($clean[0]['call'] ?? null) ? $clean[0]['call'] : '';
            if ($message === '') {
                $message = self::CHECK_MESSAGES[$function] ?? 'Check this field.';
            }
            $rules[] = ['condition' => $clean[0], 'message' => mb_substr($message, 0, 500)];
        }
        return $rules !== [] ? [$rules] : null;
    }

    /**
     * @return array{0: mixed}|null
     */
    private function iconName(mixed $value, A2uiVersion $version): ?array
    {
        if (is_string($value)) {
            $name = self::ICON_ALIASES[$value] ?? $value;
            return in_array($name, ComponentRegistry::ICON_NAMES, true) ? [$name] : null;
        }
        if (!is_array($value)) {
            return null;
        }
        if (isset($value['svgPath'])) {
            $path = $value['svgPath'];
            if (is_string($path) && preg_match(self::SVG_PATH_PATTERN, $path) === 1) {
                return [['svgPath' => $path]];
            }
            if ($version === A2uiVersion::V1_0 && is_array($path) && ($binding = $this->binding($path)) !== null) {
                return [['svgPath' => $binding[0]]];
            }
            return null;
        }
        return isset($value['path']) ? $this->binding($value) : null;
    }

    /**
     * @return array{0: list<array{title: mixed, child: string}>}|null
     */
    private function tabs(mixed $value, A2uiVersion $version, int $minimum): ?array
    {
        if (!is_array($value) || !array_is_list($value)) {
            return null;
        }
        $tabs = [];
        foreach ($value as $tab) {
            if (!is_array($tab)) {
                continue;
            }
            $title = $this->dynamicString($tab['title'] ?? null, $version);
            $child = $tab['child'] ?? null;
            if ($title !== null && is_string($child) && preg_match(self::ID_PATTERN, $child) === 1) {
                $tabs[] = ['title' => $title[0], 'child' => $child];
            }
        }
        return count($tabs) >= $minimum ? [$tabs] : null;
    }

    /**
     * @return array{0: list<array{label: mixed, value: string}>}|null
     */
    private function options(mixed $value, A2uiVersion $version): ?array
    {
        if (!is_array($value) || !array_is_list($value)) {
            return null;
        }
        $options = [];
        foreach ($value as $option) {
            if (is_string($option) || is_int($option) || is_float($option)) {
                $options[] = ['label' => (string)$option, 'value' => (string)$option];
                continue;
            }
            if (!is_array($option)) {
                continue;
            }
            $optionValue = $option['value'] ?? null;
            $label = $this->dynamicString($option['label'] ?? $optionValue, $version);
            if ($label !== null && (is_string($optionValue) || is_int($optionValue) || is_float($optionValue))) {
                $options[] = ['label' => $label[0], 'value' => (string)$optionValue];
            }
        }
        return [$options];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function accessibility(mixed $value, A2uiVersion $version): ?array
    {
        if (!is_array($value)) {
            return null;
        }
        $clean = [];
        foreach ($this->registry->accessibilityAttributes($version) as $attribute) {
            if (array_key_exists($attribute->name, $value)) {
                $sanitised = $this->value($value[$attribute->name], $attribute, $version);
                if ($sanitised !== null) {
                    $clean[$attribute->name] = $sanitised[0];
                }
            }
        }
        return $clean !== [] ? $clean : null;
    }

    /**
     * The value of a `required` check: anything JSON can hold.
     *
     * @return array{0: mixed}|null
     */
    private function anyValue(mixed $value, A2uiVersion $version): ?array
    {
        if (is_array($value) && (isset($value['path']) || isset($value['call']))) {
            return $this->dynamic($value, ['string', 'number', 'boolean', 'validationResult', 'array'], $version);
        }
        return $value === null || is_scalar($value) || is_array($value) ? [$value] : null;
    }

    /**
     * An action context value: a literal, a binding or a function call. A
     * plain object is a literal only in v1.0.
     *
     * @return array{0: mixed}|null
     */
    private function dynamicValue(mixed $value, A2uiVersion $version): ?array
    {
        if (is_string($value)) {
            return [mb_substr($value, 0, self::MAX_TEXT)];
        }
        if (is_int($value) || is_float($value) || is_bool($value)) {
            return [$value];
        }
        if (!is_array($value)) {
            return null;
        }
        if (array_is_list($value)) {
            return [$value];
        }
        if (isset($value['path']) || isset($value['call'])) {
            return $this->dynamic($value, ['string', 'number', 'boolean', 'validationResult'], $version);
        }
        return $version === A2uiVersion::V1_0 ? [$value] : null;
    }

    /**
     * @return array{0: string}|null
     */
    private function uri(mixed $value): ?array
    {
        if (!is_string($value) || filter_var($value, FILTER_VALIDATE_URL) === false) {
            return null;
        }
        $scheme = strtolower((string)parse_url($value, PHP_URL_SCHEME));
        return $scheme === 'https' || $scheme === 'http' ? [$value] : null;
    }

    /**
     * @return array{0: list<mixed>}|null
     */
    private function booleanList(mixed $value, A2uiVersion $version, int $minimum): ?array
    {
        if (!is_array($value) || !array_is_list($value)) {
            return null;
        }
        $values = [];
        foreach ($value as $item) {
            $sanitised = $this->dynamicBoolean($item, $version);
            if ($sanitised !== null) {
                $values[] = $sanitised[0];
            }
        }
        return count($values) >= $minimum ? [$values] : null;
    }

    /**
     * @return array{0: int}|null
     */
    private function integer(mixed $value, ?int $minimum): ?array
    {
        if (is_float($value) && floor($value) === $value) {
            $value = (int)$value;
        } elseif (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            $value = (int)$value;
        }
        if (!is_int($value) || ($minimum !== null && $value < $minimum)) {
            return null;
        }
        return [$value];
    }

    /**
     * Cut references to components that do not exist (or to the component
     * itself); a component that loses a required reference goes too, until
     * nothing changes any more.
     *
     * @param array<string, Component> $byId
     * @param list<string> $notes
     * @return array<string, Component>
     */
    private function resolveReferences(array $byId, A2uiVersion $version, array &$notes): array
    {
        do {
            $changed = false;
            foreach ($byId as $id => $component) {
                $definition = $this->registry->component($component->type, $version);
                if ($definition === null) {
                    continue;
                }
                $exists = static fn(mixed $reference): bool => is_string($reference) && $reference !== $id && isset($byId[$reference]);
                foreach ($definition->properties as $name => $property) {
                    if (!$component->has($name)) {
                        continue;
                    }
                    $value = $component->property($name);
                    if ($property->type === PropertyType::ComponentId && !$exists($value)) {
                        if ($property->required) {
                            $notes[] = sprintf('Dropped %s "%s": its %s "%s" does not exist.', $component->type, $id, $name, is_string($value) ? $value : '');
                            unset($byId[$id]);
                            $changed = true;
                            continue 2;
                        }
                        $component = $component->without($name);
                    } elseif ($property->type === PropertyType::ChildList && is_array($value)) {
                        if (array_is_list($value)) {
                            $kept = array_values(array_filter($value, $exists));
                            if (count($kept) !== count($value)) {
                                $notes[] = sprintf('Removed children of %s "%s" that do not exist.', $component->type, $id);
                                $component = $component->with($name, $kept);
                            }
                        } elseif (!$exists($value['componentId'] ?? null)) {
                            $notes[] = sprintf('Removed the template of %s "%s": its component does not exist.', $component->type, $id);
                            $component = $component->with($name, []);
                        }
                    } elseif ($property->type === PropertyType::Tabs && is_array($value)) {
                        $kept = array_values(array_filter(
                            $value,
                            static fn(mixed $tab): bool => is_array($tab) && $exists($tab['child'] ?? null),
                        ));
                        if ($kept === []) {
                            $notes[] = sprintf('Dropped Tabs "%s": none of its tabs has a component.', $id);
                            unset($byId[$id]);
                            $changed = true;
                            continue 2;
                        }
                        if (count($kept) !== count($value)) {
                            $notes[] = sprintf('Removed tabs of "%s" whose component does not exist.', $id);
                            $component = $component->with($name, $kept);
                        }
                    }
                }
                $byId[$id] = $component;
            }
        } while ($changed);

        return $byId;
    }

    /**
     * Exactly one component must have the id "root". Models often call it
     * something else; when that happened — no component was called "root" and
     * one component nobody refers to is left — it becomes the root. A root
     * that was dropped is not replaced by whatever is left over.
     *
     * @param array<string, Component> $byId
     * @param list<string> $notes
     * @return array<string, Component>
     */
    private function ensureRoot(array $byId, bool $namedRoot, A2uiVersion $version, array &$notes): array
    {
        if (isset($byId[Component::ROOT]) || $byId === []) {
            if ($byId === []) {
                $notes[] = 'No usable component was left.';
            }
            return $byId;
        }
        if ($namedRoot) {
            $notes[] = 'The root component could not be used, so nothing can be rendered.';
            return $byId;
        }
        $referenced = [];
        foreach ($byId as $component) {
            foreach ($this->references($component, $version) as $reference) {
                $referenced[$reference] = true;
            }
        }
        $candidates = array_values(array_filter(array_keys($byId), static fn(string $id): bool => !isset($referenced[$id])));
        if ($candidates === []) {
            $notes[] = 'No component has the id "root", and every component is the child of another.';
            return $byId;
        }
        $old = $candidates[0];
        $notes[] = sprintf('Made "%s" the root: no component had the id "root".', $old);
        $renamed = [Component::ROOT => $byId[$old]->withId(Component::ROOT)];
        unset($byId[$old]);
        return $renamed + $byId;
    }

    /**
     * Walk the tree from the root: break cycles, keep only what the root
     * reaches, and keep `weight` only on direct children of a Row or Column.
     *
     * @param array<string, Component> $byId
     * @param list<string> $notes
     * @return list<Component>
     */
    private function prune(array $byId, A2uiVersion $version, array &$notes): array
    {
        $reachable = [];
        $weighted = [];
        /** @param array<string, true> $ancestors */
        $visit = function (string $id, array $ancestors) use (&$visit, &$byId, &$reachable, &$weighted, &$notes, $version): void {
            $reachable[$id] = true;
            $component = $byId[$id];
            $ancestors[$id] = true;
            foreach ($this->references($component, $version) as $reference) {
                if (isset($ancestors[$reference])) {
                    $notes[] = sprintf('Removed the reference from "%s" to "%s": it would loop.', $id, $reference);
                    $component = $this->withoutReference($component, $reference, $version);
                    $byId[$id] = $component;
                    continue;
                }
                if ($component->type === 'Row' || $component->type === 'Column') {
                    $weighted[$reference] = true;
                }
                if (!isset($reachable[$reference]) && isset($byId[$reference])) {
                    $visit($reference, $ancestors);
                }
            }
        };
        $visit(Component::ROOT, []);

        $unreachable = count($byId) - count($reachable);
        if ($unreachable > 0) {
            $notes[] = sprintf('Removed %d component(s) the root does not reach.', $unreachable);
        }

        $components = [];
        foreach ($byId as $id => $component) {
            if (!isset($reachable[$id])) {
                continue;
            }
            if ($component->has('weight') && !isset($weighted[$id])) {
                $notes[] = sprintf('Removed the weight of "%s": only children of a Row or Column have one.', $id);
                $component = $component->without('weight');
            }
            $components[] = $component;
        }
        usort($components, static fn(Component $a, Component $b): int => ($b->id === Component::ROOT) <=> ($a->id === Component::ROOT));
        return $components;
    }

    /**
     * Every component id a component refers to, in order.
     *
     * @return list<string>
     */
    private function references(Component $component, A2uiVersion $version): array
    {
        $definition = $this->registry->component($component->type, $version);
        if ($definition === null) {
            return [];
        }
        $references = [];
        foreach ($definition->properties as $name => $property) {
            $value = $component->property($name);
            if ($property->type === PropertyType::ComponentId && is_string($value)) {
                $references[] = $value;
            } elseif ($property->type === PropertyType::ChildList && is_array($value)) {
                if (array_is_list($value)) {
                    foreach ($value as $child) {
                        if (is_string($child)) {
                            $references[] = $child;
                        }
                    }
                } elseif (is_string($value['componentId'] ?? null)) {
                    $references[] = $value['componentId'];
                }
            } elseif ($property->type === PropertyType::Tabs && is_array($value)) {
                foreach ($value as $tab) {
                    if (is_array($tab) && is_string($tab['child'] ?? null)) {
                        $references[] = $tab['child'];
                    }
                }
            }
        }
        return $references;
    }

    private function withoutReference(Component $component, string $reference, A2uiVersion $version): Component
    {
        $definition = $this->registry->component($component->type, $version);
        foreach ($definition !== null ? $definition->properties : [] as $name => $property) {
            $value = $component->property($name);
            if ($property->type === PropertyType::ChildList && is_array($value)) {
                $component = $component->with($name, array_is_list($value)
                    ? array_values(array_filter($value, static fn(mixed $child): bool => $child !== $reference))
                    : (($value['componentId'] ?? null) === $reference ? [] : $value));
            } elseif ($property->type === PropertyType::Tabs && is_array($value)) {
                $component = $component->with($name, array_values(array_filter(
                    $value,
                    static fn(mixed $tab): bool => !is_array($tab) || ($tab['child'] ?? null) !== $reference,
                )));
            } elseif ($property->type === PropertyType::ComponentId && $value === $reference) {
                $component = $component->without($name);
            }
        }
        return $component;
    }

    /**
     * Keep properties in catalogue order, with the common ones first.
     *
     * @param array<string, mixed> $properties
     * @return array<string, mixed>
     */
    private function ordered(array $properties, ComponentDefinition $definition): array
    {
        $ordered = [];
        foreach (['accessibility', 'weight', ...array_keys($definition->properties)] as $name) {
            if (array_key_exists($name, $properties)) {
                $ordered[$name] = $properties[$name];
            }
        }
        return $ordered;
    }

    /**
     * The data model must be a JSON object, and a small one.
     *
     * @param list<string> $notes
     * @return array<string, mixed>
     */
    private function dataModel(mixed $dataModel, array &$notes): array
    {
        if ($dataModel === null || $dataModel === []) {
            return [];
        }
        if (!is_array($dataModel) || array_is_list($dataModel)) {
            $notes[] = 'Replaced the data model with an empty object: it was not a JSON object.';
            return [];
        }
        $json = json_encode($dataModel, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false || strlen($json) > self::MAX_DATA_MODEL_BYTES) {
            $notes[] = 'Replaced the data model with an empty object: it was too large.';
            return [];
        }
        $object = [];
        foreach ($dataModel as $key => $value) {
            $object[(string)$key] = $value;
        }
        return $object;
    }

    private function heading(string $id, string $text, int $level, A2uiVersion $version): Component
    {
        return $version === A2uiVersion::V1_0
            ? new Component($id, 'Text', ['text' => str_repeat('#', $level) . ' ' . $text])
            : new Component($id, 'Text', ['text' => $text, 'variant' => 'h' . $level]);
    }
}
