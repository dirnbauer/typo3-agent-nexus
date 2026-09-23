<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Agentstack\Service;

use TYPO3\CMS\Core\SingletonInterface;
use Webconsulting\AgentNexus\A2a\Service\SkillCatalog;
use Webconsulting\AgentNexus\A2ui\Domain\Repository\ComponentRegistry;
use Webconsulting\AgentNexus\Agui\Service\EventCatalog;
use Webconsulting\AgentNexus\Ap2\Mandate\Check;
use Webconsulting\AgentNexus\Ap2\Service\SampleVerification;
use Webconsulting\AgentNexus\Shared\Http\Api\Route;
use Webconsulting\AgentNexus\Shared\Http\Api\RouteRegistry;
use Webconsulting\AgentNexus\Shared\Protocol;
use Webconsulting\AgentNexus\Ucp\Service\Merchant;

/**
 * One description per protocol, assembled from the services that run it.
 *
 * The "Protocol info" and "Protocol hub" elements and the backend overview all
 * need the same answer to "what is this protocol, which version of it runs
 * here, what does it expose and how does a request flow through it". Rather
 * than restating that in templates, this catalogue derives it: the endpoints
 * from the API routes that are actually served, the specification version from
 * {@see SpecificationVersions}, and every number from its source — the A2A
 * skills, the AG-UI event catalogue, the merchant's products, the A2UI basic
 * catalogue and a real, verified AP2 mandate chain.
 *
 * Diagrams are build artifacts, not content: `npm run diagrams` renders
 * Build/Diagrams/*.mmd into Resources/Public/Diagrams/*.svg and those files are
 * committed, so neither an editor nor CI ever needs node or Chromium.
 */
final readonly class ProtocolCatalog implements SingletonInterface
{
    /** @var list<string> */
    public const array PROTOCOLS = ['a2ui', 'agui', 'a2a', 'ucp', 'ap2'];

    private const string DIAGRAM_BASE = 'EXT:agent_nexus/Resources/Public/Diagrams/';

    /** @var array<string, array{label: string, name: string, edge: string, tagline: string}> */
    private const array META = [
        'a2ui' => [
            'label' => 'A2UI',
            'name' => 'Agent-to-UI',
            'edge' => 'agent ↔ UI',
            'tagline' => 'The agent describes an interface as data. The site builds it only from components it already trusts.',
        ],
        'agui' => [
            'label' => 'AG-UI',
            'name' => 'Agent-User Interaction',
            'edge' => 'agent ↔ user',
            'tagline' => 'An agent run arrives as a stream of typed events. The page shows each step and asks you to approve.',
        ],
        'a2a' => [
            'label' => 'A2A',
            'name' => 'Agent-to-Agent',
            'edge' => 'agent ↔ agent',
            'tagline' => 'An Agent Card tells other agents what this site can do. They send it a task and get a result back.',
        ],
        'ucp' => [
            'label' => 'UCP',
            'name' => 'Universal Commerce Protocol',
            'edge' => 'agent ↔ merchant',
            'tagline' => 'A shop publishes a UCP profile and an agent checks out through its API. A person approves before any order is placed.',
        ],
        'ap2' => [
            'label' => 'AP2',
            'name' => 'Agent Payments Protocol',
            'edge' => 'agent ↔ payment',
            'tagline' => 'Signed mandates prove that a person approved this exact purchase, within the limits they set.',
        ],
    ];

    /** @var array<string, list<array{title: string, text: string}>> */
    private const array HOW_IT_WORKS = [
        'a2ui' => [
            ['title' => 'Intent', 'text' => 'The visitor types what they need in one line. Nothing is generated yet.'],
            ['title' => 'Messages', 'text' => 'The agent answers with A2UI messages: a new surface, its components and its data. Each component points to its children by id.'],
            ['title' => 'Validation', 'text' => 'TYPO3 checks every component against the official basic catalogue. It drops unknown components and properties instead of rendering them.'],
            ['title' => 'Action', 'text' => 'The visitor fills in the form and sends it. The renderer reports an action with the form data, and TYPO3 stores it with the surface.'],
        ],
        'agui' => [
            ['title' => 'Run input', 'text' => 'The client posts a RunAgentInput: the thread, a run id and the conversation.'],
            ['title' => 'Stream', 'text' => 'The agent reasons, answers and proposes a change as a tool call. Every event arrives on its own line of the stream.'],
            ['title' => 'Interrupt', 'text' => 'The run ends with an interrupt. Nothing is written until a person answers it.'],
            ['title' => 'Resume', 'text' => 'The next run answers the interrupt. Only an approval carries out the change.'],
        ],
        'a2a' => [
            ['title' => 'Discovery', 'text' => 'The calling agent reads the Agent Card. It learns this agent\'s skills, its endpoints and the protocol version.'],
            ['title' => 'Delegation', 'text' => 'It sends a message over JSON-RPC or HTTP. The server creates a task with an id and a context id.'],
            ['title' => 'Lifecycle', 'text' => 'Status updates move the task to working. If a skill needs more detail, the task waits for input until a message names it.'],
            ['title' => 'Artifacts', 'text' => 'The result comes back as a named artifact, streamed in chunks. The task completes, and the agent can read it again later.'],
        ],
        'ucp' => [
            ['title' => 'Profile', 'text' => 'The agent reads the business profile: the UCP version, the checkout endpoint and what the shop supports.'],
            ['title' => 'Checkout', 'text' => 'It opens a checkout session with products from the real catalogue. Prices come from the shop; a model never sets one.'],
            ['title' => 'Approval', 'text' => 'The session is ready to complete. The agent stops and shows the priced checkout to a person.'],
            ['title' => 'Completion', 'text' => 'Only an approval completes the checkout and creates the order. Every payment in this demo is simulated.'],
        ],
        'ap2' => [
            ['title' => 'Open mandates', 'text' => 'A trusted surface signs two open mandates for the agent: what it may buy, from which merchant, up to which amount.'],
            ['title' => 'Signed checkout', 'text' => 'The merchant signs the checkout. Its hash ties every later mandate to exactly this purchase.'],
            ['title' => 'Closed mandates', 'text' => 'The agent closes both mandates for this checkout with its own key. It cannot go beyond what the open mandates allow.'],
            ['title' => 'Verification', 'text' => 'The merchant and the payment processor check every signature, hash and limit, then return receipts. Nothing is charged.'],
        ],
    ];

    public function __construct(
        private SkillCatalog $skillCatalog,
        private EventCatalog $eventCatalog,
        private Merchant $merchant,
        private SampleVerification $sampleVerification,
        private ComponentRegistry $componentRegistry,
        private RouteRegistry $routeRegistry,
        private SpecificationVersions $specificationVersions,
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
     * Everything the elements and the overview need about one protocol.
     *
     * @return array{
     *     key: string,
     *     label: string,
     *     name: string,
     *     edge: string,
     *     tagline: string,
     *     spec: string,
     *     specVersion: string,
     *     specLatest: string,
     *     diagram: string,
     *     diagramAlt: string,
     *     endpoints: list<array{id: string, method: string, path: string, binding: string, description: string, widget: bool}>,
     *     howItWorks: list<array{title: string, text: string}>,
     *     facts: list<array{label: string, value: string}>
     * }
     */
    public function get(string $protocol): array
    {
        $key = $this->has($protocol) ? $protocol : self::PROTOCOLS[0];
        $meta = self::META[$key];
        $enum = Protocol::from($key);
        $spec = $this->specificationVersions->for($enum);

        return [
            'key' => $key,
            'label' => $meta['label'],
            'name' => $meta['name'],
            'edge' => $meta['edge'],
            'tagline' => $meta['tagline'],
            'spec' => $spec['url'],
            'specVersion' => $spec['implemented'],
            'specLatest' => $spec['latest'],
            'diagram' => self::DIAGRAM_BASE . $key . '.svg',
            'diagramAlt' => $meta['label'] . ' sequence diagram: ' . $meta['edge'],
            'endpoints' => $this->endpoints($enum),
            'howItWorks' => self::HOW_IT_WORKS[$key],
            'facts' => $this->facts($key),
        ];
    }

    /**
     * @return list<array{
     *     key: string, label: string, name: string, edge: string, tagline: string,
     *     spec: string, specVersion: string, specLatest: string, diagram: string, diagramAlt: string,
     *     endpoints: list<array{id: string, method: string, path: string, binding: string, description: string, widget: bool}>,
     *     howItWorks: list<array{title: string, text: string}>,
     *     facts: list<array{label: string, value: string}>
     * }>
     */
    public function all(): array
    {
        return array_map($this->get(...), self::PROTOCOLS);
    }

    /**
     * The endpoints this installation serves for a protocol, as routed.
     *
     * @return list<array{id: string, method: string, path: string, binding: string, description: string, widget: bool}>
     */
    public function endpoints(Protocol $protocol): array
    {
        $apiBasePath = $this->routeRegistry->apiBasePath();

        return array_map(static fn(Route $route): array => [
            'id' => $route->id,
            'method' => implode(', ', $route->methods),
            'path' => $route->uri($apiBasePath),
            'binding' => $route->binding,
            'description' => $route->description,
            'widget' => $route->widget,
        ], $this->routeRegistry->forProtocol($protocol));
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
        $categories = array_values(array_unique(array_map(static fn(array $c): string => $c['category'], $manifest)));

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
     * Verifies a real (sandbox-signed) mandate chain, so the element shows the
     * checks that actually gate a payment, not a prose summary of them.
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
