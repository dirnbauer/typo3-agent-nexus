<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Agentstack\Service;

use TYPO3\CMS\Core\SingletonInterface;
use Webconsulting\AgentNexus\A2a\Service\SkillCatalog;
use Webconsulting\AgentNexus\A2ui\Domain\Repository\ComponentRegistry;
use Webconsulting\AgentNexus\Agui\Service\EventCatalog;
use Webconsulting\AgentNexus\Ap2\Mandate\Check;
use Webconsulting\AgentNexus\Ap2\Service\SampleVerification;
use Webconsulting\AgentNexus\Ucp\Service\Merchant;

/**
 * One description per protocol, assembled from the services that actually run it.
 *
 * The "Protocol info" frontend plugin and the backend hub both need the same
 * answer to "what is this protocol, what does it expose here, and how does a
 * request flow through it". Rather than restating that in templates, this
 * catalogue derives every number from the live source of truth: the A2A skills
 * the agent advertises, the AG-UI event families it can emit, the merchant's own
 * product catalogue, the A2UI component registry and a real AP2 mandate chain.
 *
 * Diagrams are build artifacts, not content: `npm run diagrams` renders
 * Build/Diagrams/*.mmd into Resources/Public/Diagrams/*.svg and those files are
 * committed, so neither an editor nor CI ever needs node or Chromium.
 */
final class ProtocolCatalog implements SingletonInterface
{
    /** @var list<string> */
    public const PROTOCOLS = ['a2ui', 'agui', 'a2a', 'ucp', 'ap2'];

    private const DIAGRAM_BASE = 'EXT:agent_nexus/Resources/Public/Diagrams/';

    /**
     * Public eID endpoints per protocol. Mirrors the registrations in
     * ext_localconf.php — keep both in step.
     *
     * @var array<string, list<array{id: string, path: string, method: string, description: string}>>
     */
    private const ENDPOINTS = [
        'a2ui' => [
            ['id' => 'a2ui_generate', 'path' => '/index.php?eID=a2ui_generate', 'method' => 'POST', 'description' => 'Turns a visitor\'s request into an A2UI surface: a flat list of components from the catalogue.'],
            ['id' => 'a2ui_submit', 'path' => '/index.php?eID=a2ui_submit', 'method' => 'POST', 'description' => 'Stores the completed form as an inquiry record.'],
        ],
        'agui' => [
            ['id' => 'agui_assistant', 'path' => '/index.php?eID=agui_assistant', 'method' => 'POST', 'description' => 'Streams one agent run as AG-UI events over SSE, including the approval step.'],
        ],
        'a2a' => [
            ['id' => 'a2a_card', 'path' => '/index.php?eID=a2a_card', 'method' => 'GET', 'description' => 'Returns the Agent Card: who this agent is, where to reach it and which skills it offers.'],
            ['id' => 'a2a_rpc', 'path' => '/index.php?eID=a2a_rpc', 'method' => 'POST', 'description' => 'Accepts JSON-RPC 2.0 calls for message/send and message/stream.'],
            ['id' => 'a2a_concierge', 'path' => '/index.php?eID=a2a_concierge', 'method' => 'POST', 'description' => 'Runs the concierge on this page: it delegates a task and streams each status change.'],
        ],
        'ucp' => [
            ['id' => 'ucp_manifest', 'path' => '/index.php?eID=ucp_manifest', 'method' => 'GET', 'description' => 'Returns the merchant manifest, which a shopping agent reads before it builds a cart.'],
            ['id' => 'ucp_checkout', 'path' => '/index.php?eID=ucp_checkout', 'method' => 'POST', 'description' => 'Streams the agent\'s checkout up to the point where a person has to approve it.'],
        ],
        'ap2' => [
            ['id' => 'ap2_authorize', 'path' => '/index.php?eID=ap2_authorize', 'method' => 'POST', 'description' => 'Creates and signs the Intent and Cart mandates, then returns the verified chain.'],
        ],
    ];

    /** @var array<string, array{label: string, name: string, edge: string, tagline: string, spec: string}> */
    private const META = [
        'a2ui' => [
            'label' => 'A2UI',
            'name' => 'Agent-to-UI',
            'edge' => 'agent ↔ UI',
            'tagline' => 'The agent describes an interface as data. The site builds it only from components it already trusts.',
            'spec' => 'https://github.com/google/A2UI',
        ],
        'agui' => [
            'label' => 'AG-UI',
            'name' => 'Agent-User Interaction',
            'edge' => 'agent ↔ user',
            'tagline' => 'An agent run arrives as a stream of typed events. The page shows each step and asks you to approve.',
            'spec' => 'https://docs.ag-ui.com',
        ],
        'a2a' => [
            'label' => 'A2A',
            'name' => 'Agent-to-Agent',
            'edge' => 'agent ↔ agent',
            'tagline' => 'An Agent Card tells other agents what this site can do. They send it a task and get a result back.',
            'spec' => 'https://a2a-protocol.org',
        ],
        'ucp' => [
            'label' => 'UCP',
            'name' => 'Universal Commerce Protocol',
            'edge' => 'agent ↔ merchant',
            'tagline' => 'A shop publishes a manifest and an agent checks out from it. A person approves before any order is placed.',
            'spec' => 'https://www.universalcommerce.org',
        ],
        'ap2' => [
            'label' => 'AP2',
            'name' => 'Agent Payments Protocol',
            'edge' => 'agent ↔ payment',
            'tagline' => 'Two linked, signed mandates prove that a person approved this exact purchase, within their limits.',
            'spec' => 'https://ap2-protocol.org',
        ],
    ];

    /** @var array<string, list<array{title: string, text: string}>> */
    private const HOW_IT_WORKS = [
        'a2ui' => [
            ['title' => 'Intent', 'text' => 'The visitor types what they need in one line. Nothing is generated yet.'],
            ['title' => 'Generation', 'text' => 'The agent replies with a surface: a flat list of components. Each one points to its children by id.'],
            ['title' => 'Validation', 'text' => 'TYPO3 checks every component against its registry. It drops unknown components and properties instead of rendering them.'],
            ['title' => 'Submission', 'text' => 'The visitor fills in the form and sends it. TYPO3 stores it as an inquiry record.'],
        ],
        'agui' => [
            ['title' => 'Run start', 'text' => 'The browser opens an SSE stream. The server replies with RUN_STARTED and a thread id.'],
            ['title' => 'Streamed answer', 'text' => 'Reasoning and text arrive in small pieces, so the page fills in while the agent works.'],
            ['title' => 'Approval gate', 'text' => 'Before it saves anything, the agent sends a confirm tool call and stops. Nothing happens until a person decides.'],
            ['title' => 'Apply', 'text' => 'After approval the run continues, TYPO3 stores the lead and RUN_FINISHED closes the stream.'],
        ],
        'a2a' => [
            ['title' => 'Discovery', 'text' => 'The calling agent fetches the Agent Card. It learns this agent\'s skills, transport and authentication.'],
            ['title' => 'Delegation', 'text' => 'It sends a message over JSON-RPC. The server creates a Task with an id and a context id.'],
            ['title' => 'Lifecycle', 'text' => 'Status updates move the task to working. If a skill needs more detail, the task moves to input-required.'],
            ['title' => 'Artifacts', 'text' => 'The result comes back as a named artifact, streamed in chunks. The task then reaches completed.'],
        ],
        'ucp' => [
            ['title' => 'Manifest', 'text' => 'The agent reads the merchant manifest: currency, capabilities, checkout endpoint and catalogue.'],
            ['title' => 'Cart', 'text' => 'It builds a cart from the real catalogue. Prices come from the catalogue; the model never invents one.'],
            ['title' => 'Approval', 'text' => 'The stream stops at authorization.required and shows the priced cart to a person.'],
            ['title' => 'Confirmation', 'text' => 'Only an explicit approval produces order.confirmed. Every order in this demo is simulated.'],
        ],
        'ap2' => [
            ['title' => 'Intent Mandate', 'text' => 'A person allows an agent to spend up to a cap, at named merchants, until the mandate expires.'],
            ['title' => 'Cart Mandate', 'text' => 'A second mandate covers one fully priced cart. It points to the first mandate and fixes that exact purchase.'],
            ['title' => 'Verification', 'text' => 'The site checks the chain: both signatures, the link between the mandates, the merchant and the cap.'],
            ['title' => 'Receipt', 'text' => 'The verified chain serves as the receipt. Mandates here are signed with a sandbox key, and nothing is charged.'],
        ],
    ];

    public function __construct(
        private readonly SkillCatalog $skillCatalog,
        private readonly EventCatalog $eventCatalog,
        private readonly Merchant $merchant,
        private readonly SampleVerification $sampleVerification,
        private readonly ComponentRegistry $componentRegistry,
    ) {}

    /**
     * @return list<string>
     */
    public function protocols(): array
    {
        return self::PROTOCOLS;
    }

    public function has(string $protocol): bool
    {
        return in_array($protocol, self::PROTOCOLS, true);
    }

    /**
     * Everything the protocol info element and the hub need about one protocol.
     *
     * @return array{
     *     key: string,
     *     label: string,
     *     name: string,
     *     edge: string,
     *     tagline: string,
     *     spec: string,
     *     diagram: string,
     *     diagramAlt: string,
     *     endpoints: list<array{id: string, path: string, method: string, description: string}>,
     *     howItWorks: list<array{title: string, text: string}>,
     *     facts: list<array{label: string, value: string}>
     * }
     */
    public function get(string $protocol): array
    {
        $key = $this->has($protocol) ? $protocol : self::PROTOCOLS[0];
        $meta = self::META[$key];

        return [
            'key' => $key,
            'label' => $meta['label'],
            'name' => $meta['name'],
            'edge' => $meta['edge'],
            'tagline' => $meta['tagline'],
            'spec' => $meta['spec'],
            'diagram' => self::DIAGRAM_BASE . $key . '.svg',
            'diagramAlt' => $meta['label'] . ' sequence diagram: ' . $meta['edge'],
            'endpoints' => self::ENDPOINTS[$key],
            'howItWorks' => self::HOW_IT_WORKS[$key],
            'facts' => $this->facts($key),
        ];
    }

    /**
     * @return list<array{
     *     key: string, label: string, name: string, edge: string, tagline: string, spec: string,
     *     diagram: string, diagramAlt: string,
     *     endpoints: list<array{id: string, path: string, method: string, description: string}>,
     *     howItWorks: list<array{title: string, text: string}>,
     *     facts: list<array{label: string, value: string}>
     * }>
     */
    public function all(): array
    {
        return array_map($this->get(...), self::PROTOCOLS);
    }

    /**
     * Endpoint ids a protocol registers, for the hub's health check.
     *
     * @return list<string>
     */
    public function endpointIds(string $protocol): array
    {
        if (!$this->has($protocol)) {
            return [];
        }
        return array_map(static fn(array $e): string => $e['id'], self::ENDPOINTS[$protocol]);
    }

    /**
     * Live numbers, read from the services that implement the protocol — never
     * hand-maintained copies of them.
     *
     * @return list<array{label: string, value: string}>
     */
    private function facts(string $protocol): array
    {
        return match ($protocol) {
            'a2ui' => $this->a2uiFacts(),
            'agui' => $this->aguiFacts(),
            'a2a' => $this->a2aFacts(),
            'ucp' => $this->ucpFacts(),
            'ap2' => $this->ap2Facts(),
            default => [],
        };
    }

    /**
     * @return list<array{label: string, value: string}>
     */
    private function a2uiFacts(): array
    {
        $manifest = $this->componentRegistry->getCatalogManifest();
        $containers = array_filter($manifest, static fn(array $c): bool => $c['container']);
        $categories = array_unique(array_map(static fn(array $c): string => $c['category'], $manifest));

        return [
            ['label' => 'Catalogue components', 'value' => (string)count($manifest)],
            ['label' => 'Container components', 'value' => (string)count($containers)],
            ['label' => 'Categories', 'value' => implode(', ', $categories)],
        ];
    }

    /**
     * @return list<array{label: string, value: string}>
     */
    private function aguiFacts(): array
    {
        $families = $this->eventCatalog->all();
        $events = array_sum(array_map(static fn(array $f): int => count($f['events']), $families));

        return [
            ['label' => 'Event families', 'value' => (string)count($families)],
            ['label' => 'Event types', 'value' => (string)$events],
            ['label' => 'Families', 'value' => implode(', ', array_keys($families))],
        ];
    }

    /**
     * @return list<array{label: string, value: string}>
     */
    private function a2aFacts(): array
    {
        $skills = $this->skillCatalog->all();
        $gated = array_filter($skills, static fn(array $s): bool => ($s['inputPrompt'] ?? null) !== null);

        return [
            ['label' => 'Advertised skills', 'value' => (string)count($skills)],
            ['label' => 'Skills that ask for input', 'value' => (string)count($gated)],
            ['label' => 'Skills', 'value' => implode(', ', array_map(static fn(array $s): string => (string)$s['name'], $skills))],
        ];
    }

    /**
     * @return list<array{label: string, value: string}>
     */
    private function ucpFacts(): array
    {
        $catalog = $this->merchant->catalog();
        $prices = array_map(static fn(array $p): int => (int)$p['price'], $catalog);

        return [
            ['label' => 'Products', 'value' => (string)count($catalog)],
            ['label' => 'Currency', 'value' => Merchant::CURRENCY],
            ['label' => 'Price range', 'value' => $prices === []
                ? '—'
                : $this->money(min($prices)) . ' – ' . $this->money(max($prices))],
        ];
    }

    /**
     * Runs a real (sandbox-signed) mandate chain so the element shows the checks
     * that actually gate a payment, not a prose summary of them.
     *
     * @return list<array{label: string, value: string}>
     */
    private function ap2Facts(): array
    {
        $verdict = $this->sampleVerification->verdict();

        return [
            ['label' => 'Mandate types', 'value' => 'Checkout and payment, open and closed (mandate.*.1)'],
            ['label' => 'Chain checks', 'value' => implode(', ', array_map(
                static fn(Check $check): string => $check->label(),
                $verdict->checks,
            ))],
            ['label' => 'Signing', 'value' => 'ES256 sandbox keys, no real payment network'],
        ];
    }

    private function money(int $cents): string
    {
        return '€' . number_format($cents / 100, 2);
    }
}
