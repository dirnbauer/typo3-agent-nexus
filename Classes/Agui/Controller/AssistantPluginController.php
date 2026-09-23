<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Agui\Controller;

use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer;
use Webconsulting\AgentNexus\Agui\Http\AguiRoutes;
use Webconsulting\AgentNexus\Shared\Http\Api\RouteRegistry;

/**
 * The live assistant content element: a cacheable shell whose script is an
 * AG-UI 1.0 client of the public endpoint (POST /api/agent-nexus/ag-ui).
 *
 * The element passes its own uid and page along; the endpoint loads the
 * element's settings from the record, so nothing that controls the model
 * travels with the request.
 */
final class AssistantPluginController extends ActionController
{
    public function __construct(
        private readonly RouteRegistry $routes,
    ) {}

    public function showAction(): ResponseInterface
    {
        $contentObject = $this->request->getAttribute('currentContentObject');
        $data = $contentObject instanceof ContentObjectRenderer ? $contentObject->data : [];
        $pageId = $this->request->getAttribute('frontend.page.information')?->getId();

        $this->view->assignMultiple([
            'settings' => $this->settings,
            'data' => $data,
            'pageId' => $pageId ?? (is_numeric($data['pid'] ?? null) ? (int)$data['pid'] : 0),
            'endpoint' => $this->routes->get(AguiRoutes::RUN)?->uri($this->routes->apiBasePath()) ?? '',
        ]);

        return $this->htmlResponse();
    }
}
