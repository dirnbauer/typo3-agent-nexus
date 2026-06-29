<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\A2ui\Domain\Repository;

use TYPO3\CMS\Core\SingletonInterface;
use Webconsulting\AgentNexus\A2ui\Domain\Model\Component;

/**
 * The trusted A2UI v1.0 component catalog.
 *
 * This is the security boundary of the whole integration: an agent may only
 * *describe* a UI using the components and properties registered here. Anything
 * the agent emits that is not in this catalog is rejected (server side) or
 * stripped (when sanitising LLM output) before it ever reaches the renderer.
 * A2UI sends declarative data, never executable code - the catalog is what keeps
 * that promise enforceable.
 */
class ComponentRegistry implements SingletonInterface
{
    public const CATEGORY_DISPLAY = 'display';
    public const CATEGORY_INPUT = 'input';
    public const CATEGORY_CONTAINER = 'container';
    public const CATEGORY_INTERACTIVE = 'interactive';

    /**
     * @var array<string, array{template: string, category: string, allowedProps: array<int, string>, container: bool}>
     */
    protected array $catalog = [];

    public function __construct()
    {
        $this->registerBasicCatalog();
    }

    public function register(string $component, string $templatePath, string $category, array $allowedProps = [], bool $container = false): self
    {
        $this->catalog[$component] = [
            'template' => $templatePath,
            'category' => $category,
            'allowedProps' => $allowedProps,
            'container' => $container,
        ];
        return $this;
    }

    public function isRegistered(string $component): bool
    {
        return isset($this->catalog[$component]);
    }

    public function isContainer(string $component): bool
    {
        return (bool)($this->catalog[$component]['container'] ?? false);
    }

    public function getTemplatePath(string $component): ?string
    {
        return $this->catalog[$component]['template'] ?? null;
    }

    /**
     * @return array<int, string>
     */
    public function getRegisteredComponents(): array
    {
        return array_keys($this->catalog);
    }

    /**
     * Validate a single component against the catalog: it must be registered and
     * every property it carries must be on that component's allow-list.
     */
    public function validate(Component $component): bool
    {
        if (!$this->isRegistered($component->getComponent())) {
            return false;
        }
        $allowed = $this->catalog[$component->getComponent()]['allowedProps'];
        if ($allowed === []) {
            return true;
        }
        foreach (array_keys($component->getProperties()) as $prop) {
            if (!in_array($prop, $allowed, true)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Return a safe copy of a component: unknown components yield null, and any
     * property not on the allow-list is dropped. Used to harden (possibly noisy)
     * LLM output without discarding an otherwise valid component.
     */
    public function sanitize(Component $component): ?Component
    {
        if (!$this->isRegistered($component->getComponent())) {
            return null;
        }
        $allowed = $this->catalog[$component->getComponent()]['allowedProps'];
        $properties = $component->getProperties();
        if ($allowed !== []) {
            $properties = array_filter(
                $properties,
                static fn(string $key): bool => in_array($key, $allowed, true),
                ARRAY_FILTER_USE_KEY,
            );
        }

        return new Component(
            $component->getId(),
            $component->getComponent(),
            $properties,
            $this->isContainer($component->getComponent()) ? $component->getChildren() : [],
            $component->getAction(),
        );
    }

    /**
     * The catalog manifest shared with the client renderer and the "allowed
     * catalog" panel: component -> {category, container, allowedProps}.
     *
     * @return array<string, array{category: string, container: bool, allowedProps: array<int, string>}>
     */
    public function getCatalogManifest(): array
    {
        $manifest = [];
        foreach ($this->catalog as $name => $config) {
            $manifest[$name] = [
                'category' => $config['category'],
                'container' => $config['container'],
                'allowedProps' => $config['allowedProps'],
            ];
        }
        return $manifest;
    }

    /**
     * Register the A2UI v1.0 "basic" catalog, mapped to TYPO3 backend (Bootstrap 5)
     * components.
     */
    protected function registerBasicCatalog(): void
    {
        $base = 'EXT:agent_nexus/Resources/Private/Templates/A2UI/Components/';

        $this->register('Text', $base . 'Text.html', self::CATEGORY_DISPLAY, [
            'text', 'variant', 'align',
        ]);

        $this->register('Image', $base . 'Image.html', self::CATEGORY_DISPLAY, [
            'src', 'alt', 'width', 'height', 'rounded',
        ]);

        $this->register('Icon', $base . 'Icon.html', self::CATEGORY_DISPLAY, [
            'name', 'size', 'color',
        ]);

        $this->register('Video', $base . 'Video.html', self::CATEGORY_DISPLAY, [
            'src', 'poster', 'controls', 'autoplay',
        ]);

        $this->register('AudioPlayer', $base . 'AudioPlayer.html', self::CATEGORY_DISPLAY, [
            'src', 'controls',
        ]);

        $this->register('Slider', $base . 'Slider.html', self::CATEGORY_INPUT, [
            'label', 'value', 'min', 'max', 'step', 'helpText', 'checks',
        ]);

        $this->register('TextField', $base . 'TextField.html', self::CATEGORY_INPUT, [
            'label', 'placeholder', 'value', 'required', 'maxlength', 'disabled', 'helpText', 'inputType', 'checks',
        ]);

        $this->register('Textarea', $base . 'Textarea.html', self::CATEGORY_INPUT, [
            'label', 'placeholder', 'value', 'rows', 'required', 'disabled', 'helpText', 'checks',
        ]);

        $this->register('ChoicePicker', $base . 'ChoicePicker.html', self::CATEGORY_INPUT, [
            'label', 'value', 'options', 'multiple', 'required', 'helpText', 'checks',
        ]);

        $this->register('CheckBox', $base . 'CheckBox.html', self::CATEGORY_INPUT, [
            'label', 'value', 'checked', 'helpText', 'checks',
        ]);

        $this->register('DateTimeInput', $base . 'DateTimeInput.html', self::CATEGORY_INPUT, [
            'label', 'value', 'min', 'max', 'mode', 'required', 'helpText', 'checks',
        ]);

        $this->register('Button', $base . 'Button.html', self::CATEGORY_INTERACTIVE, [
            'text', 'label', 'variant', 'icon', 'disabled',
        ]);

        $this->register('ButtonGroup', $base . 'ButtonGroup.html', self::CATEGORY_INTERACTIVE, [
            'label', 'options', 'value', 'multiple',
        ]);

        $this->register('Divider', $base . 'Divider.html', self::CATEGORY_DISPLAY, [
            'label',
        ]);

        $this->register('Column', $base . 'Column.html', self::CATEGORY_CONTAINER, [
            'gap', 'align',
        ], true);

        $this->register('Row', $base . 'Row.html', self::CATEGORY_CONTAINER, [
            'gap', 'align', 'wrap',
        ], true);

        $this->register('Card', $base . 'Card.html', self::CATEGORY_CONTAINER, [
            'title', 'subtitle',
        ], true);

        // List supports A2UI template children: {"path": "/items", "componentId": "tpl"}.
        $this->register('List', $base . 'List.html', self::CATEGORY_CONTAINER, [
            'gap', 'emptyText',
        ], true);

        // Tabs: `tabs` holds the titles, parallel to the children (one panel each).
        $this->register('Tabs', $base . 'Tabs.html', self::CATEGORY_CONTAINER, [
            'tabs',
        ], true);

        $this->register('Modal', $base . 'Modal.html', self::CATEGORY_CONTAINER, [
            'title', 'open',
        ], true);
    }
}
