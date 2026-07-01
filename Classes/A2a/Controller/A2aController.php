<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\A2a\Controller;

use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Template\Components\ButtonBar;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Imaging\IconSize;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use Webconsulting\AgentNexus\A2a\Service\SkillCatalog;
use Webconsulting\AgentNexus\A2a\Service\TaskLogger;

/**
 * Backend module: the A2A Console.
 *
 * - console: act as a client agent — discover the site's Agent Card, delegate a
 *   task and watch its lifecycle stream in (submitted → working → input-required
 *   → completed) with artifacts.
 * - catalog: the Skill Inspector — the skills the agent advertises and the task
 *   lifecycle states they move through.
 */
#[AsController]
final class A2aController extends ActionController
{
    private const CSS_FILES = [
        'EXT:agent_nexus/Resources/Public/Css/nexus-tokens.css',
        'EXT:agent_nexus/Resources/Public/Css/nexus-ui.css',
        'EXT:agent_nexus/Resources/Public/Css/nexus-backend.css',
        'EXT:agent_nexus/Resources/Public/Css/modules/a2a.css',
    ];
    private const JS_CONSOLE = '@webconsulting/agent-nexus/a2a-console.js';

    /** The lifecycle states an A2A task moves through (for the Skill Inspector). */
    private const STATES = [
        ['state' => 'submitted', 'accent' => 'submitted', 'desc' => 'Task created and acknowledged; not started yet.'],
        ['state' => 'working', 'accent' => 'working', 'desc' => 'The agent is actively processing the task.'],
        ['state' => 'input-required', 'accent' => 'input', 'desc' => 'Paused — the agent needs more input from the caller.'],
        ['state' => 'completed', 'accent' => 'completed', 'desc' => 'Finished successfully; artifacts are available.'],
        ['state' => 'failed', 'accent' => 'failed', 'desc' => 'Terminated by an error.'],
        ['state' => 'canceled', 'accent' => 'failed', 'desc' => 'Canceled by the caller.'],
    ];

    /** The 2026 agent-nexus family — shown as a legend in every sibling module. */
    private const STACK = [
        ['key' => 'MCP', 'label' => 'agent ↔ tools', 'accent' => 'mcp'],
        ['key' => 'A2A', 'label' => 'agent ↔ agent', 'accent' => 'a2a', 'self' => true],
        ['key' => 'AG-UI', 'label' => 'agent ↔ user', 'accent' => 'agui'],
        ['key' => 'A2UI', 'label' => 'agent ↔ UI', 'accent' => 'a2ui'],
        ['key' => 'UCP', 'label' => 'agent ↔ merchant', 'accent' => 'ucp'],
        ['key' => 'AP2', 'label' => 'authorization', 'accent' => 'ap2'],
    ];

    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly PageRenderer $pageRenderer,
        private readonly IconFactory $iconFactory,
        private readonly SkillCatalog $skillCatalog,
        private readonly TaskLogger $taskLogger,
    ) {}

    public function consoleAction(): ResponseInterface
    {
        foreach (self::CSS_FILES as $cssFile) {
            $this->pageRenderer->addCssFile($cssFile);
        }
        $this->pageRenderer->loadJavaScriptModule(self::JS_CONSOLE);

        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);
        $moduleTemplate->setTitle('A2A Console', 'Agent-to-agent tasks');
        $this->addDocHeader($moduleTemplate, 'console');
        $moduleTemplate->assignMultiple([
            'skills' => array_values($this->skillCatalog->all()),
            'stack' => self::STACK,
            'cardUrl' => '/index.php?eID=a2a_card',
            'today' => $this->taskLogger->getTodayTotals(),
            'recent' => $this->taskLogger->getRecent(8),
        ]);

        return $moduleTemplate->renderResponse('A2a/Console');
    }

    public function catalogAction(): ResponseInterface
    {
        foreach (self::CSS_FILES as $cssFile) {
            $this->pageRenderer->addCssFile($cssFile);
        }

        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);
        $moduleTemplate->setTitle('A2A Skill Inspector');
        $this->addDocHeader($moduleTemplate, 'catalog');
        $moduleTemplate->assignMultiple([
            'skills' => array_values($this->skillCatalog->all()),
            'states' => self::STATES,
            'stack' => self::STACK,
            'cardUrl' => '/index.php?eID=a2a_card',
        ]);

        return $moduleTemplate->renderResponse('A2a/Catalog');
    }

    private function addDocHeader(ModuleTemplate $moduleTemplate, string $active): void
    {
        $buttonBar = $moduleTemplate->getDocHeaderComponent()->getButtonBar();

        if ($active !== 'console') {
            $buttonBar->addButton(
                $buttonBar->makeLinkButton()
                    ->setIcon($this->iconFactory->getIcon('a2a-module', IconSize::SMALL))
                    ->setTitle('Console')->setShowLabelText(true)
                    ->setHref($this->uriBuilder->reset()->uriFor('console', [], 'A2a')),
                ButtonBar::BUTTON_POSITION_LEFT,
                1,
            );
        }
        if ($active !== 'catalog') {
            $buttonBar->addButton(
                $buttonBar->makeLinkButton()
                    ->setIcon($this->iconFactory->getIcon('actions-list', IconSize::SMALL))
                    ->setTitle('Skill Inspector')->setShowLabelText(true)
                    ->setHref($this->uriBuilder->reset()->uriFor('catalog', [], 'A2a')),
                ButtonBar::BUTTON_POSITION_LEFT,
                2,
            );
        }
        $buttonBar->addButton(
            $buttonBar->makeShortcutButton()->setRouteIdentifier('agentstack_a2a')->setDisplayName('A2A Console'),
            ButtonBar::BUTTON_POSITION_RIGHT,
        );
    }
}
