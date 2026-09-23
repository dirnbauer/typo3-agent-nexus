<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Agentstack\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\Components\ButtonBar;
use TYPO3\CMS\Backend\Template\Components\ComponentFactory;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Imaging\IconSize;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Pagination\QueryBuilderPaginator;
use TYPO3\CMS\Core\Pagination\SimplePagination;
use Webconsulting\AgentNexus\Shared\Backend\ModuleFrame;
use Webconsulting\AgentNexus\Shared\Configuration\ExtensionSettings;
use Webconsulting\AgentNexus\Shared\Protocol;
use Webconsulting\AgentNexus\Shared\Traffic\Channel;
use Webconsulting\AgentNexus\Shared\Traffic\TrafficFilter;
use Webconsulting\AgentNexus\Shared\Traffic\TrafficPresenter;
use Webconsulting\AgentNexus\Shared\Traffic\TrafficRepository;

/**
 * The live traffic log: every request, response and streamed event of the
 * protocol endpoints.
 *
 * The list is server-rendered and filterable; "Follow live" polls for newer
 * entries and adds them at the top without a reload. The detail view shows one
 * exchange completely — headers, bodies, every streamed frame with its offset
 * — and links to the protocol object it touched.
 */
#[AsController]
final readonly class TrafficController
{
    private const int PER_PAGE = 50;
    private const int POLL_LIMIT = 50;

    public function __construct(
        private ModuleFrame $moduleFrame,
        private TrafficRepository $repository,
        private TrafficPresenter $presenter,
        private ExtensionSettings $settings,
        private UriBuilder $uriBuilder,
        private ComponentFactory $componentFactory,
        private IconFactory $iconFactory,
    ) {}

    public function listAction(ServerRequestInterface $request): ResponseInterface
    {
        $query = $request->getQueryParams();
        $filter = TrafficFilter::fromArray($query);
        $page = max(1, is_numeric($query['page'] ?? null) ? (int)$query['page'] : 1);

        $paginator = new QueryBuilderPaginator($this->repository->listQuery($filter), $page, self::PER_PAGE);
        $rows = [];
        foreach ($paginator->getPaginatedItems() as $row) {
            if (is_array($row)) {
                $rows[] = $this->presenter->row($row, $filter->toArray());
            }
        }

        $view = $this->moduleFrame->create(
            $request,
            ['EXT:agent_nexus/Resources/Public/Css/modules/traffic.css'],
            ['@webconsulting/agent-nexus/traffic-live.js'],
            $filter->toArray(),
        );
        $view->assignMultiple([
            'rows' => $rows,
            'filter' => $filter,
            'filterArguments' => $filter->toArray(),
            'paginator' => $paginator,
            'pagination' => new SimplePagination($paginator),
            'protocols' => array_map(static fn(Protocol $p): array => ['value' => $p->value, 'label' => $p->label()], Protocol::cases()),
            'channels' => array_map(static fn(Channel $c): string => $c->value, Channel::cases()),
            'periods' => array_keys(TrafficFilter::PERIODS),
            'latestUid' => $rows === [] ? $this->repository->latestUid() : $rows[0]['uid'],
            'recording' => $this->settings->trafficEnabled(),
            'capturesBodies' => $this->settings->trafficCaptureBodies(),
            'redacts' => $this->settings->trafficRedactPersonalData(),
            'retentionDays' => $this->settings->trafficRetentionDays(),
            'listUri' => $listUri = (string)$this->uriBuilder->buildUriFromRoute('agentnexus_traffic'),
            'listHiddenFields' => $this->moduleFrame->hiddenFieldsOf($listUri),
        ]);

        return $view->renderResponse('Traffic/List');
    }

    public function detailAction(ServerRequestInterface $request): ResponseInterface
    {
        $query = $request->getQueryParams();
        $uid = is_numeric($query['uid'] ?? null) ? (int)$query['uid'] : 0;
        $row = $uid > 0 ? $this->repository->findByUid($uid) : null;
        $returnArguments = is_array($query['return'] ?? null) ? TrafficFilter::fromArray($query['return'])->toArray() : [];
        $listUri = (string)$this->uriBuilder->buildUriFromRoute('agentnexus_traffic', $returnArguments);

        $view = $this->moduleFrame->create(
            $request,
            ['EXT:agent_nexus/Resources/Public/Css/modules/traffic.css'],
            [],
            ['uid' => (string)$uid],
            $this->languageService()->sL(TrafficPresenter::LANGUAGE_DOMAIN . ':detail.title'),
        );
        $view->getDocHeaderComponent()->getButtonBar()->addButton(
            $this->componentFactory->createLinkButton()
                ->setHref($listUri)
                ->setTitle($this->languageService()->sL(TrafficPresenter::LANGUAGE_DOMAIN . ':detail.back'))
                ->setShowLabelText(true)
                ->setIcon($this->iconFactory->getIcon('actions-view-go-back', IconSize::SMALL)),
            ButtonBar::BUTTON_POSITION_LEFT,
            1,
        );
        $view->assignMultiple([
            'entry' => $row !== null ? $this->presenter->detail($row) : null,
            'uid' => $uid,
            'listUri' => $listUri,
        ]);

        return $view->renderResponse('Traffic/Detail');
    }

    /**
     * Entries newer than the one the page shows first, for "Follow live".
     */
    public function pollAction(ServerRequestInterface $request): ResponseInterface
    {
        $query = $request->getQueryParams();
        $after = is_numeric($query['after'] ?? null) ? max(0, (int)$query['after']) : 0;
        $filter = TrafficFilter::fromArray($query);

        $rows = array_map(
            fn(array $row): array => $this->presenter->row($row, $filter->toArray()),
            $this->repository->findNewerThan($after, $filter, self::POLL_LIMIT),
        );

        return new JsonResponse([
            'rows' => array_reverse($rows),
            'latestUid' => $rows === [] ? $after : $rows[array_key_last($rows)]['uid'],
        ]);
    }

    private function languageService(): LanguageService
    {
        $languageService = $GLOBALS['LANG'] ?? null;
        if (!$languageService instanceof LanguageService) {
            throw new \RuntimeException('No backend language service is available.', 1758614403);
        }
        return $languageService;
    }
}
