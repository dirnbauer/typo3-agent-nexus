<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Agentstack\Controller;

use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer;
use Webconsulting\AgentNexus\Agentstack\Dto\ProtocolStatus;
use Webconsulting\AgentNexus\Agentstack\Service\ProtocolCatalog;
use Webconsulting\AgentNexus\Agentstack\Service\ProtocolStatusService;

/**
 * "Protocol hub" — the landing element the five demos hang off.
 *
 * Without it the site root is a headline and a paragraph, and a visitor has to
 * guess from the menu what any of A2UI, AG-UI, A2A, UCP or AP2 mean. The hub
 * answers that in one screen: what each protocol is for, what it exposes on
 * *this* installation, whether it is actually working right now, and where the
 * running demo is.
 *
 * Everything shown is derived, never written down twice — the catalogue supplies
 * the description and the live figures, the status service supplies health, and
 * the link is the routed URL of the seeded page rather than an assembled guess.
 * So an install that never seeded a site still renders: the card simply has no
 * demo link.
 */
final class HubPluginController extends ActionController
{
    private const int ENDPOINTS_PER_CARD = 3;

    public function __construct(
        private readonly ProtocolCatalog $protocolCatalog,
        private readonly ProtocolStatusService $protocolStatusService,
    ) {}

    public function showAction(): ResponseInterface
    {
        $contentObject = $this->request->getAttribute('currentContentObject');

        $this->view->assignMultiple([
            'settings' => $this->settings,
            'data' => $contentObject instanceof ContentObjectRenderer ? $contentObject->data : [],
            'cards' => $this->cards(),
        ]);

        return $this->htmlResponse();
    }

    /**
     * One card per protocol: its description joined to its current health.
     *
     * @return list<array{
     *     key: string, label: string, name: string, edge: string, tagline: string, spec: string, specVersion: string,
     *     endpoints: list<array{id: string, method: string, path: string, binding: string, description: string, widget: bool}>,
     *     moreEndpoints: int,
     *     headline: array{label: string, value: string}|null,
     *     status: ProtocolStatus
     * }>
     */
    private function cards(): array
    {
        $status = [];
        foreach ($this->protocolStatusService->all() as $protocolStatus) {
            $status[$protocolStatus->key] = $protocolStatus;
        }

        $cards = [];
        foreach ($this->protocolCatalog->all() as $protocol) {
            // A card names the entry points another agent would call; the
            // widgets' own endpoints and the long tail stay on the protocol page.
            $public = array_values(array_filter($protocol['endpoints'], static fn(array $endpoint): bool => !$endpoint['widget']));
            $cards[] = [
                'key' => $protocol['key'],
                'label' => $protocol['label'],
                'name' => $protocol['name'],
                'edge' => $protocol['edge'],
                'tagline' => $protocol['tagline'],
                'spec' => $protocol['spec'],
                'specVersion' => $protocol['specVersion'],
                'endpoints' => array_slice($public, 0, self::ENDPOINTS_PER_CARD),
                'moreEndpoints' => max(0, count($public) - self::ENDPOINTS_PER_CARD),
                // The first fact is the countable one ("20 catalog components",
                // "6 event families"); the rest are prose that would not fit.
                'headline' => $protocol['facts'][0] ?? null,
                'status' => $status[$protocol['key']],
            ];
        }

        return $cards;
    }
}
