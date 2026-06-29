<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\A2a\Controller;

use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;

/**
 * Frontend plugin controller for the A2A Expert Router (Concierge).
 *
 * Renders the concierge widget shell; the live behaviour (agent delegates the
 * task and streams its lifecycle) runs client-side against the existing
 * a2a_concierge eID endpoint. Cacheable — the shell is static.
 */
final class ConciergePluginController extends ActionController
{
    public function showAction(): ResponseInterface
    {
        $contentObject = $this->request->getAttribute('currentContentObject');
        $data = $contentObject?->data ?? [];
        $pageId = (int)($this->request->getAttribute('frontend.page.information')?->getId() ?? ($data['pid'] ?? 0));

        $this->view->assignMultiple([
            'settings' => $this->settings,
            'data' => $data,
            'pageId' => $pageId,
        ]);

        return $this->htmlResponse();
    }
}
