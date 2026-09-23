<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\A2ui\Domain\Repository;

use TYPO3\CMS\Core\SingletonInterface;
use Webconsulting\AgentNexus\A2ui\Domain\Model\A2uiVersion;
use Webconsulting\AgentNexus\A2ui\Domain\Model\ComponentDefinition;
use Webconsulting\AgentNexus\A2ui\Domain\Model\FunctionDefinition;
use Webconsulting\AgentNexus\A2ui\Domain\Model\PropertyDefinition;
use Webconsulting\AgentNexus\A2ui\Domain\Model\PropertyType;

/**
 * The official A2UI basic catalogue, per version.
 *
 * This is the trust boundary of the integration: an agent may only describe an
 * interface with the components, properties and functions listed here, and
 * the sanitiser strips everything else before a surface leaves the server. The
 * definitions are written out by hand from the published catalogues
 * (`https://a2ui.org/specification/v0_9/catalogs/basic/catalog.json` for
 * v0.9.1, `…/v1_0/…` for the v1.0 candidate); a conformance test compares
 * them with the vendored copies of those files, so they cannot drift.
 */
final class ComponentRegistry implements SingletonInterface
{
    /** The icon names of the basic catalogue, in catalogue order (identical in v0.9.1 and v1.0). */
    public const array ICON_NAMES = [
        'accountCircle', 'add', 'arrowBack', 'arrowForward', 'attachFile', 'calendarToday', 'call', 'camera',
        'check', 'close', 'delete', 'download', 'edit', 'event', 'error', 'fastForward', 'favorite',
        'favoriteOff', 'folder', 'help', 'home', 'info', 'locationOn', 'lock', 'lockOpen', 'mail', 'menu',
        'moreVert', 'moreHoriz', 'notificationsOff', 'notifications', 'pause', 'payment', 'person', 'phone',
        'photo', 'play', 'print', 'refresh', 'rewind', 'search', 'send', 'settings', 'share', 'shoppingCart',
        'skipNext', 'skipPrevious', 'star', 'starHalf', 'starOff', 'stop', 'upload', 'visibility',
        'visibilityOff', 'volumeDown', 'volumeMute', 'volumeOff', 'volumeUp', 'warning',
    ];

    /** The v1.0 system function available inside list templates. */
    public const string INDEX_FUNCTION = '@index';

    /** @var array<string, array<string, ComponentDefinition>> */
    private array $components = [];

    /** @var array<string, array<string, FunctionDefinition>> */
    private array $functions = [];

    /**
     * @return array<string, ComponentDefinition> keyed by component name, in catalogue order
     */
    public function components(A2uiVersion $version = A2uiVersion::DEFAULT): array
    {
        return $this->components[$version->value] ??= $this->defineComponents($version);
    }

    public function component(string $name, A2uiVersion $version = A2uiVersion::DEFAULT): ?ComponentDefinition
    {
        return $this->components($version)[$name] ?? null;
    }

    public function isRegistered(string $name, A2uiVersion $version = A2uiVersion::DEFAULT): bool
    {
        return $this->component($name, $version) !== null;
    }

    /**
     * The properties every component takes (ComponentCommon, plus `weight`).
     *
     * @return list<PropertyDefinition>
     */
    public function commonProperties(A2uiVersion $version = A2uiVersion::DEFAULT): array
    {
        $common = [
            new PropertyDefinition('id', PropertyType::ComponentId, required: true),
            new PropertyDefinition('accessibility', PropertyType::Accessibility),
            new PropertyDefinition('weight', PropertyType::Number),
        ];
        if ($version === A2uiVersion::V1_0) {
            array_splice($common, 1, 0, [new PropertyDefinition('catalogId', PropertyType::String)]);
            $common[] = new PropertyDefinition('metadata', PropertyType::Object);
        }
        return $common;
    }

    /**
     * The attributes of `accessibility`.
     *
     * @return list<PropertyDefinition>
     */
    public function accessibilityAttributes(A2uiVersion $version = A2uiVersion::DEFAULT): array
    {
        $attributes = [
            new PropertyDefinition('label', PropertyType::DynamicString),
            new PropertyDefinition('description', PropertyType::DynamicString),
        ];
        if ($version === A2uiVersion::V1_0) {
            $attributes[] = new PropertyDefinition('live', PropertyType::Enum, values: ['off', 'polite', 'assertive'], default: 'off');
            $attributes[] = new PropertyDefinition('hidden', PropertyType::DynamicBoolean);
        }
        return $attributes;
    }

    /**
     * @return array<string, FunctionDefinition> the catalogue functions, keyed by name
     */
    public function functions(A2uiVersion $version = A2uiVersion::DEFAULT): array
    {
        return $this->functions[$version->value] ??= $this->defineFunctions($version);
    }

    /** A catalogue function, or the v1.0 system function `@index`. */
    public function function(string $name, A2uiVersion $version = A2uiVersion::DEFAULT): ?FunctionDefinition
    {
        if ($name === self::INDEX_FUNCTION) {
            return $version === A2uiVersion::V1_0
                ? new FunctionDefinition(self::INDEX_FUNCTION, 'number', [
                    new PropertyDefinition('offset', PropertyType::DynamicNumber, default: 0),
                ])
                : null;
        }
        return $this->functions($version)[$name] ?? null;
    }

    /**
     * The theme properties `createSurface` may carry. v1.0 removed the theme.
     *
     * @return list<PropertyDefinition>
     */
    public function themeProperties(A2uiVersion $version = A2uiVersion::DEFAULT): array
    {
        if ($version === A2uiVersion::V1_0) {
            return [];
        }
        return [
            new PropertyDefinition('primaryColor', PropertyType::String),
            new PropertyDefinition('iconUrl', PropertyType::Uri),
            new PropertyDefinition('agentDisplayName', PropertyType::String),
        ];
    }

    /**
     * What this agent can generate, as the A2UI capabilities schemas describe
     * an agent: `server_capabilities.json` ("v0.9") and
     * `agent_capabilities.json` ("v1.0") in one object.
     *
     * @return array<string, array{supportedCatalogIds: list<string>, acceptsInlineCatalogs: bool}>
     */
    public function capabilities(): array
    {
        $capabilities = [];
        foreach (A2uiVersion::cases() as $version) {
            $capabilities[$version->capabilitiesKey()] = [
                'supportedCatalogIds' => [$version->catalogId()],
                'acceptsInlineCatalogs' => false,
            ];
        }
        return $capabilities;
    }

    /**
     * The catalogue in the shape the protocol overview reads:
     * component => {category, container, allowedProps}.
     *
     * @return array<string, array{category: string, container: bool, allowedProps: list<string>}>
     */
    public function getCatalogManifest(A2uiVersion $version = A2uiVersion::DEFAULT): array
    {
        $manifest = [];
        foreach ($this->components($version) as $name => $definition) {
            $manifest[$name] = [
                'category' => $definition->category,
                'container' => $definition->isContainer(),
                'allowedProps' => array_keys($definition->properties),
            ];
        }
        return $manifest;
    }

    /**
     * @return array<string, ComponentDefinition>
     */
    private function defineComponents(A2uiVersion $version): array
    {
        $v1 = $version === A2uiVersion::V1_0;
        $checks = new PropertyDefinition('checks', PropertyType::Checks);

        $definitions = [
            new ComponentDefinition('Text', ComponentDefinition::CATEGORY_DISPLAY, [
                new PropertyDefinition('text', PropertyType::DynamicString, required: true),
                new PropertyDefinition(
                    'variant',
                    PropertyType::Enum,
                    values: $v1 ? ['caption', 'body'] : ['h1', 'h2', 'h3', 'h4', 'h5', 'caption', 'body'],
                    default: 'body',
                ),
            ]),
            new ComponentDefinition('Image', ComponentDefinition::CATEGORY_DISPLAY, [
                new PropertyDefinition('url', PropertyType::DynamicString, required: true),
                new PropertyDefinition('description', PropertyType::DynamicString),
                new PropertyDefinition('fit', PropertyType::Enum, values: ['contain', 'cover', 'fill', 'none', 'scaleDown'], default: 'fill'),
                new PropertyDefinition(
                    'variant',
                    PropertyType::Enum,
                    values: ['icon', 'avatar', 'smallFeature', 'mediumFeature', 'largeFeature', 'header'],
                    default: 'mediumFeature',
                ),
            ]),
            new ComponentDefinition('Icon', ComponentDefinition::CATEGORY_DISPLAY, [
                new PropertyDefinition('name', PropertyType::IconName, required: true, values: self::ICON_NAMES),
            ]),
            new ComponentDefinition('Video', ComponentDefinition::CATEGORY_DISPLAY, array_values(array_filter([
                new PropertyDefinition('url', PropertyType::DynamicString, required: true),
                $v1 ? new PropertyDefinition('posterUrl', PropertyType::DynamicString) : null,
            ]))),
            new ComponentDefinition('AudioPlayer', ComponentDefinition::CATEGORY_DISPLAY, [
                new PropertyDefinition('url', PropertyType::DynamicString, required: true),
                new PropertyDefinition('description', PropertyType::DynamicString),
            ]),
            new ComponentDefinition('Row', ComponentDefinition::CATEGORY_LAYOUT, [
                new PropertyDefinition('children', PropertyType::ChildList, required: true),
                new PropertyDefinition(
                    'justify',
                    PropertyType::Enum,
                    values: ['center', 'end', 'spaceAround', 'spaceBetween', 'spaceEvenly', 'start', 'stretch'],
                    default: 'start',
                ),
                new PropertyDefinition('align', PropertyType::Enum, values: ['start', 'center', 'end', 'stretch'], default: 'stretch'),
            ]),
            new ComponentDefinition('Column', ComponentDefinition::CATEGORY_LAYOUT, [
                new PropertyDefinition('children', PropertyType::ChildList, required: true),
                new PropertyDefinition(
                    'justify',
                    PropertyType::Enum,
                    values: ['start', 'center', 'end', 'spaceBetween', 'spaceAround', 'spaceEvenly', 'stretch'],
                    default: 'start',
                ),
                new PropertyDefinition('align', PropertyType::Enum, values: ['center', 'end', 'start', 'stretch'], default: 'stretch'),
            ]),
            new ComponentDefinition('List', ComponentDefinition::CATEGORY_LAYOUT, [
                new PropertyDefinition('children', PropertyType::ChildList, required: true),
                new PropertyDefinition('direction', PropertyType::Enum, values: ['vertical', 'horizontal'], default: 'vertical'),
                new PropertyDefinition('align', PropertyType::Enum, values: ['start', 'center', 'end', 'stretch'], default: 'stretch'),
            ]),
            new ComponentDefinition('Card', ComponentDefinition::CATEGORY_LAYOUT, [
                new PropertyDefinition('child', PropertyType::ComponentId, required: true),
            ]),
            new ComponentDefinition('Tabs', ComponentDefinition::CATEGORY_LAYOUT, [
                new PropertyDefinition('tabs', PropertyType::Tabs, required: true, minimum: 1),
            ]),
            new ComponentDefinition('Modal', ComponentDefinition::CATEGORY_LAYOUT, [
                new PropertyDefinition('trigger', PropertyType::ComponentId, required: true),
                new PropertyDefinition('content', PropertyType::ComponentId, required: true),
            ]),
            new ComponentDefinition('Divider', ComponentDefinition::CATEGORY_LAYOUT, [
                new PropertyDefinition('axis', PropertyType::Enum, values: ['horizontal', 'vertical'], default: 'horizontal'),
            ]),
            new ComponentDefinition('Button', ComponentDefinition::CATEGORY_INTERACTIVE, [
                new PropertyDefinition('child', PropertyType::ComponentId, required: true),
                new PropertyDefinition('variant', PropertyType::Enum, values: ['default', 'primary', 'borderless'], default: 'default'),
                new PropertyDefinition('action', PropertyType::Action, required: true),
                $checks,
            ]),
            new ComponentDefinition('TextField', ComponentDefinition::CATEGORY_INPUT, array_values(array_filter([
                new PropertyDefinition('label', PropertyType::DynamicString, required: true),
                new PropertyDefinition('value', PropertyType::DynamicString),
                $v1 ? new PropertyDefinition('placeholder', PropertyType::DynamicString) : null,
                new PropertyDefinition('variant', PropertyType::Enum, values: ['longText', 'number', 'shortText', 'obscured'], default: 'shortText'),
                $v1 ? null : new PropertyDefinition('validationRegexp', PropertyType::String),
                $checks,
            ]))),
            new ComponentDefinition('CheckBox', ComponentDefinition::CATEGORY_INPUT, [
                new PropertyDefinition('label', PropertyType::DynamicString, required: true),
                new PropertyDefinition('value', PropertyType::DynamicBoolean, required: true),
                $checks,
            ]),
            new ComponentDefinition('ChoicePicker', ComponentDefinition::CATEGORY_INPUT, [
                new PropertyDefinition('label', PropertyType::DynamicString),
                new PropertyDefinition('variant', PropertyType::Enum, values: ['multipleSelection', 'mutuallyExclusive'], default: 'mutuallyExclusive'),
                new PropertyDefinition('options', PropertyType::Options, required: true),
                new PropertyDefinition('value', PropertyType::DynamicStringList, required: true),
                new PropertyDefinition('displayStyle', PropertyType::Enum, values: ['checkbox', 'chips'], default: 'checkbox'),
                new PropertyDefinition('filterable', PropertyType::Boolean, default: false),
                $checks,
            ]),
            new ComponentDefinition('Slider', ComponentDefinition::CATEGORY_INPUT, array_values(array_filter([
                new PropertyDefinition('label', PropertyType::DynamicString),
                new PropertyDefinition('min', PropertyType::Number, default: 0),
                new PropertyDefinition('max', PropertyType::Number, required: true),
                new PropertyDefinition('value', PropertyType::DynamicNumber, required: true),
                $v1 ? new PropertyDefinition('steps', PropertyType::Integer, minimum: 1) : null,
                $checks,
            ]))),
            new ComponentDefinition('DateTimeInput', ComponentDefinition::CATEGORY_INPUT, [
                new PropertyDefinition('value', PropertyType::DynamicString, required: true),
                new PropertyDefinition('enableDate', PropertyType::Boolean, default: false),
                new PropertyDefinition('enableTime', PropertyType::Boolean, default: false),
                new PropertyDefinition('min', PropertyType::DateTime),
                new PropertyDefinition('max', PropertyType::DateTime),
                new PropertyDefinition('label', PropertyType::DynamicString),
                $checks,
            ]),
        ];

        $indexed = [];
        foreach ($definitions as $definition) {
            $indexed[$definition->name] = $definition;
        }
        return $indexed;
    }

    /**
     * @return array<string, FunctionDefinition>
     */
    private function defineFunctions(A2uiVersion $version): array
    {
        $check = $version === A2uiVersion::V1_0 ? 'validationResult' : 'boolean';
        $value = static fn(PropertyType $type): PropertyDefinition => new PropertyDefinition('value', $type, required: true);

        $definitions = [
            new FunctionDefinition('required', $check, [$value(PropertyType::Any)]),
            new FunctionDefinition('regex', $check, [
                $value(PropertyType::DynamicString),
                new PropertyDefinition('pattern', PropertyType::String, required: true),
            ]),
            new FunctionDefinition('length', $check, [
                $value(PropertyType::DynamicString),
                new PropertyDefinition('min', PropertyType::Integer, minimum: 0),
                new PropertyDefinition('max', PropertyType::Integer, minimum: 0),
            ], ['min', 'max']),
            new FunctionDefinition('numeric', $check, [
                $value(PropertyType::DynamicNumber),
                new PropertyDefinition('min', PropertyType::Number),
                new PropertyDefinition('max', PropertyType::Number),
            ], ['min', 'max']),
            new FunctionDefinition('email', $check, [$value(PropertyType::DynamicString)]),
            new FunctionDefinition('formatString', 'string', [$value(PropertyType::DynamicString)]),
            new FunctionDefinition('formatNumber', 'string', [
                $value(PropertyType::DynamicNumber),
                new PropertyDefinition('decimals', PropertyType::DynamicNumber),
                new PropertyDefinition('grouping', PropertyType::DynamicBoolean),
            ]),
            new FunctionDefinition('formatCurrency', 'string', [
                $value(PropertyType::DynamicNumber),
                new PropertyDefinition('currency', PropertyType::DynamicString, required: true),
                new PropertyDefinition('decimals', PropertyType::DynamicNumber),
                new PropertyDefinition('grouping', PropertyType::DynamicBoolean),
            ]),
            new FunctionDefinition('formatDate', 'string', [
                $value(PropertyType::DynamicValue),
                new PropertyDefinition('format', PropertyType::DynamicString, required: true),
            ]),
            new FunctionDefinition('pluralize', 'string', [
                $value(PropertyType::DynamicNumber),
                new PropertyDefinition('zero', PropertyType::DynamicString),
                new PropertyDefinition('one', PropertyType::DynamicString),
                new PropertyDefinition('two', PropertyType::DynamicString),
                new PropertyDefinition('few', PropertyType::DynamicString),
                new PropertyDefinition('many', PropertyType::DynamicString),
                new PropertyDefinition('other', PropertyType::DynamicString, required: true),
            ]),
            new FunctionDefinition('openUrl', 'void', [
                new PropertyDefinition(
                    'url',
                    $version === A2uiVersion::V1_0 ? PropertyType::DynamicUri : PropertyType::Uri,
                    required: true,
                ),
            ]),
            new FunctionDefinition('and', 'boolean', [new PropertyDefinition('values', PropertyType::BooleanList, required: true, minimum: 2)]),
            new FunctionDefinition('or', 'boolean', [new PropertyDefinition('values', PropertyType::BooleanList, required: true, minimum: 2)]),
            new FunctionDefinition('not', 'boolean', [$value(PropertyType::DynamicBoolean)]),
        ];

        $indexed = [];
        foreach ($definitions as $definition) {
            $indexed[$definition->name] = $definition;
        }
        return $indexed;
    }
}
