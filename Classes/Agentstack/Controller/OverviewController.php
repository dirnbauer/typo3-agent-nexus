<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Agentstack\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Package\PackageManager;
use Webconsulting\AgentNexus\Agentstack\Dto\ProtocolStatus;
use Webconsulting\AgentNexus\Agentstack\Service\ProtocolStatusService;
use Webconsulting\AgentNexus\Agentstack\Service\SiteLocator;
use Webconsulting\AgentNexus\Agentstack\Service\SpecificationVersions;
use Webconsulting\AgentNexus\Shared\Backend\ModuleFrame;
use Webconsulting\AgentNexus\Shared\Configuration\ExtensionSettings;
use Webconsulting\AgentNexus\Shared\Http\Api\RouteRegistry;
use Webconsulting\AgentNexus\Shared\Llm\LanguageModel;
use Webconsulting\AgentNexus\Shared\Llm\UsageLedger;
use Webconsulting\AgentNexus\Shared\Traffic\TrafficRepository;

/**
 * Agent Nexus — the overview.
 *
 * It answers what an operator opens the backend for: is each protocol working
 * here, which version of its specification does it implement, and what ran
 * recently. One card per protocol (health, specification version, activity,
 * the way into its console, its demo page and its objects), the specification
 * table with the newest published versions, the discovery documents this
 * installation publishes, the most recent protocol objects and a setup list
 * that names what is still missing.
 */
#[AsController]
final readonly class OverviewController
{
    private const int DAY = 86400;

    public function __construct(
        private ModuleFrame $moduleFrame,
        private ProtocolStatusService $protocolStatusService,
        private SpecificationVersions $specificationVersions,
        private SiteLocator $siteLocator,
        private RouteRegistry $routeRegistry,
        private TrafficRepository $trafficRepository,
        private LanguageModel $languageModel,
        private UsageLedger $usageLedger,
        private ExtensionSettings $settings,
        private PackageManager $packageManager,
        private UriBuilder $uriBuilder,
    ) {}

    public function indexAction(ServerRequestInterface $request): ResponseInterface
    {
        $protocols = $this->protocolStatusService->all();
        $origin = $request->getUri()->getScheme() . '://' . $request->getUri()->getAuthority();
        $exchanges = $this->safely(fn(): array => $this->trafficRepository->countByProtocolSince(time() - self::DAY), []);

        $view = $this->moduleFrame->create($request);
        $view->assignMultiple([
            'version' => $this->version(),
            'llm' => $this->llmSummary(),
            'cards' => array_map(static fn(ProtocolStatus $protocol): array => [
                'protocol' => $protocol,
                'exchanges' => $exchanges[$protocol->key] ?? 0,
                'healthBadge' => match ($protocol->health) {
                    ProtocolStatus::HEALTH_OK => 'success',
                    ProtocolStatus::HEALTH_WARN => 'warning',
                    default => 'danger',
                },
            ], $protocols),
            'specifications' => $this->specificationVersions->all(),
            'checked' => SpecificationVersions::CHECKED,
            'discovery' => $this->discovery($origin),
            'apiBase' => $origin . $this->routeRegistry->apiBasePath(),
            'activity' => $this->protocolStatusService->recentActivity(),
            'setup' => $this->setup($protocols),
            'inspectorUri' => (string)$this->uriBuilder->buildUriFromRoute('agentnexus_inspector'),
            'trafficUri' => (string)$this->uriBuilder->buildUriFromRoute('agentnexus_traffic'),
        ]);

        return $view->renderResponse('Overview/Index');
    }

    private function version(): string
    {
        try {
            return $this->packageManager->getPackage('agent_nexus')->getPackageMetaData()->getVersion();
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * Whether a model is reachable, which one, and how much of today's budget
     * is spent.
     *
     * @return array{available: bool, model: string, provider: string, spentToday: string, budget: string, budgetPercent: int, overBudget: bool, hasBudget: bool}
     */
    private function llmSummary(): array
    {
        $connection = $this->languageModel->getConnectionInfo();
        $budget = $this->settings->float('llmDailyBudget', 2.0);
        $spent = $this->safely(fn(): float => $this->usageLedger->getCostToday(), 0.0);

        return [
            'available' => $connection !== null,
            'model' => $connection['model'] ?? '',
            'provider' => $connection['provider'] ?? '',
            'spentToday' => number_format($spent, 2),
            'budget' => number_format($budget, 2),
            'budgetPercent' => $budget > 0 ? (int)min(100, round($spent / $budget * 100)) : 0,
            'overBudget' => $budget > 0 && $spent >= $budget,
            'hasBudget' => $budget > 0,
        ];
    }

    /**
     * The documents another agent reads first, when this installation
     * publishes them.
     *
     * @return list<array{protocol: string, label: string, url: string}>
     */
    private function discovery(string $origin): array
    {
        $documents = [];
        foreach ($this->routeRegistry->all() as $route) {
            if ($route->wellKnown) {
                $documents[] = [
                    'protocol' => $route->protocol->value,
                    'label' => $route->protocol->label(),
                    'url' => $this->routeRegistry->url($route->id, $origin),
                ];
            }
        }
        return $documents;
    }

    /**
     * What still needs doing before the demos are fully usable: each entry a
     * key into the label file plus whether it is done and its detail values.
     *
     * @param list<ProtocolStatus> $protocols
     * @return list<array{key: string, done: bool, arguments: list<string>}>
     */
    private function setup(array $protocols): array
    {
        $site = $this->siteLocator->site();
        $storagePid = $this->siteLocator->storagePid();
        $routed = array_all($protocols, static fn(ProtocolStatus $protocol): bool => $protocol->endpointsRegistered);

        return [
            [
                'key' => $site !== null ? 'site.done' : 'site.todo',
                'done' => $site !== null,
                'arguments' => $site !== null ? [$site->getIdentifier(), (string)$site->getRootPageId()] : [],
            ],
            [
                'key' => $this->siteLocator->storageReady() ? 'storage.done' : 'storage.todo',
                'done' => $this->siteLocator->storageReady(),
                'arguments' => [(string)$storagePid],
            ],
            [
                'key' => $routed ? 'endpoints.done' : 'endpoints.todo',
                'done' => $routed,
                'arguments' => [$this->routeRegistry->apiBasePath()],
            ],
            [
                'key' => $this->languageModel->isAvailable() ? 'model.done' : 'model.todo',
                'done' => $this->languageModel->isAvailable(),
                'arguments' => [],
            ],
            [
                'key' => $this->settings->trafficEnabled() ? 'traffic.done' : 'traffic.todo',
                'done' => $this->settings->trafficEnabled(),
                'arguments' => [(string)$this->settings->trafficRetentionDays()],
            ],
        ];
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
