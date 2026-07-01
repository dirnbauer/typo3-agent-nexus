<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\A2ui\Controller;

use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Template\Components\ButtonBar;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Imaging\IconSize;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use Webconsulting\AgentNexus\A2ui\Domain\Repository\ComponentRegistry;
use Webconsulting\AgentNexus\A2ui\Service\AgentService;
use Webconsulting\AgentNexus\A2ui\Service\RendererService;

/**
 * Backend module: the A2UI Playground.
 *
 * - dashboard: the live playground (intent -> agent -> A2UI JSON -> rendered UI)
 * - generate:  the agent endpoint (JSON for the playground, HTML for a permalink)
 * - demo:      a server-side rendered gallery of the trusted component catalog
 */
#[AsController]
final class A2UIController extends ActionController
{
    private const JS_MODULE = '@webconsulting/agent-nexus/a2ui-playground.js';

    /** Nexus design system first, then the small A2UI-specific layer. Order matters. */
    private const CSS_FILES = [
        'EXT:agent_nexus/Resources/Public/Css/nexus-tokens.css',
        'EXT:agent_nexus/Resources/Public/Css/nexus-ui.css',
        'EXT:agent_nexus/Resources/Public/Css/nexus-backend.css',
        'EXT:agent_nexus/Resources/Public/Css/modules/a2ui.css',
    ];

    /**
     * Natural-language presets that make the concept land without any typing.
     *
     * @var array<int, array{label: string, intent: string}>
     */
    private const PRESETS = [
        ['label' => 'Plan a landing page', 'intent' => 'Create a new landing page'],
        ['label' => 'Event registration', 'intent' => 'Collect event registration details'],
        ['label' => 'SEO metadata', 'intent' => 'Edit the SEO metadata for this page'],
        ['label' => 'Newsletter signup', 'intent' => 'A newsletter signup form'],
    ];

    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly PageRenderer $pageRenderer,
        private readonly IconFactory $iconFactory,
        private readonly AgentService $agentService,
        private readonly RendererService $rendererService,
        private readonly ComponentRegistry $componentRegistry,
    ) {}

    /**
     * The live playground.
     */
    public function dashboardAction(): ResponseInterface
    {
        $this->addStylesheets();
        $this->pageRenderer->loadJavaScriptModule(self::JS_MODULE);

        $endpoint = $this->uriBuilder->reset()->uriFor('generate', ['format' => 'json'], 'A2UI');
        $respondEndpoint = $this->uriBuilder->reset()->uriFor('respond', [], 'A2UI');

        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);
        $moduleTemplate->setTitle('A2UI Playground', 'Agent-driven UI');
        $this->addDocHeader($moduleTemplate, 'playground');
        $moduleTemplate->assignMultiple([
            'endpoint' => $endpoint,
            'respondEndpoint' => $respondEndpoint,
            'presets' => self::PRESETS,
            'catalog' => $this->componentRegistry->getCatalogManifest(),
            'llmAvailable' => $this->agentService->isLlmAvailable(),
            'connection' => $this->agentService->getConnectionInfo(),
            'cost' => $this->agentService->getCostSummary(3),
        ]);

        return $moduleTemplate->renderResponse('A2UI/Dashboard');
    }

    /**
     * The agent endpoint. format=json powers the playground; HTML serves a
     * shareable, server-rendered permalink of a generated surface.
     */
    public function generateAction(): ResponseInterface
    {
        $intent = $this->request->hasArgument('intent') ? trim((string)$this->request->getArgument('intent')) : '';
        $format = $this->request->hasArgument('format') ? (string)$this->request->getArgument('format') : 'html';

        if ($intent === '') {
            return $this->jsonResponse((string)json_encode(['success' => false, 'error' => 'Intent parameter required']));
        }

        $context = [
            'pageId' => (int)($this->request->getQueryParams()['id'] ?? 0),
            'beUser' => $GLOBALS['BE_USER']->user['username'] ?? 'unknown',
            'language' => $GLOBALS['BE_USER']->user['lang'] ?? 'en',
        ];

        $result = $this->agentService->generate($intent, $context);
        $surface = $result->getSurface();

        if ($format === 'json') {
            return $this->jsonResponse((string)json_encode([
                'success' => true,
                'intent' => $intent,
                'provenance' => [
                    'source' => $result->getSource(),
                    'model' => $result->getModel(),
                    'label' => $result->getProvenanceLabel(),
                    'notes' => $result->getNotes(),
                ],
                'payload' => $surface->toMessage(),
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }

        $this->addStylesheets();

        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);
        $moduleTemplate->setTitle('Generated UI', $intent);
        $this->addDocHeader($moduleTemplate, 'generate');
        $moduleTemplate->assignMultiple([
            'intent' => $intent,
            'provenanceLabel' => $result->getProvenanceLabel(),
            'isLlm' => $result->isLlm(),
            'notes' => $result->getNotes(),
            'renderedHtml' => $this->rendererService->renderStatic($surface, $this->request),
            'jsonPayload' => $this->rendererService->toJson($surface),
        ]);

        return $moduleTemplate->renderResponse('A2UI/Generate');
    }

    /**
     * The other half of the bidirectional loop: the agent reacts to a userAction
     * and streams back an `actionResponse` — here, a confirmation section that the
     * client appends to the live surface (Emit → Render → Signal → Reason).
     */
    public function respondAction(): ResponseInterface
    {
        $action = $this->request->hasArgument('actionName') ? (string)$this->request->getArgument('actionName') : '';
        $contextRaw = $this->request->hasArgument('context') ? (string)$this->request->getArgument('context') : '{}';
        $context = json_decode($contextRaw, true);
        $context = is_array($context) ? $context : [];

        $name = '';
        foreach (['name', 'fullName', 'title', 'email'] as $key) {
            if (!empty($context[$key]) && is_string($context[$key])) {
                $name = $context[$key];
                break;
            }
        }
        $greeting = $name !== '' ? 'Thanks, ' . $name . '!' : 'Thank you!';
        $ackText = sprintf(
            '%s The agent received your %s request and a colleague will follow up shortly.',
            $greeting,
            $this->humanizeAction($action),
        );

        // Echo back a short summary of what was submitted — proof the data round-tripped.
        $children = ['a2ui_ack_text'];
        $components = [['id' => 'a2ui_ack_text', 'component' => 'Text', 'text' => $ackText]];
        $i = 0;
        foreach ($context as $key => $value) {
            if ($i >= 5 || is_array($value) || $value === '' || $value === null) {
                continue;
            }
            $id = 'a2ui_ack_s' . $i;
            $printable = is_bool($value) ? ($value ? 'yes' : 'no') : (string)$value;
            $components[] = ['id' => $id, 'component' => 'Text', 'text' => $this->labelize($key) . ': ' . $printable, 'variant' => 'muted'];
            $children[] = $id;
            $i++;
        }
        $components[] = ['id' => 'a2ui_ack', 'component' => 'Card', 'title' => '✓ Request received', 'children' => $children];

        return $this->jsonResponse((string)json_encode([
            'success' => true,
            'ackId' => 'a2ui_ack',
            'components' => $components,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function humanizeAction(string $action): string
    {
        $words = trim((string)preg_replace('/([a-z])([A-Z])/', '$1 $2', $action));
        $words = strtolower(str_replace(['_', '-'], ' ', $words));
        return $words !== '' ? $words : 'submission';
    }

    private function labelize(string $key): string
    {
        $words = (string)preg_replace('/([a-z])([A-Z])/', '$1 $2', $key);
        $words = trim(str_replace(['_', '-'], ' ', $words));
        return ucfirst(strtolower($words));
    }

    /**
     * Server-side rendered gallery of the trusted catalog (no-JS path).
     */
    public function demoAction(): ResponseInterface
    {
        $this->addStylesheets();

        $examples = [
            'Page creation' => 'create page',
            'Content editor' => 'edit content',
            'SEO metadata' => 'seo optimization',
            'Event registration' => 'event registration',
            'Newsletter signup' => 'newsletter signup',
            'Scheduling' => 'schedule publication',
        ];

        $rendered = [];
        foreach ($examples as $title => $intent) {
            $surface = $this->agentService->generateOffline($intent);
            $rendered[$title] = [
                'html' => $this->rendererService->renderStatic($surface, $this->request),
                'json' => $this->rendererService->toJson($surface),
            ];
        }

        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);
        $moduleTemplate->setTitle('A2UI Component Gallery');
        $this->addDocHeader($moduleTemplate, 'gallery');
        $moduleTemplate->assignMultiple([
            'examples' => $rendered,
            'catalog' => $this->componentRegistry->getCatalogManifest(),
        ]);

        return $moduleTemplate->renderResponse('A2UI/Demo');
    }

    private function addStylesheets(): void
    {
        foreach (self::CSS_FILES as $file) {
            $this->pageRenderer->addCssFile($file);
        }
    }

    /**
     * Populate the module doc header (the top bar / breadcrumb area) with
     * navigation between the playground and the gallery plus a shortcut button.
     */
    private function addDocHeader(ModuleTemplate $moduleTemplate, string $active): void
    {
        $buttonBar = $moduleTemplate->getDocHeaderComponent()->getButtonBar();

        if ($active !== 'playground') {
            $playgroundUrl = $this->uriBuilder->reset()->uriFor('dashboard', [], 'A2UI');
            $buttonBar->addButton(
                $buttonBar->makeLinkButton()
                    ->setIcon($this->iconFactory->getIcon('a2ui-module', IconSize::SMALL))
                    ->setTitle('Playground')
                    ->setShowLabelText(true)
                    ->setHref($playgroundUrl),
                ButtonBar::BUTTON_POSITION_LEFT,
                1,
            );
        }

        if ($active !== 'gallery') {
            $galleryUrl = $this->uriBuilder->reset()->uriFor('demo', [], 'A2UI');
            $buttonBar->addButton(
                $buttonBar->makeLinkButton()
                    ->setIcon($this->iconFactory->getIcon('actions-list-alternative', IconSize::SMALL))
                    ->setTitle('Component gallery')
                    ->setShowLabelText(true)
                    ->setHref($galleryUrl),
                ButtonBar::BUTTON_POSITION_LEFT,
                2,
            );
        }

        $shortcutButton = $buttonBar->makeShortcutButton()
            ->setRouteIdentifier('agentstack_a2ui')
            ->setDisplayName('A2UI Playground');
        $buttonBar->addButton($shortcutButton, ButtonBar::BUTTON_POSITION_RIGHT);
    }
}
