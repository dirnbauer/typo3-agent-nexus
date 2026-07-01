<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Ap2\Controller;

use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Template\Components\ButtonBar;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Imaging\IconSize;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use Webconsulting\AgentNexus\Ap2\Service\MandateLog;

/**
 * Backend module: the AP2 Mandate Studio.
 *
 * - studio: build an Intent Mandate (spending limits), mint a Cart Mandate for a
 *   specific cart, then verify the authorization chain — and tamper with a token
 *   to watch verification fail.
 * - catalog: the Mandate Inspector — the mandate types and the checks the chain
 *   enforces.
 *
 * Everything is sandbox-signed; no real payment is ever initiated.
 */
#[AsController]
final class Ap2Controller extends ActionController
{
    /** Ordered design-system CSS: tokens → primitives → backend chrome → AP2 accents. */
    private const CSS = [
        'EXT:agent_nexus/Resources/Public/Css/nexus-tokens.css',
        'EXT:agent_nexus/Resources/Public/Css/nexus-ui.css',
        'EXT:agent_nexus/Resources/Public/Css/nexus-backend.css',
        'EXT:agent_nexus/Resources/Public/Css/modules/ap2.css',
    ];
    private const JS_STUDIO = '@webconsulting/agent-nexus/ap2-studio.js';

    /** Mandate types (for the Inspector). */
    private const TYPES = [
        ['type' => 'IntentMandate', 'accent' => 'intent', 'desc' => 'The human authorizes an agent to spend within limits (a cap, allowed merchants, an expiry). Often created while the human is present, used later when they are not.'],
        ['type' => 'CartMandate', 'accent' => 'cart', 'desc' => 'For a specific, fully-priced cart; references the Intent Mandate and proves this exact purchase is authorized.'],
        ['type' => 'PaymentMandate', 'accent' => 'payment', 'desc' => 'Conveys the authorized payment to the network with proof of the chain (simulated here as the verified result).'],
    ];

    /** The 2026 agent-nexus family. */
    private const STACK = [
        ['key' => 'MCP', 'label' => 'agent ↔ tools', 'accent' => 'mcp'],
        ['key' => 'A2A', 'label' => 'agent ↔ agent', 'accent' => 'a2a'],
        ['key' => 'AG-UI', 'label' => 'agent ↔ user', 'accent' => 'agui'],
        ['key' => 'A2UI', 'label' => 'agent ↔ UI', 'accent' => 'a2ui'],
        ['key' => 'UCP', 'label' => 'agent ↔ merchant', 'accent' => 'ucp'],
        ['key' => 'AP2', 'label' => 'authorization', 'accent' => 'ap2', 'self' => true],
    ];

    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly PageRenderer $pageRenderer,
        private readonly IconFactory $iconFactory,
        private readonly MandateLog $mandateLog,
    ) {}

    public function studioAction(): ResponseInterface
    {
        foreach (self::CSS as $cssFile) {
            $this->pageRenderer->addCssFile($cssFile);
        }
        $this->pageRenderer->loadJavaScriptModule(self::JS_STUDIO);

        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);
        $moduleTemplate->setTitle('AP2 Mandate Studio', 'Agent payment authorization');
        $this->addDocHeader($moduleTemplate, 'studio');
        $moduleTemplate->assignMultiple([
            'stack' => self::STACK,
            'today' => $this->mandateLog->getTodayTotals(),
        ]);

        return $moduleTemplate->renderResponse('Ap2/Studio');
    }

    public function catalogAction(): ResponseInterface
    {
        foreach (self::CSS as $cssFile) {
            $this->pageRenderer->addCssFile($cssFile);
        }

        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);
        $moduleTemplate->setTitle('AP2 Mandate Inspector');
        $this->addDocHeader($moduleTemplate, 'catalog');
        $moduleTemplate->assignMultiple([
            'types' => self::TYPES,
            'stack' => self::STACK,
        ]);

        return $moduleTemplate->renderResponse('Ap2/Catalog');
    }

    private function addDocHeader(ModuleTemplate $moduleTemplate, string $active): void
    {
        $buttonBar = $moduleTemplate->getDocHeaderComponent()->getButtonBar();

        if ($active !== 'studio') {
            $buttonBar->addButton(
                $buttonBar->makeLinkButton()
                    ->setIcon($this->iconFactory->getIcon('ap2-module', IconSize::SMALL))
                    ->setTitle('Studio')->setShowLabelText(true)
                    ->setHref($this->uriBuilder->reset()->uriFor('studio', [], 'Ap2')),
                ButtonBar::BUTTON_POSITION_LEFT,
                1,
            );
        }
        if ($active !== 'catalog') {
            $buttonBar->addButton(
                $buttonBar->makeLinkButton()
                    ->setIcon($this->iconFactory->getIcon('actions-list', IconSize::SMALL))
                    ->setTitle('Mandate Inspector')->setShowLabelText(true)
                    ->setHref($this->uriBuilder->reset()->uriFor('catalog', [], 'Ap2')),
                ButtonBar::BUTTON_POSITION_LEFT,
                2,
            );
        }
        $buttonBar->addButton(
            $buttonBar->makeShortcutButton()->setRouteIdentifier('agentstack_ap2')->setDisplayName('AP2 Mandate Studio'),
            ButtonBar::BUTTON_POSITION_RIGHT,
        );
    }
}
