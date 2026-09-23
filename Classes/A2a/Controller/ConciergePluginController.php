<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\A2a\Controller;

use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer;
use TYPO3\CMS\Frontend\Page\PageInformation;
use Webconsulting\AgentNexus\A2a\Service\SkillCatalog;
use Webconsulting\AgentNexus\Shared\Http\Api\RouteRegistry;

/**
 * Frontend plugin: the A2A concierge.
 *
 * Renders the widget shell; the widget itself is a client of the site's
 * public A2A endpoint. It sends SendStreamingMessage (A2A 1.0) to the
 * JSON-RPC binding and names its content element in the message metadata, so
 * the endpoint can load this element's settings server side. The shell is
 * cacheable: the skill chips come from the skill catalogue, the endpoint path
 * from the API routes, and neither depends on the visitor.
 */
final class ConciergePluginController extends ActionController
{
    public function __construct(
        private readonly SkillCatalog $skills,
        private readonly RouteRegistry $routes,
    ) {}

    public function showAction(): ResponseInterface
    {
        $contentObject = $this->request->getAttribute('currentContentObject');
        $data = $contentObject instanceof ContentObjectRenderer ? $contentObject->data : [];
        $pageInformation = $this->request->getAttribute('frontend.page.information');
        $pageId = $pageInformation instanceof PageInformation ? $pageInformation->getId() : (int)($data['pid'] ?? 0);

        $this->view->assignMultiple([
            'settings' => $this->settings,
            'data' => $data,
            'pageId' => $pageId,
            'skills' => array_values($this->skills->all()),
            'endpoint' => $this->routes->get('a2a.jsonrpc')?->uri($this->routes->apiBasePath()) ?? '',
        ]);

        return $this->htmlResponse();
    }
}
