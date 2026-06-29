<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\A2ui\Controller;

use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;

/**
 * Frontend plugin controller for the A2UI Smart Project Inquiry.
 *
 * Renders the inquiry widget shell; the live behaviour (agent generates the
 * adaptive form, visitor submits) runs client-side against the existing
 * a2ui_generate / a2ui_submit eID endpoints. Cacheable — the shell is static.
 */
final class InquiryPluginController extends ActionController
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
