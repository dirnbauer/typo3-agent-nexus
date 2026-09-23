<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Agentstack\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Routing\Route;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\Components\ButtonBar;
use TYPO3\CMS\Backend\Template\Components\ComponentFactory;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Imaging\IconSize;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Pagination\QueryBuilderPaginator;
use TYPO3\CMS\Core\Pagination\SimplePagination;
use Webconsulting\AgentNexus\Agentstack\Inspector\InspectorPresenter;
use Webconsulting\AgentNexus\Shared\Backend\ModuleFrame;
use Webconsulting\AgentNexus\Shared\Store\ObjectFilter;
use Webconsulting\AgentNexus\Shared\Store\ObjectKind;
use Webconsulting\AgentNexus\Shared\Store\ObjectStore;
use Webconsulting\AgentNexus\Shared\Traffic\Channel;

/**
 * The inspector: every object the protocols created, one screen per kind —
 * A2A tasks, AG-UI runs, UCP checkout sessions, AP2 mandates, A2UI surfaces.
 *
 * Each screen is a filterable list; the detail view reads the object in its
 * specification's shape and shows its state history, the exchanges in the
 * traffic log that touched it and the other objects of the same context.
 */
#[AsController]
final readonly class InspectorController
{
    public const string LANGUAGE_DOMAIN = 'agent_nexus.inspector';
    private const int PER_PAGE = 30;

    public function __construct(
        private ModuleFrame $moduleFrame,
        private ObjectStore $objectStore,
        private InspectorPresenter $presenter,
        private UriBuilder $uriBuilder,
        private ComponentFactory $componentFactory,
        private IconFactory $iconFactory,
    ) {}

    public function listAction(ServerRequestInterface $request): ResponseInterface
    {
        $kind = $this->kind($request);
        $query = $request->getQueryParams();
        $filter = ObjectFilter::fromArray($kind, $query);
        $page = max(1, is_numeric($query['page'] ?? null) ? (int)$query['page'] : 1);

        $paginator = new QueryBuilderPaginator($this->objectStore->listQuery($filter), $page, self::PER_PAGE);
        $rows = [];
        foreach ($this->objectStore->hydrateRows($this->rowsOf($paginator->getPaginatedItems())) as $object) {
            $rows[] = $this->presenter->row($object);
        }

        $view = $this->moduleFrame->create(
            $request,
            ['EXT:agent_nexus/Resources/Public/Css/modules/inspector.css'],
            [],
            $filter->toArray(),
        );
        $listUri = (string)$this->uriBuilder->buildUriFromRoute($kind->inspectorModule());
        $view->assignMultiple([
            'kind' => $kind->value,
            'protocol' => $kind->protocol()->value,
            'protocolLabel' => $kind->protocol()->label(),
            'rows' => $rows,
            'filter' => $filter,
            'filterArguments' => $filter->toArray(),
            'filterActive' => $filter->toArray() !== [],
            'states' => $this->objectStore->states($kind),
            'sources' => array_map(static fn(Channel $channel): string => $channel->value, Channel::cases()),
            'paginator' => $paginator,
            'pagination' => new SimplePagination($paginator),
            'route' => $kind->inspectorModule(),
            'listUri' => $listUri,
            'listHiddenFields' => $this->moduleFrame->hiddenFieldsOf($listUri),
        ]);

        return $view->renderResponse('Inspector/List');
    }

    public function detailAction(ServerRequestInterface $request): ResponseInterface
    {
        $kind = $this->kind($request);
        $query = $request->getQueryParams();
        $uid = is_numeric($query['uid'] ?? null) ? (int)$query['uid'] : 0;
        $object = $uid > 0 ? $this->objectStore->findByUid($uid) : null;
        if ($object !== null && $object->kind !== $kind) {
            $object = null;
        }
        $listUri = (string)$this->uriBuilder->buildUriFromRoute($kind->inspectorModule());

        $view = $this->moduleFrame->create(
            $request,
            ['EXT:agent_nexus/Resources/Public/Css/modules/inspector.css'],
            [],
            ['uid' => (string)$uid],
        );
        $view->getDocHeaderComponent()->getButtonBar()->addButton(
            $this->componentFactory->createLinkButton()
                ->setHref($listUri)
                ->setTitle($this->languageService()->sL(self::LANGUAGE_DOMAIN . ':detail.back'))
                ->setShowLabelText(true)
                ->setIcon($this->iconFactory->getIcon('actions-view-go-back', IconSize::SMALL)),
            ButtonBar::BUTTON_POSITION_LEFT,
            1,
        );
        $view->assignMultiple([
            'kind' => $kind->value,
            'protocol' => $kind->protocol()->value,
            'object' => $object !== null ? $this->presenter->detail($object) : null,
            'listUri' => $listUri,
            'trafficUri' => $object !== null
                ? (string)$this->uriBuilder->buildUriFromRoute('agentnexus_traffic', ['search' => $object->objectId])
                : '',
        ]);

        return $view->renderResponse('Inspector/Detail');
    }

    /** The object kind this screen lists, from the module's route option. */
    private function kind(ServerRequestInterface $request): ObjectKind
    {
        $route = $request->getAttribute('route');
        $kind = $route instanceof Route ? $route->getOption('agentnexus_kind') : null;
        return is_string($kind) ? (ObjectKind::tryFrom($kind) ?? ObjectKind::Task) : ObjectKind::Task;
    }

    /**
     * @param iterable<mixed> $items
     * @return list<array<string, mixed>>
     */
    private function rowsOf(iterable $items): array
    {
        $rows = [];
        foreach ($items as $item) {
            if (is_array($item)) {
                $row = [];
                foreach ($item as $key => $value) {
                    $row[(string)$key] = $value;
                }
                $rows[] = $row;
            }
        }
        return $rows;
    }

    private function languageService(): LanguageService
    {
        $languageService = $GLOBALS['LANG'] ?? null;
        if (!$languageService instanceof LanguageService) {
            throw new \RuntimeException('No backend language service is available.', 1758614404);
        }
        return $languageService;
    }
}
