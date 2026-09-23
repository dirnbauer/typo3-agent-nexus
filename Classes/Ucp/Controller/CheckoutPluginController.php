<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Ucp\Controller;

use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer;
use Webconsulting\AgentNexus\Shared\Http\Api\RouteRegistry;

/**
 * The checkout widget: a visitor asks the shopping agent to buy something.
 *
 * The element renders a cacheable shell. Its JavaScript talks AG-UI to the
 * shopping agent endpoint (`{api}/ucp/agent`); the agent talks UCP to the
 * store and stops for the visitor's approval before anything is ordered. The
 * widget sends its content element and page with every run, so the server
 * loads the element's own settings and files the checkout on the site's
 * storage page.
 */
final class CheckoutPluginController extends ActionController
{
    public function __construct(
        private readonly RouteRegistry $routes,
    ) {}

    public function showAction(): ResponseInterface
    {
        $contentObject = $this->request->getAttribute('currentContentObject');
        $data = $contentObject instanceof ContentObjectRenderer ? $contentObject->data : [];
        $pageId = (int)($this->request->getAttribute('frontend.page.information')?->getId() ?? ($data['pid'] ?? 0));

        $this->view->assignMultiple([
            'settings' => $this->settings,
            'data' => $data,
            'pageId' => $pageId,
            'agentUrl' => $this->routes->get('ucp.agent')?->uri($this->routes->apiBasePath()) ?? '',
        ]);

        return $this->htmlResponse();
    }
}
