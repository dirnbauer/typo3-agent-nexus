<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\A2ui\Controller;

use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer;
use Webconsulting\AgentNexus\Shared\Http\Api\RouteRegistry;

/**
 * The "Smart inquiry" widget: a visitor describes what they need, the agent
 * answers with an A2UI surface, the widget renders it and sends the filled-in
 * form back as an A2UI action.
 *
 * The shell is static and cacheable. Its script talks to the public A2UI
 * endpoints like any other client and names its content element, so the
 * endpoints load this element's FlexForm (business context, confirmation
 * text) themselves — nothing that shapes a prompt travels from the browser.
 */
final class InquiryPluginController extends ActionController
{
    public function __construct(
        private readonly RouteRegistry $routes,
    ) {}

    public function showAction(): ResponseInterface
    {
        $contentObject = $this->request->getAttribute('currentContentObject');
        $data = $contentObject instanceof ContentObjectRenderer ? $contentObject->data : [];
        $pageId = (int)($this->request->getAttribute('frontend.page.information')?->getId() ?? ($data['pid'] ?? 0));
        $apiBase = $this->routes->apiBasePath();

        $this->view->assignMultiple([
            'settings' => $this->settings,
            'data' => $data,
            'pageId' => $pageId,
            'contentElement' => (int)($data['_LOCALIZED_UID'] ?? $data['uid'] ?? 0),
            'surfacesUrl' => $this->routes->get('a2ui.surfaces')?->uri($apiBase) ?? '',
            'actionsUrl' => $this->routes->get('a2ui.actions')?->uri($apiBase) ?? '',
        ]);

        return $this->htmlResponse();
    }
}
