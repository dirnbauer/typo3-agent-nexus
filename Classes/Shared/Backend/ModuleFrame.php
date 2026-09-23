<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Shared\Backend;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Module\ModuleInterface;
use TYPO3\CMS\Backend\Template\Components\ButtonBar;
use TYPO3\CMS\Backend\Template\Components\ComponentFactory;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Page\PageRenderer;

/**
 * The chrome every Agent Nexus backend screen shares.
 *
 * One call gives a module the same DocHeader as every other: the title from
 * the module's own label, the v14 submodule menu (the third-level screens of a
 * protocol, of the inspector), a reload button and a shortcut. It also loads
 * the design-system layers — tokens first, then primitives, then the backend
 * layer — so a module only names what it adds on top.
 */
final readonly class ModuleFrame
{
    public const string LANGUAGE_DOMAIN = 'agent_nexus.modules';

    /** Loaded on every screen, in this order. */
    private const array BASE_STYLESHEETS = [
        'EXT:agent_nexus/Resources/Public/Css/nexus-tokens.css',
        'EXT:agent_nexus/Resources/Public/Css/nexus-ui.css',
        'EXT:agent_nexus/Resources/Public/Css/nexus-backend.css',
    ];

    public function __construct(
        private ModuleTemplateFactory $moduleTemplateFactory,
        private ComponentFactory $componentFactory,
        private PageRenderer $pageRenderer,
    ) {}

    /**
     * @param list<string> $stylesheets        EXT: paths loaded after the base layers
     * @param list<string> $javaScriptModules  import map specifiers
     * @param array<string, string> $shortcutArguments route arguments the shortcut keeps
     */
    public function create(
        ServerRequestInterface $request,
        array $stylesheets = [],
        array $javaScriptModules = [],
        array $shortcutArguments = [],
        ?string $subtitle = null,
    ): ModuleTemplate {
        foreach ([...self::BASE_STYLESHEETS, ...$stylesheets] as $stylesheet) {
            $this->pageRenderer->addCssFile($stylesheet);
        }
        foreach ($javaScriptModules as $module) {
            $this->pageRenderer->loadJavaScriptModule($module);
        }

        $moduleTemplate = $this->moduleTemplateFactory->create($request);
        $module = $request->getAttribute('module');
        $languageService = $this->languageService();

        if ($module instanceof ModuleInterface) {
            $title = $languageService->sL($module->getTitle());
            $parent = $module->getParentModule();
            $sectionTitle = $parent !== null && $parent->getParentModule() !== null
                ? $languageService->sL($parent->getTitle())
                : $title;
            $moduleTemplate->setTitle($sectionTitle, $subtitle ?? ($sectionTitle !== $title ? $title : ''));
            $moduleTemplate->makeDocHeaderModuleMenu();
            $moduleTemplate->getDocHeaderComponent()->setShortcutContext(
                $module->getIdentifier(),
                $sectionTitle === $title ? $title : $sectionTitle . ': ' . $title,
                $shortcutArguments,
            );
        }

        $moduleTemplate->getDocHeaderComponent()->getButtonBar()->addButton(
            $this->componentFactory->createReloadButton($request->getUri()),
            ButtonBar::BUTTON_POSITION_RIGHT,
        );

        return $moduleTemplate;
    }

    /**
     * The query of a module URL as hidden form fields. A GET filter form
     * replaces the query string of its action, so the route token has to
     * travel as a field.
     *
     * @return array<string, string>
     */
    public function hiddenFieldsOf(string $uri): array
    {
        parse_str((string)parse_url($uri, PHP_URL_QUERY), $query);
        $fields = [];
        foreach ($query as $name => $value) {
            if (is_string($value)) {
                $fields[(string)$name] = $value;
            }
        }
        return $fields;
    }

    /** A label from this extension's module label file. */
    public function label(string $key): string
    {
        return $this->languageService()->sL(self::LANGUAGE_DOMAIN . ':' . $key);
    }

    private function languageService(): LanguageService
    {
        $languageService = $GLOBALS['LANG'] ?? null;
        if (!$languageService instanceof LanguageService) {
            throw new \RuntimeException('No backend language service is available.', 1758614402);
        }
        return $languageService;
    }
}
