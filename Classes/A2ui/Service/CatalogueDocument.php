<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\A2ui\Service;

use Webconsulting\AgentNexus\A2ui\Domain\Model\A2uiVersion;
use Webconsulting\AgentNexus\A2ui\Domain\Model\ComponentDefinition;
use Webconsulting\AgentNexus\A2ui\Domain\Model\FunctionDefinition;
use Webconsulting\AgentNexus\A2ui\Domain\Model\PropertyDefinition;
use Webconsulting\AgentNexus\A2ui\Domain\Repository\ComponentRegistry;
use Webconsulting\AgentNexus\Shared\Http\Api\RouteRegistry;

/**
 * What `GET /a2ui/catalog` answers: this agent's A2UI capabilities (valid
 * against `server_capabilities.json` and `agent_capabilities.json`), the
 * endpoints, and for each version the catalogue it generates — every
 * component with its properties, every function with its arguments.
 */
final readonly class CatalogueDocument
{
    public function __construct(
        private ComponentRegistry $registry,
        private RouteRegistry $routes,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function document(string $origin): array
    {
        $versions = [];
        foreach (A2uiVersion::cases() as $version) {
            $versions[] = $this->version($version);
        }

        return [
            'capabilities' => $this->registry->capabilities(),
            'defaultVersion' => A2uiVersion::DEFAULT->value,
            'endpoints' => [
                'surfaces' => $this->routes->url('a2ui.surfaces', $origin),
                'actions' => $this->routes->url('a2ui.actions', $origin),
                'catalog' => $this->routes->url('a2ui.catalog', $origin),
            ],
            'versions' => $versions,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function version(A2uiVersion $version): array
    {
        $describe = static fn(PropertyDefinition $property): array => $property->toArray();
        return [
            'version' => $version->value,
            'wireVersions' => $version->wireVersions(),
            'status' => $version->isStable() ? 'stable' : 'release candidate',
            'catalogId' => $version->catalogId(),
            'extensionUri' => $version->extensionUri(),
            'capabilitiesMember' => $version->capabilitiesMember(),
            'dataModelMember' => $version->dataModelMember(),
            'commonProperties' => array_map($describe, $this->registry->commonProperties($version)),
            'components' => array_values(array_map(
                static fn(ComponentDefinition $component): array => $component->toArray(),
                $this->registry->components($version),
            )),
            'functions' => array_values(array_map(
                static fn(FunctionDefinition $function): array => $function->toArray(),
                $this->registry->functions($version),
            )),
            'theme' => array_map($describe, $this->registry->themeProperties($version)),
        ];
    }
}
