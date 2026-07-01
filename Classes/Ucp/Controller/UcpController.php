<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Ucp\Controller;

use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Template\Components\ButtonBar;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Imaging\IconSize;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use Webconsulting\AgentNexus\Ucp\Service\Merchant;
use Webconsulting\AgentNexus\Ucp\Service\OrderLogger;

/**
 * Backend module: the UCP Console.
 *
 * - console: act as a shopping agent — discover the merchant manifest, pick a
 *   shopping intent, and watch the agent build a cart and run the checkout state
 *   machine, pausing for your purchase authorization before anything is placed.
 * - catalog: the Commerce Inspector — the merchant manifest, the product catalog
 *   and the checkout state machine.
 *
 * Every checkout is SIMULATED; no payment is taken.
 */
#[AsController]
final class UcpController extends ActionController
{
    /** Ordered: tokens -> primitives -> backend chrome -> module extras. */
    private const CSS_FILES = [
        'EXT:agent_nexus/Resources/Public/Css/nexus-tokens.css',
        'EXT:agent_nexus/Resources/Public/Css/nexus-ui.css',
        'EXT:agent_nexus/Resources/Public/Css/nexus-backend.css',
        'EXT:agent_nexus/Resources/Public/Css/modules/ucp.css',
    ];
    private const JS_CONSOLE = '@webconsulting/agent-nexus/ucp-console.js';

    /** @var array<int, array{id: string, label: string, hint: string}> */
    private const PRESETS = [
        ['id' => 'pro', 'label' => 'Set me up with Pro', 'hint' => 'The agent recommends and carts the Pro licence.'],
        ['id' => 'agency', 'label' => 'Full agency kit', 'hint' => 'Agency bundle + a guided onboarding add-on.'],
        ['id' => 'support', 'label' => 'Add priority support', 'hint' => 'Just the Priority Support Pack.'],
    ];

    /** The checkout state machine (for the Commerce Inspector). */
    private const STATES = [
        ['state' => 'discovering', 'accent' => 'discover', 'desc' => 'The agent reads the merchant manifest and catalog.'],
        ['state' => 'building_cart', 'accent' => 'cart', 'desc' => 'It assembles a cart from the catalog for your intent.'],
        ['state' => 'review', 'accent' => 'review', 'desc' => 'Cart assembled and totalled; ready to authorize.'],
        ['state' => 'authorization_required', 'accent' => 'auth', 'desc' => 'Paused — a human must authorize the purchase. Nothing is placed yet.'],
        ['state' => 'confirmed', 'accent' => 'confirmed', 'desc' => 'Authorized and placed (SIMULATED).'],
        ['state' => 'declined', 'accent' => 'declined', 'desc' => 'The human declined; nothing was placed.'],
    ];

    /** The 2026 agent-nexus family — shown as a legend in every sibling module. */
    private const STACK = [
        ['key' => 'MCP', 'label' => 'agent ↔ tools', 'accent' => 'mcp'],
        ['key' => 'A2A', 'label' => 'agent ↔ agent', 'accent' => 'a2a'],
        ['key' => 'AG-UI', 'label' => 'agent ↔ user', 'accent' => 'agui'],
        ['key' => 'A2UI', 'label' => 'agent ↔ UI', 'accent' => 'a2ui'],
        ['key' => 'UCP', 'label' => 'agent ↔ merchant', 'accent' => 'ucp', 'self' => true],
        ['key' => 'AP2', 'label' => 'authorization', 'accent' => 'ap2'],
    ];

    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly PageRenderer $pageRenderer,
        private readonly IconFactory $iconFactory,
        private readonly Merchant $merchant,
        private readonly OrderLogger $orderLogger,
    ) {}

    public function consoleAction(): ResponseInterface
    {
        foreach (self::CSS_FILES as $cssFile) {
            $this->pageRenderer->addCssFile($cssFile);
        }
        $this->pageRenderer->loadJavaScriptModule(self::JS_CONSOLE);

        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);
        $moduleTemplate->setTitle('UCP Console', 'Agent-driven checkout');
        $this->addDocHeader($moduleTemplate, 'console');
        $moduleTemplate->assignMultiple([
            'presets' => self::PRESETS,
            'stack' => self::STACK,
            'manifestUrl' => '/index.php?eID=ucp_manifest',
            'today' => $this->orderLogger->getTodayTotals(),
            'recent' => $this->orderLogger->getRecent(8),
        ]);

        return $moduleTemplate->renderResponse('Ucp/Console');
    }

    public function catalogAction(): ResponseInterface
    {
        foreach (self::CSS_FILES as $cssFile) {
            $this->pageRenderer->addCssFile($cssFile);
        }

        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);
        $moduleTemplate->setTitle('UCP Commerce Inspector');
        $this->addDocHeader($moduleTemplate, 'catalog');
        $moduleTemplate->assignMultiple([
            'catalog' => $this->merchant->catalog(),
            'states' => self::STATES,
            'stack' => self::STACK,
            'manifestUrl' => '/index.php?eID=ucp_manifest',
        ]);

        return $moduleTemplate->renderResponse('Ucp/Catalog');
    }

    private function addDocHeader(ModuleTemplate $moduleTemplate, string $active): void
    {
        $buttonBar = $moduleTemplate->getDocHeaderComponent()->getButtonBar();

        if ($active !== 'console') {
            $buttonBar->addButton(
                $buttonBar->makeLinkButton()
                    ->setIcon($this->iconFactory->getIcon('ucp-module', IconSize::SMALL))
                    ->setTitle('Console')->setShowLabelText(true)
                    ->setHref($this->uriBuilder->reset()->uriFor('console', [], 'Ucp')),
                ButtonBar::BUTTON_POSITION_LEFT,
                1,
            );
        }
        if ($active !== 'catalog') {
            $buttonBar->addButton(
                $buttonBar->makeLinkButton()
                    ->setIcon($this->iconFactory->getIcon('actions-list', IconSize::SMALL))
                    ->setTitle('Commerce Inspector')->setShowLabelText(true)
                    ->setHref($this->uriBuilder->reset()->uriFor('catalog', [], 'Ucp')),
                ButtonBar::BUTTON_POSITION_LEFT,
                2,
            );
        }
        $buttonBar->addButton(
            $buttonBar->makeShortcutButton()->setRouteIdentifier('agentstack_ucp')->setDisplayName('UCP Console'),
            ButtonBar::BUTTON_POSITION_RIGHT,
        );
    }
}
