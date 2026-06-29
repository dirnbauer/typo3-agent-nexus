<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Agui\Controller;

use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;

/**
 * Frontend plugin controller for the AG-UI AI Site Assistant.
 *
 * Renders the assistant widget shell; the live behaviour (agent streams its
 * thinking, an answer and a generative UI, then captures a lead on approval)
 * runs client-side against the existing agui_assistant eID endpoint over SSE.
 * Cacheable — the shell is static.
 */
final class AssistantPluginController extends ActionController
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
