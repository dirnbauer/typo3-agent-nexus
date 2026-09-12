<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Agentstack\Controller;

use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer;
use Webconsulting\AgentNexus\Agentstack\Service\ProtocolCatalog;

/**
 * "Protocol info" — the explainer that sits next to a live demo.
 *
 * A demo shows what a protocol *does*; this element says what it *is*: the
 * sequence diagram rendered at build time, the endpoints this installation
 * actually exposes, and the four steps a request walks through. Editors pick the
 * protocol and which of the three sections to show.
 *
 * Entirely server-rendered and cacheable — no JavaScript, no endpoint calls.
 */
final class ProtocolInfoPluginController extends ActionController
{
    public function __construct(
        private readonly ProtocolCatalog $protocolCatalog,
    ) {}

    public function showAction(): ResponseInterface
    {
        $contentObject = $this->request->getAttribute('currentContentObject');
        $data = $contentObject instanceof ContentObjectRenderer ? $contentObject->data : [];

        $protocol = is_string($this->settings['protocol'] ?? null) ? $this->settings['protocol'] : '';
        $sections = $this->sections();

        $this->view->assignMultiple([
            'settings' => $this->settings,
            'data' => $data,
            'protocol' => $this->protocolCatalog->get($protocol),
            'sections' => $sections,
        ]);

        return $this->htmlResponse();
    }

    /**
     * Which of the three sections the editor enabled. An element with nothing
     * selected would be an empty box, so fall back to all of them.
     *
     * @return array{diagram: bool, endpoints: bool, howItWorks: bool}
     */
    private function sections(): array
    {
        $raw = $this->settings['sections'] ?? '';
        $selected = is_string($raw)
            ? array_filter(array_map(trim(...), explode(',', $raw)))
            : [];

        if ($selected === []) {
            return ['diagram' => true, 'endpoints' => true, 'howItWorks' => true];
        }

        return [
            'diagram' => in_array('diagram', $selected, true),
            'endpoints' => in_array('endpoints', $selected, true),
            'howItWorks' => in_array('how-it-works', $selected, true),
        ];
    }
}
