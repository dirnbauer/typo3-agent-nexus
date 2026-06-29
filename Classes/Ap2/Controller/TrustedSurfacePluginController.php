<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Ap2\Controller;

use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;

/**
 * Frontend plugin controller for the AP2 Trusted Surface.
 *
 * Renders the trusted-surface widget shell; the live behaviour (visitor sets a
 * cap and authorizes, the chain is signed + verified) runs client-side against
 * the existing ap2_authorize eID endpoint. Cacheable — the shell is static.
 */
final class TrustedSurfacePluginController extends ActionController
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
