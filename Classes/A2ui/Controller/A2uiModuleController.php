<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\A2ui\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Routing\Exception\RouteNotFoundException;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\SystemResource\Publishing\SystemResourcePublisherInterface;
use TYPO3\CMS\Core\SystemResource\SystemResourceFactory;
use Webconsulting\AgentNexus\A2ui\Domain\Model\A2uiVersion;
use Webconsulting\AgentNexus\A2ui\Domain\Model\ComponentDefinition;
use Webconsulting\AgentNexus\A2ui\Domain\Model\FunctionDefinition;
use Webconsulting\AgentNexus\A2ui\Domain\Model\PropertyDefinition;
use Webconsulting\AgentNexus\A2ui\Domain\Repository\ComponentRegistry;
use Webconsulting\AgentNexus\A2ui\Service\AgentService;
use Webconsulting\AgentNexus\A2ui\Service\CatalogueExamples;
use Webconsulting\AgentNexus\A2ui\Service\SurfaceGenerator;
use Webconsulting\AgentNexus\Agentstack\Inspector\InspectorPresenter;
use Webconsulting\AgentNexus\Agentstack\Service\SiteLocator;
use Webconsulting\AgentNexus\Shared\Backend\ModuleFrame;
use Webconsulting\AgentNexus\Shared\Http\Api\RouteRegistry;
use Webconsulting\AgentNexus\Shared\Store\ObjectFilter;
use Webconsulting\AgentNexus\Shared\Store\ObjectKind;
use Webconsulting\AgentNexus\Shared\Store\ObjectStore;

/**
 * The A2UI section of the backend.
 *
 * - Playground: describe a form in one line (or pick an example), choose
 *   v0.9.1 or the v1.0 candidate, and watch the whole exchange — every
 *   message numbered next to the surface the renderer draws from it, the
 *   action the renderer sends back and the agent's answer.
 * - Catalogue: the official basic catalogue of either version, every
 *   component with its properties and a live example, and the functions.
 */
#[AsController]
final readonly class A2uiModuleController
{
    public const string LANGUAGE_DOMAIN = 'agent_nexus.a2ui';
    private const string STYLESHEET = 'EXT:agent_nexus/Resources/Public/Css/modules/a2ui.css';
    private const int RECENT = 8;

    public function __construct(
        private ModuleFrame $moduleFrame,
        private AgentService $agent,
        private ComponentRegistry $registry,
        private CatalogueExamples $examples,
        private ObjectStore $objectStore,
        private RouteRegistry $routes,
        private UriBuilder $uriBuilder,
        private SiteLocator $siteLocator,
        private SystemResourceFactory $resourceFactory,
        private SystemResourcePublisherInterface $resourcePublisher,
    ) {}

    public function playgroundAction(ServerRequestInterface $request): ResponseInterface
    {
        $view = $this->moduleFrame->create($request, [self::STYLESHEET], ['@webconsulting/agent-nexus/a2ui-playground.js']);
        $origin = $request->getUri()->getScheme() . '://' . $request->getUri()->getAuthority();
        $surfacesUrl = $this->routes->url('a2ui.surfaces', $origin);

        $examples = [];
        foreach (SurfaceGenerator::EXAMPLES as $key => $intent) {
            $examples[] = ['key' => $key, 'intent' => $intent];
        }

        $view->assignMultiple([
            'examples' => $examples,
            'versions' => $this->versions(),
            'modelAvailable' => $this->agent->isModelAvailable(),
            'connection' => $this->agent->connectionInfo(),
            'cost' => $this->agent->costSummary(3),
            'recent' => $this->recentSurfaces(),
            'endpoints' => [
                'surfaces' => $surfacesUrl,
                'actions' => $this->routes->url('a2ui.actions', $origin),
                'catalog' => $this->routes->url('a2ui.catalog', $origin),
            ],
            'curl' => sprintf(
                "curl -s -X POST %s \\\n  -H 'Content-Type: application/json' \\\n  -d '{\"intent\": \"A contact form\", \"version\": \"v0.9.1\"}'",
                $surfacesUrl,
            ),
            'frontendUrl' => $this->siteLocator->protocolUrl('a2ui'),
            'catalogUri' => (string)$this->uriBuilder->buildUriFromRoute('agentnexus_a2ui_catalog'),
            'inspectorUri' => $this->uri(ObjectKind::Surface->inspectorModule()),
        ]);

        return $view->renderResponse('A2ui/Playground');
    }

    public function catalogAction(ServerRequestInterface $request): ResponseInterface
    {
        $version = A2uiVersion::fromWire($request->getQueryParams()['version'] ?? null) ?? A2uiVersion::DEFAULT;
        $view = $this->moduleFrame->create(
            $request,
            [self::STYLESHEET],
            ['@webconsulting/agent-nexus/a2ui-catalog.js'],
            ['version' => $version->value],
        );
        $imageUrl = (string)$this->resourcePublisher->generateUri(
            $this->resourceFactory->createPublicResource('EXT:agent_nexus/Resources/Public/Diagrams/a2ui.svg'),
            $request,
        );

        $categories = [];
        foreach ($this->registry->components($version) as $component) {
            $categories[$component->category][] = $this->component($component, $version, $imageUrl);
        }

        $view->assignMultiple([
            'version' => $version->value,
            'stable' => $version->isStable(),
            'catalogId' => $version->catalogId(),
            'versionLinks' => array_map(fn(array $entry): array => $entry + [
                'uri' => (string)$this->uriBuilder->buildUriFromRoute('agentnexus_a2ui_catalog', ['version' => $entry['value']]),
                'active' => $entry['value'] === $version->value,
            ], $this->versions()),
            'categories' => $categories,
            'common' => array_map($this->property(...), $this->registry->commonProperties($version)),
            'functions' => array_values(array_map(static fn(FunctionDefinition $function): array => [
                'name' => $function->name,
                'returnType' => $function->returnType,
                'arguments' => implode(', ', array_map(
                    static fn(PropertyDefinition $argument): string => $argument->name . ($argument->required ? '*' : ''),
                    array_values($function->arguments),
                )),
                'oneOf' => implode(' / ', $function->oneOfRequired),
            ], $this->registry->functions($version))),
            'theme' => array_map($this->property(...), $this->registry->themeProperties($version)),
        ]);

        return $view->renderResponse('A2ui/Catalog');
    }

    /**
     * @return list<array{value: string, stable: bool, catalogId: string}>
     */
    private function versions(): array
    {
        return array_map(static fn(A2uiVersion $version): array => [
            'value' => $version->value,
            'stable' => $version->isStable(),
            'catalogId' => $version->catalogId(),
        ], A2uiVersion::cases());
    }

    /**
     * @return array<string, mixed>
     */
    private function component(ComponentDefinition $component, A2uiVersion $version, string $imageUrl): array
    {
        return [
            'name' => $component->name,
            'anchor' => 'a2ui-component-' . strtolower($component->name),
            'checkable' => $component->isCheckable(),
            'properties' => array_values(array_map($this->property(...), $component->properties)),
            'messages' => (string)json_encode(
                $this->examples->messages($component->name, $version, $imageUrl),
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP,
            ),
        ];
    }

    /**
     * @return array{name: string, type: string, required: bool, values: string, default: string}
     */
    private function property(PropertyDefinition $property): array
    {
        return [
            'name' => $property->name,
            'type' => $property->type->value,
            'required' => $property->required,
            'values' => count($property->values) > 12
                ? implode(', ', array_slice($property->values, 0, 12)) . ' … (' . count($property->values) . ')'
                : implode(', ', $property->values),
            'default' => $property->default === null ? '' : (string)json_encode($property->default),
        ];
    }

    /**
     * @return list<array{surfaceId: string, label: string, version: string, state: string, stateBadge: string, source: string, updated: int, uri: string}>
     */
    private function recentSurfaces(): array
    {
        $rows = [];
        foreach ($this->objectStore->list(new ObjectFilter(ObjectKind::Surface), self::RECENT) as $object) {
            $rows[] = [
                'surfaceId' => $object->objectId,
                'label' => $object->label,
                'version' => is_string($object->payload['version'] ?? null) ? $object->payload['version'] : '',
                'state' => $object->state,
                'stateBadge' => InspectorPresenter::stateBadge(ObjectKind::Surface, $object->state),
                'source' => $object->source,
                'updated' => $object->tstamp,
                'uri' => $this->uri(ObjectKind::Surface->inspectorModule() . '.detail', ['uid' => $object->uid]),
            ];
        }
        return $rows;
    }

    /**
     * @param array<string, int|string> $parameters
     */
    private function uri(string $route, array $parameters = []): string
    {
        try {
            return (string)$this->uriBuilder->buildUriFromRoute($route, $parameters);
        } catch (RouteNotFoundException) {
            return '';
        }
    }
}
