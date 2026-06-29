<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\A2ui\Service;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\SingletonInterface;
use TYPO3\CMS\Core\View\ViewFactoryData;
use TYPO3\CMS\Core\View\ViewFactoryInterface;
use Webconsulting\AgentNexus\A2ui\Domain\Model\Component;
use Webconsulting\AgentNexus\A2ui\Domain\Model\Surface;
use Webconsulting\AgentNexus\A2ui\Domain\Repository\ComponentRegistry;

/**
 * Server-side rendering of an A2UI v1.0 surface.
 *
 * The interactive playground renders surfaces client-side (that is the whole
 * point of A2UI). This service provides the no-JavaScript path used by the
 * component gallery: it reconstructs the tree from the flat adjacency list
 * starting at "root", resolves JSON-Pointer value bindings against the data
 * model, and renders each node with its catalog Fluid template (Bootstrap 5).
 */
final class RendererService implements SingletonInterface
{
    private const TEMPLATE_ROOT = 'EXT:agent_nexus/Resources/Private/Templates/';

    public function __construct(
        private readonly ComponentRegistry $registry,
        private readonly ViewFactoryInterface $viewFactory,
    ) {}

    /**
     * The canonical A2UI v1.0 message as pretty JSON.
     */
    public function toJson(Surface $surface): string
    {
        return $surface->toJson();
    }

    /**
     * Render a surface to backend HTML (no-JS fallback / gallery).
     */
    public function renderStatic(Surface $surface, ServerRequestInterface $request): string
    {
        $root = $surface->getRoot();
        if ($root === null) {
            return '<div class="alert alert-warning" role="alert">A2UI: surface has no <code>root</code> component.</div>';
        }

        return '<div class="a2ui-surface" data-surface-id="' . htmlspecialchars($surface->getSurfaceId()) . '">'
            . $this->renderNode($root, $surface, $request)
            . '</div>';
    }

    private function renderNode(Component $component, Surface $surface, ServerRequestInterface $request): string
    {
        $type = $component->getComponent();
        if (!$this->registry->isRegistered($type)) {
            // Defensive: surfaces are sanitised upstream, but never trust blindly.
            return '';
        }

        $childrenHtml = '';
        if ($this->registry->isContainer($type)) {
            // Server-side rendering resolves static child ids; List template
            // iteration is a client-side concern (the gallery uses static forms).
            foreach ($component->getChildIds() as $childId) {
                $child = $surface->getComponent($childId);
                if ($child !== null) {
                    $childrenHtml .= $this->renderNode($child, $surface, $request);
                }
            }
        }

        [$value, $bindingPath] = $this->resolveBinding($component->getProperty('value'), $surface->getDataModel());

        $view = $this->viewFactory->create(new ViewFactoryData(
            templateRootPaths: [self::TEMPLATE_ROOT],
            request: $request,
        ));
        $view->assignMultiple([
            'id' => $component->getId(),
            'component' => $type,
            'props' => $component->getProperties(),
            'value' => $value,
            'path' => $bindingPath,
            'action' => $component->getAction(),
            'children' => $childrenHtml,
        ]);

        return $view->render('A2UI/Components/' . $type);
    }

    /**
     * Resolve a component "value" property: a JSON-Pointer binding object
     * ({"path": "/field"}) is looked up in the data model; a literal is returned
     * as-is.
     *
     * @param array<string, mixed> $dataModel
     * @return array{0: mixed, 1: ?string} [resolvedValue, bindingPath|null]
     */
    private function resolveBinding(mixed $value, array $dataModel): array
    {
        if (is_array($value) && isset($value['path']) && is_string($value['path'])) {
            return [$this->resolvePointer($dataModel, $value['path']), $value['path']];
        }
        return [$value, null];
    }

    /**
     * Minimal RFC 6901 JSON Pointer lookup (absolute paths from the data model root).
     *
     * @param array<string, mixed> $data
     */
    private function resolvePointer(array $data, string $pointer): mixed
    {
        $pointer = ltrim($pointer, '/');
        if ($pointer === '') {
            return $data;
        }
        $current = $data;
        foreach (explode('/', $pointer) as $segment) {
            $segment = str_replace(['~1', '~0'], ['/', '~'], $segment);
            if (is_array($current) && array_key_exists($segment, $current)) {
                $current = $current[$segment];
            } else {
                return null;
            }
        }
        return $current;
    }
}
