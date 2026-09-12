<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Agentstack\Controller;

use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Package\PackageManager;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use Webconsulting\AgentNexus\Agentstack\Service\ProtocolStatusService;
use Webconsulting\AgentNexus\Agentstack\Service\SiteLocator;
use Webconsulting\AgentNexus\Shared\Llm\LlmClient;
use Webconsulting\AgentNexus\Shared\Llm\LlmUsageTracker;

/**
 * Agent Nexus — the hub.
 *
 * This module used to be a field guide: theory cards, a protocol map, a
 * comparison table, a glossary. All of that was explanation, and explanation now
 * lives where a reader actually needs it — in the frontend "Protocol info"
 * element and in the documentation. What an operator could never answer from the
 * backend was the practical question: *is this working here, right now?*
 *
 * So the hub answers exactly that. A header with the installed version, whether
 * a model is reachable and what today's spend is against the budget. One card
 * per protocol with its health (endpoints registered, storage folder present,
 * model on or off), when it last ran, how often in the last 24 hours, and the
 * three things you would want to do next: open the playground, open the live
 * page, or seed the site. Then the ten most recent events across all protocols,
 * and a setup panel that names what is still missing.
 */
#[AsController]
final class OverviewController extends ActionController
{
    /** Design-system CSS, loaded in this exact order. */
    private const CSS_FILES = [
        'EXT:agent_nexus/Resources/Public/Css/nexus-tokens.css',
        'EXT:agent_nexus/Resources/Public/Css/nexus-ui.css',
        'EXT:agent_nexus/Resources/Public/Css/nexus-backend.css',
    ];

    private const JS_OVERVIEW = '@webconsulting/agent-nexus/nexus-overview.js';

    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly PageRenderer $pageRenderer,
        private readonly ProtocolStatusService $protocolStatusService,
        private readonly SiteLocator $siteLocator,
        private readonly LlmClient $llmClient,
        private readonly LlmUsageTracker $usageTracker,
        private readonly ExtensionConfiguration $extensionConfiguration,
        private readonly PackageManager $packageManager,
    ) {}

    public function indexAction(): ResponseInterface
    {
        foreach (self::CSS_FILES as $file) {
            $this->pageRenderer->addCssFile($file);
        }
        $this->pageRenderer->loadJavaScriptModule(self::JS_OVERVIEW);

        $protocols = $this->protocolStatusService->all();

        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);
        $moduleTemplate->setTitle('Agent Nexus');
        $moduleTemplate->assignMultiple([
            'version' => $this->version(),
            'llm' => $this->llmSummary(),
            'protocols' => $protocols,
            'activity' => $this->protocolStatusService->recentActivity(),
            'setup' => $this->setup($protocols),
        ]);

        return $moduleTemplate->renderResponse('Overview/Index');
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
     * The header chip: is a model reachable at all, which one, and how much of
     * today's budget is gone.
     *
     * @return array{available: bool, model: string, provider: string, spentToday: float, budget: float, budgetPercent: int, overBudget: bool}
     */
    private function llmSummary(): array
    {
        $connection = $this->llmClient->getConnectionInfo();
        $budget = (float)($this->configuration()['llmDailyBudget'] ?? 0);
        $spent = $this->usageTracker->getCostToday();

        return [
            'available' => $connection !== null,
            'model' => $connection['model'] ?? '',
            'provider' => $connection['provider'] ?? '',
            'spentToday' => $spent,
            'budget' => $budget,
            'budgetPercent' => $budget > 0 ? (int)min(100, round($spent / $budget * 100)) : 0,
            'overBudget' => $budget > 0 && $spent >= $budget,
        ];
    }

    /**
     * What still needs doing before the demos are fully usable. Each entry is a
     * plain statement plus whether it is satisfied — no scoring, no badges.
     *
     * @param list<\Webconsulting\AgentNexus\Agentstack\Dto\ProtocolStatus> $protocols
     * @return array{
     *     items: list<array{label: string, done: bool, detail: string}>,
     *     storagePid: int,
     *     siteIdentifier: string,
     *     seedCommand: string
     * }
     */
    private function setup(array $protocols): array
    {
        $site = $this->siteLocator->site();
        $storagePid = $this->siteLocator->storagePid();
        $storageReady = $this->siteLocator->storageReady();
        $endpointsOk = array_reduce(
            $protocols,
            static fn(bool $carry, $protocol): bool => $carry && $protocol->endpointsRegistered,
            true,
        );

        return [
            'items' => [
                [
                    'label' => 'Demo site',
                    'done' => $site !== null,
                    'detail' => $site !== null
                        ? sprintf('Site "%s" on page %d', $site->getIdentifier(), $site->getRootPageId())
                        : 'No seeded site yet — run the seed command below.',
                ],
                [
                    'label' => 'Storage folder',
                    'done' => $storageReady,
                    'detail' => $storageReady
                        ? sprintf('Records are written to page %d.', $storagePid)
                        : 'Inquiries, leads and orders land on the page that triggered them.',
                ],
                [
                    'label' => 'Frontend endpoints',
                    'done' => $endpointsOk,
                    'detail' => $endpointsOk
                        ? 'All nine eID endpoints are registered.'
                        : 'Some eID endpoints are missing — flush caches and check ext_localconf.php.',
                ],
                [
                    'label' => 'Language model',
                    'done' => $this->llmClient->isAvailable(),
                    'detail' => $this->llmClient->isAvailable()
                        ? 'netresearch/nr-llm is installed; per-protocol toggles decide where it is used.'
                        : 'Not installed — every protocol runs its deterministic demo.',
                ],
            ],
            'storagePid' => $storagePid,
            'siteIdentifier' => $site?->getIdentifier() ?? 'agent-nexus',
            'seedCommand' => 'vendor/bin/typo3 agentnexus:seed-site --base=https://example.org/',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function configuration(): array
    {
        try {
            $configuration = $this->extensionConfiguration->get('agent_nexus');
            return is_array($configuration) ? $configuration : [];
        } catch (\Throwable) {
            return [];
        }
    }
}
