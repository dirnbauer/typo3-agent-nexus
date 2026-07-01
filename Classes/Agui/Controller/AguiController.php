<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Agui\Controller;

use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Template\Components\ButtonBar;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Imaging\IconSize;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use Webconsulting\AgentNexus\Agui\Service\EventCatalog;
use Webconsulting\AgentNexus\Agui\Service\RunLogger;

/**
 * Backend module: the AG-UI Playground.
 *
 * - console: pick a task, start a run, and watch the agent stream typed AG-UI
 *   events live — with a human-in-the-loop Approve/Reject gate before any write.
 * - catalog: the Event Inspector — every AG-UI event type, grouped by family.
 */
#[AsController]
final class AguiController extends ActionController
{
    private const CSS_FILES = [
        'EXT:agent_nexus/Resources/Public/Css/nexus-tokens.css',
        'EXT:agent_nexus/Resources/Public/Css/nexus-ui.css',
        'EXT:agent_nexus/Resources/Public/Css/nexus-backend.css',
        'EXT:agent_nexus/Resources/Public/Css/modules/agui.css',
    ];
    private const JS_CONSOLE = '@webconsulting/agent-nexus/agui-console.js';

    /** @var array<int, array{id: string, label: string, hint: string}> */
    private const PRESETS = [
        ['id' => 'seo', 'label' => 'Generate SEO metadata', 'hint' => 'Draft a meta description for a page, apply only on approval.'],
        ['id' => 'translate', 'label' => 'Translate page titles', 'hint' => 'Shared-state demo: edit the live titles before approving.'],
        ['id' => 'news', 'label' => 'Draft a news article', 'hint' => 'Stream reasoning + body, create a draft on approval.'],
    ];

    /** The 2026 agent-nexus family — shown as a legend in every sibling module. */
    private const STACK = [
        ['key' => 'MCP', 'label' => 'agent ↔ tools', 'accent' => 'mcp'],
        ['key' => 'A2A', 'label' => 'agent ↔ agent', 'accent' => 'a2a'],
        ['key' => 'AG-UI', 'label' => 'agent ↔ user', 'accent' => 'agui', 'self' => true],
        ['key' => 'A2UI', 'label' => 'agent ↔ UI', 'accent' => 'a2ui'],
        ['key' => 'UCP', 'label' => 'agent ↔ merchant', 'accent' => 'ucp'],
        ['key' => 'AP2', 'label' => 'authorization', 'accent' => 'ap2'],
    ];

    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly PageRenderer $pageRenderer,
        private readonly IconFactory $iconFactory,
        private readonly EventCatalog $eventCatalog,
        private readonly RunLogger $runLogger,
    ) {}

    public function consoleAction(): ResponseInterface
    {
        foreach (self::CSS_FILES as $cssFile) {
            $this->pageRenderer->addCssFile($cssFile);
        }
        $this->pageRenderer->loadJavaScriptModule(self::JS_CONSOLE);

        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);
        $moduleTemplate->setTitle('AG-UI Playground', 'Live agent events');
        $this->addDocHeader($moduleTemplate, 'console');
        $moduleTemplate->assignMultiple([
            'presets' => self::PRESETS,
            'stack' => self::STACK,
            'today' => $this->runLogger->getTodayTotals(),
            'recent' => $this->runLogger->getRecent(8),
        ]);

        return $moduleTemplate->renderResponse('Agui/Console');
    }

    public function catalogAction(): ResponseInterface
    {
        foreach (self::CSS_FILES as $cssFile) {
            $this->pageRenderer->addCssFile($cssFile);
        }
        $this->pageRenderer->loadJavaScriptModule(self::JS_CONSOLE);

        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);
        $moduleTemplate->setTitle('AG-UI Event Inspector');
        $this->addDocHeader($moduleTemplate, 'catalog');
        $moduleTemplate->assignMultiple([
            'catalog' => $this->eventCatalog->all(),
            'stack' => self::STACK,
        ]);

        return $moduleTemplate->renderResponse('Agui/Catalog');
    }

    private function addDocHeader(ModuleTemplate $moduleTemplate, string $active): void
    {
        $buttonBar = $moduleTemplate->getDocHeaderComponent()->getButtonBar();

        if ($active !== 'console') {
            $buttonBar->addButton(
                $buttonBar->makeLinkButton()
                    ->setIcon($this->iconFactory->getIcon('agui-module', IconSize::SMALL))
                    ->setTitle('Run Console')->setShowLabelText(true)
                    ->setHref($this->uriBuilder->reset()->uriFor('console', [], 'Agui')),
                ButtonBar::BUTTON_POSITION_LEFT,
                1,
            );
        }
        if ($active !== 'catalog') {
            $buttonBar->addButton(
                $buttonBar->makeLinkButton()
                    ->setIcon($this->iconFactory->getIcon('actions-list', IconSize::SMALL))
                    ->setTitle('Event Inspector')->setShowLabelText(true)
                    ->setHref($this->uriBuilder->reset()->uriFor('catalog', [], 'Agui')),
                ButtonBar::BUTTON_POSITION_LEFT,
                2,
            );
        }
        $buttonBar->addButton(
            $buttonBar->makeShortcutButton()->setRouteIdentifier('agentstack_agui')->setDisplayName('AG-UI Playground'),
            ButtonBar::BUTTON_POSITION_RIGHT,
        );
    }
}
