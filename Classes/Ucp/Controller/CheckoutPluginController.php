<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Ucp\Controller;

use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;

/**
 * Frontend plugin controller for the UCP Package & Quote Builder.
 *
 * Renders the agent checkout widget shell; the live behaviour (agent assembles
 * the package, builds the cart and runs the simulated checkout) runs client-side
 * against the existing ucp_checkout eID endpoint. Cacheable — the shell is static.
 */
final class CheckoutPluginController extends ActionController
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
