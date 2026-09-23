<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Agentstack\Service;

use TYPO3\CMS\Backend\Routing\UriBuilder as BackendUriBuilder;
use TYPO3\CMS\Core\SingletonInterface;
use Webconsulting\AgentNexus\Agentstack\Dto\ProtocolStatus;
use Webconsulting\AgentNexus\Shared\Http\Api\RouteRegistry;
use Webconsulting\AgentNexus\Shared\Llm\LlmGuard;
use Webconsulting\AgentNexus\Shared\Protocol;
use Webconsulting\AgentNexus\Shared\Store\ObjectStore;
use Webconsulting\AgentNexus\Shared\Store\ProtocolObject;

/**
 * Answers the overview's first question: is each protocol working here?
 *
 * "Working" is concrete: the protocol's endpoints are routed, a storage folder
 * exists for what its widget records, and a model is either available or
 * deliberately off. The evidence that it ran comes from the object store —
 * when the protocol last touched a task, run, checkout, mandate or surface,
 * and how many it touched in the last 24 hours.
 *
 * Every lookup is defensive. An overview that throws because a table has not
 * been created yet would be worse than one that says "no activity".
 */
final class ProtocolStatusService implements SingletonInterface
{
    private const int DAY = 86400;

    public function __construct(
        private readonly ObjectStore $objectStore,
        private readonly ProtocolCatalog $protocolCatalog,
        private readonly SiteLocator $siteLocator,
        private readonly LlmGuard $llmGuard,
        private readonly BackendUriBuilder $backendUriBuilder,
        private readonly RouteRegistry $routeRegistry,
        private readonly SpecificationVersions $specificationVersions,
    ) {}

    /**
     * @return list<ProtocolStatus>
     */
    public function all(): array
    {
        $storageReady = $this->siteLocator->storageReady();

        return array_map(
            fn(Protocol $protocol): ProtocolStatus => $this->build($protocol, $storageReady),
            Protocol::cases(),
        );
    }

    private function build(Protocol $protocol, bool $storageReady): ProtocolStatus
    {
        $meta = $this->protocolCatalog->get($protocol->value);
        $routes = $this->routeRegistry->forProtocol($protocol);
        $llm = $this->llmGuard->allows($protocol->value);
        $module = 'agentnexus_' . $protocol->value;

        return new ProtocolStatus(
            key: $protocol->value,
            label: $meta['label'],
            name: $meta['name'],
            tagline: $meta['tagline'],
            icon: 'agentnexus-module-' . $protocol->value,
            endpointsRegistered: $routes !== [],
            endpointCount: count($routes),
            llmEnabled: $llm['allowed'],
            llmReason: $llm['reason'],
            storageReady: $storageReady,
            lastRun: $this->safely(fn(): ?int => $this->objectStore->lastActivity($protocol), null),
            runsLast24h: $this->safely(fn(): int => $this->objectStore->countSince($protocol, time() - self::DAY), 0),
            moduleIdentifier: $module,
            playgroundUri: $this->moduleUri($module),
            frontendUrl: $this->siteLocator->protocolUrl($protocol->value),
            specVersion: $this->specificationVersions->for($protocol)['implemented'],
        );
    }

    /**
     * The objects the protocols touched most recently, for the overview's
     * activity list.
     *
     * @return list<array{protocol: string, label: string, object: string, state: string, time: int, uri: string}>
     */
    public function recentActivity(int $limit = 10): array
    {
        $objects = $this->safely(fn(): array => $this->objectStore->recent($limit), []);

        return array_map(fn(ProtocolObject $object): array => [
            'protocol' => $object->kind->protocol()->value,
            'label' => $object->kind->protocol()->label(),
            'object' => $object->label !== '' ? $object->label : $object->objectId,
            'state' => $object->state,
            'time' => $object->tstamp,
            'uri' => $this->routeUri($object->kind->inspectorModule() . '.detail', ['uid' => $object->uid]),
        ], $objects);
    }

    /**
     * The link to a module, resolved through the backend router rather than
     * assembled by hand, so a module path change cannot break it.
     */
    private function moduleUri(string $identifier): string
    {
        return $this->routeUri($identifier);
    }

    /**
     * @param array<string, int|string> $parameters
     */
    private function routeUri(string $identifier, array $parameters = []): string
    {
        try {
            return (string)$this->backendUriBuilder->buildUriFromRoute($identifier, $parameters);
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * @template T
     * @param \Closure(): T $query
     * @param T $fallback
     * @return T
     */
    private function safely(\Closure $query, mixed $fallback): mixed
    {
        try {
            return $query();
        } catch (\Throwable) {
            return $fallback;
        }
    }
}
