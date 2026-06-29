<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Agentstack\Controller;

use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Module\ModuleProvider;
use TYPO3\CMS\Backend\Routing\UriBuilder as BackendUriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;

/**
 * Agent Nexus — Overview.
 *
 * A neutral, shadcn-style study guide for the agent-protocol family. A protocol
 * switcher reveals, for each protocol, a definition, an animated flow diagram,
 * the mechanics step by step, a grid of key facts, a spec snippet, and deep
 * links into the playground + the real-world plugin. Built on TYPO3 backend
 * design tokens, so it adapts to light/dark for free.
 */
#[AsController]
final class OverviewController extends ActionController
{
    private const CSS = 'EXT:agent_nexus/Resources/Public/Css/agentstack-backend.css';
    private const JS_OVERVIEW = '@webconsulting/agent-nexus/agentstack-overview.js';

    /** The 2026 agent-nexus family — the legend shown across the hub. */
    private const STACK = [
        ['key' => 'MCP', 'label' => 'agent ↔ tools', 'accent' => 'mcp'],
        ['key' => 'A2A', 'label' => 'agent ↔ agent', 'accent' => 'a2a'],
        ['key' => 'AG-UI', 'label' => 'agent ↔ user', 'accent' => 'agui'],
        ['key' => 'A2UI', 'label' => 'agent ↔ UI', 'accent' => 'a2ui'],
        ['key' => 'UCP', 'label' => 'agent ↔ merchant', 'accent' => 'ucp'],
        ['key' => 'AP2', 'label' => 'authorization', 'accent' => 'ap2'],
    ];

    /** Short theory cards shown before the protocol switcher. */
    private const THEORY = [
        [
            'title' => 'Protocols split responsibility',
            'image' => '/fileadmin/desiderio-styleguide/feature-mcp-server.png',
            'alt' => 'A product screenshot representing protocol infrastructure',
            'text' => 'Each protocol has one job: discovery, streaming, UI rendering, commerce, or authorization. Keeping the edges separate makes demos easier to understand and production systems easier to secure.',
        ],
        [
            'title' => 'The backend is the lab bench',
            'image' => '/fileadmin/desiderio-styleguide/backend-visual-editor.png',
            'alt' => 'TYPO3 backend interface screenshot',
            'text' => 'Editors and integrators can inspect real request frames, lifecycle states, payloads, signed mandates, and frontend plugin behavior without an external AI provider.',
        ],
        [
            'title' => 'The frontend is the trust surface',
            'image' => '/fileadmin/desiderio-styleguide/frontend-dashboards-forest.png',
            'alt' => 'Frontend dashboard screenshot',
            'text' => 'Visitors see shadcn-styled TYPO3 plugins: safe A2UI surfaces, live AG-UI streams, A2A delegation, simulated UCP carts, and AP2 authorization proofs.',
        ],
    ];

    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly PageRenderer $pageRenderer,
        private readonly ModuleProvider $moduleProvider,
        private readonly BackendUriBuilder $backendUriBuilder,
    ) {}

    public function indexAction(): ResponseInterface
    {
        $this->pageRenderer->addCssFile(self::CSS);
        $this->pageRenderer->loadJavaScriptModule(self::JS_OVERVIEW);

        $protocols = [];
        foreach ($this->protocols() as $protocol) {
            $protocol['moduleUrl'] = null;
            if ($this->moduleProvider->isModuleRegistered($protocol['module'])) {
                $protocol['moduleUrl'] = (string)$this->backendUriBuilder->buildUriFromRoute($protocol['module']);
            }
            $protocols[] = $protocol;
        }

        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);
        $moduleTemplate->setTitle('Agent Nexus', 'Overview');
        $moduleTemplate->assignMultiple([
            'protocols' => $protocols,
            'stack' => self::STACK,
            'theory' => self::THEORY,
        ]);

        return $moduleTemplate->renderResponse('Overview/Index');
    }

    /**
     * The study-guide content for each protocol. `flow.steps[].side` is 'l'
     * (left→right) or 'r' (right→left) and drives the diagram direction.
     *
     * @return array<int, array<string, mixed>>
     */
    private function protocols(): array
    {
        return [
            [
                'key' => 'a2ui', 'name' => 'A2UI', 'accent' => 'a2ui', 'edge' => 'agent ↔ UI surface',
                'tagline' => 'The agent describes the UI as data; a trusted client renders it natively.',
                'short' => 'A2UI is the safe way to let an agent design an interface without shipping executable frontend code.',
                'image' => '/fileadmin/desiderio-styleguide/frontend-dashboards-forest.png',
                'imageAlt' => 'A shadcn-styled frontend dashboard representing an A2UI surface',
                'definition' => 'Instead of returning HTML or running code in your page, an A2UI agent emits a <em>surface</em>: a flat list of components (one marked <code>root</code>) drawn from a fixed catalog. A trusted renderer rebuilds the tree and binds inputs to a live data model. The agent shapes the interface — your client stays in control of how it is drawn.',
                'flow' => [
                    'left' => 'Agent', 'right' => 'Client UI',
                    'steps' => [
                        ['n' => '1', 'label' => 'createSurface', 'desc' => 'The agent sends the interface as JSON.', 'side' => 'l'],
                        ['n' => '2', 'label' => 'render', 'desc' => 'The client builds native components from the catalog.', 'side' => 'r'],
                        ['n' => '3', 'label' => 'bind', 'desc' => 'Inputs two-way-bind to a data model via JSON-Pointer.', 'side' => 'r'],
                        ['n' => '4', 'label' => 'action', 'desc' => 'A button emits a typed event back to the agent.', 'side' => 'r'],
                        ['n' => '5', 'label' => 'update', 'desc' => 'The agent patches the surface in place.', 'side' => 'l'],
                    ],
                ],
                'facts' => [
                    ['term' => 'Surface', 'def' => 'A flat adjacency list of components with exactly one root.'],
                    ['term' => 'Catalog', 'def' => 'The fixed component set the client will render — nothing else.'],
                    ['term' => 'Binding', 'def' => 'Two-way JSON-Pointer binding, local until an action fires.'],
                    ['term' => 'Safety', 'def' => 'Only data crosses the wire, validated against the catalog — never code.'],
                ],
                'specLabel' => 'createSurface',
                'spec' => '{ "version": "v1.0", "createSurface": {' . "\n" .
                    '  "components": [' . "\n" .
                    '    { "id": "root", "component": "Column", "children": ["q","go"] },' . "\n" .
                    '    { "id": "q",  "component": "TextField", "value": { "path": "/q" } },' . "\n" .
                    '    { "id": "go", "component": "Button", "action": { "event": "submit" } }' . "\n" .
                    '  ] } }',
                'backendExample' => 'Generate a contact form from natural language, inspect the JSON surface, then type into the rendered controls and watch the data model update.',
                'frontendExample' => 'Smart Project Inquiry asks visitors for a goal, generates the exact intake form, and submits a structured request.',
                'frontendUrl' => '/desiderio/agent-nexus/a2ui',
                'module' => 'agentstack_a2ui', 'plugin' => 'Smart Project Inquiry',
            ],
            [
                'key' => 'agui', 'name' => 'AG-UI', 'accent' => 'agui', 'edge' => 'agent ↔ user',
                'tagline' => 'The agent streams typed events to your UI in real time.',
                'short' => 'AG-UI is for live, interruptible agent runs where people need to see progress and approve writes.',
                'image' => '/fileadmin/desiderio-styleguide/backend-visual-editor.png',
                'imageAlt' => 'A TYPO3 backend workflow screenshot representing a live event stream',
                'definition' => 'AG-UI turns a long-running agent into a live, steerable stream. Over a single SSE connection the agent emits <em>typed events</em> — text deltas, tool calls, state patches, lifecycle markers — so the UI updates as the agent thinks, and a human can approve before anything is written.',
                'flow' => [
                    'left' => 'Frontend', 'right' => 'Agent',
                    'steps' => [
                        ['n' => '1', 'label' => 'RUN_STARTED', 'desc' => 'The agent opens a run.', 'side' => 'r'],
                        ['n' => '2', 'label' => 'TEXT_MESSAGE', 'desc' => 'Tokens stream in — no spinner.', 'side' => 'r'],
                        ['n' => '3', 'label' => 'TOOL_CALL', 'desc' => 'A tool call renders a generative-UI card.', 'side' => 'r'],
                        ['n' => '4', 'label' => 'confirm', 'desc' => 'The run pauses for human approval.', 'side' => 'l'],
                        ['n' => '5', 'label' => 'STATE_DELTA', 'desc' => 'Shared state syncs via JSON Patch.', 'side' => 'r'],
                        ['n' => '6', 'label' => 'RUN_FINISHED', 'desc' => 'Done — nothing was written without you.', 'side' => 'r'],
                    ],
                ],
                'facts' => [
                    ['term' => 'Transport', 'def' => 'One SSE stream of small, typed events.'],
                    ['term' => 'Generative UI', 'def' => 'Tool calls render real components, not just text.'],
                    ['term' => 'Shared state', 'def' => 'Client and agent stay in lockstep via JSON Patch.'],
                    ['term' => 'Human-in-the-loop', 'def' => 'A confirm gate stands before any write.'],
                ],
                'specLabel' => 'event stream',
                'spec' => 'event: TEXT_MESSAGE_CONTENT   { "delta": "Comparing plans…" }' . "\n" .
                    'event: TOOL_CALL_START        { "name": "render_plan_table" }' . "\n" .
                    'event: TOOL_CALL confirm_apply { "wantResponse": true }   ← pause' . "\n" .
                    'event: RUN_FINISHED',
                'backendExample' => 'Run a plan-advisor scenario and inspect text deltas, tool-call cards, shared-state patches, and the human approval gate.',
                'frontendExample' => 'AI Site Assistant answers visitor questions as a live stream and only captures a lead after explicit approval.',
                'frontendUrl' => '/desiderio/agent-nexus/ag-ui',
                'module' => 'agentstack_agui', 'plugin' => 'AI Site Assistant',
            ],
            [
                'key' => 'a2a', 'name' => 'A2A', 'accent' => 'a2a', 'edge' => 'agent ↔ agent',
                'tagline' => 'Independent agents discover each other and delegate tasks.',
                'short' => 'A2A is the delegation layer: one agent discovers another agent and follows a task lifecycle.',
                'image' => '/fileadmin/desiderio-styleguide/customer-data-team.jpg',
                'imageAlt' => 'A team workspace image representing agent-to-agent collaboration',
                'definition' => 'A2A lets agents that were never built together cooperate. Each publishes an <em>Agent Card</em> describing its skills; a client delegates a task over JSON-RPC and follows a defined lifecycle — including a pause to ask for missing input — until a finished artifact comes back.',
                'flow' => [
                    'left' => 'Client agent', 'right' => 'Server agent',
                    'steps' => [
                        ['n' => '1', 'label' => 'discover', 'desc' => 'The client fetches the server\'s Agent Card.', 'side' => 'l'],
                        ['n' => '2', 'label' => 'message/send', 'desc' => 'It delegates a task over JSON-RPC.', 'side' => 'l'],
                        ['n' => '3', 'label' => 'working', 'desc' => 'The server accepts and starts working.', 'side' => 'r'],
                        ['n' => '4', 'label' => 'input-required', 'desc' => 'It pauses to ask the client for input.', 'side' => 'r'],
                        ['n' => '5', 'label' => 'answer', 'desc' => 'The client responds.', 'side' => 'l'],
                        ['n' => '6', 'label' => 'completed', 'desc' => 'A finished artifact returns.', 'side' => 'r'],
                    ],
                ],
                'facts' => [
                    ['term' => 'Agent Card', 'def' => 'A public manifest of an agent\'s skills and endpoints.'],
                    ['term' => 'Transport', 'def' => 'JSON-RPC 2.0 (message/send, message/stream).'],
                    ['term' => 'Lifecycle', 'def' => 'submitted → working → input-required → completed.'],
                    ['term' => 'Cooperation', 'def' => 'A contract between agents, not a shared codebase.'],
                ],
                'specLabel' => 'JSON-RPC',
                'spec' => '→ { "method": "message/send",' . "\n" .
                    '    "params": { "skill": "onboarding", "text": "…" } }' . "\n" .
                    '← { "status": "input-required", "prompt": "Which team size?" }' . "\n" .
                    '← { "status": "completed", "artifact": { "type": "plan" } }',
                'backendExample' => 'Fetch the Agent Card, pick an advertised skill, delegate a task, then inspect JSON-RPC frames and the returned artifact.',
                'frontendExample' => 'Expert Router lets a visitor request help while the site agent delegates the work to the right specialist.',
                'frontendUrl' => '/desiderio/agent-nexus/a2a',
                'module' => 'agentstack_a2a', 'plugin' => 'Expert Router',
            ],
            [
                'key' => 'ucp', 'name' => 'UCP', 'accent' => 'ucp', 'edge' => 'agent ↔ merchant',
                'tagline' => 'A shopping agent negotiates a cart and checkout with a merchant.',
                'short' => 'UCP is the commerce handshake between a buying agent and a merchant manifest.',
                'image' => '/fileadmin/desiderio-element-library/frontend-pricing-midnight-dark.png',
                'imageAlt' => 'A pricing page screenshot representing agentic commerce',
                'definition' => 'UCP gives an AI shopping agent and a merchant a shared protocol: the merchant publishes a <em>manifest</em> of products and a checkout contract; the agent discovers it, builds a cart, and drives a checkout state machine — pausing for human authorization before confirming. <strong>Simulated here — nothing is ever charged.</strong>',
                'flow' => [
                    'left' => 'Shopping agent', 'right' => 'Merchant',
                    'steps' => [
                        ['n' => '1', 'label' => 'manifest', 'desc' => 'The agent discovers the merchant manifest.', 'side' => 'l'],
                        ['n' => '2', 'label' => 'build cart', 'desc' => 'It assembles a cart from the catalog.', 'side' => 'l'],
                        ['n' => '3', 'label' => 'review', 'desc' => 'The order is reviewed.', 'side' => 'r'],
                        ['n' => '4', 'label' => 'authorize', 'desc' => 'The flow pauses for human authorization.', 'side' => 'l'],
                        ['n' => '5', 'label' => 'confirmed', 'desc' => 'Order confirmed — nothing is charged.', 'side' => 'r'],
                    ],
                ],
                'facts' => [
                    ['term' => 'Manifest', 'def' => 'The merchant\'s machine-readable catalog + checkout contract.'],
                    ['term' => 'State machine', 'def' => 'discovering → building_cart → review → confirmed.'],
                    ['term' => 'Authorization', 'def' => 'A human gate before any commitment.'],
                    ['term' => 'Simulated', 'def' => 'Demo only — no payment is ever initiated.'],
                ],
                'specLabel' => 'checkout',
                'spec' => 'GET /ucp/manifest →' . "\n" .
                    '  { "products": [ … ], "checkout": "/ucp/checkout" }' . "\n" .
                    '' . "\n" .
                    'state: building_cart → review → authorization_required → confirmed',
                'backendExample' => 'Load the merchant manifest, choose an intent, watch the agent build a cart, and inspect checkout events before authorization.',
                'frontendExample' => 'Package & Quote Builder assembles a simulated service order from a visitor goal and pauses before confirmation.',
                'frontendUrl' => '/desiderio/agent-nexus/ucp',
                'module' => 'agentstack_ucp', 'plugin' => 'Package & Quote Builder',
            ],
            [
                'key' => 'ap2', 'name' => 'AP2', 'accent' => 'ap2', 'edge' => 'authorization',
                'tagline' => 'Cryptographically-signed mandates make an agent\'s authority verifiable.',
                'short' => 'AP2 proves that an agent has authority for a specific purchase, within a human-approved scope.',
                'image' => '/fileadmin/desiderio-styleguide/compliance-soc2-dashboard.jpg',
                'imageAlt' => 'A compliance dashboard screenshot representing authorization verification',
                'definition' => 'AP2 answers one question: <em>is this agent allowed to do this?</em> A human signs an <em>Intent Mandate</em> (a spending cap and scope); the agent signs a <em>Cart Mandate</em> for the exact order that references it; the merchant verifies the signed chain. Tamper with one byte and verification fails. <strong>Simulated here — nothing is ever charged.</strong>',
                'flow' => [
                    'left' => 'Human + Agent', 'right' => 'Merchant',
                    'steps' => [
                        ['n' => '1', 'label' => 'Intent', 'desc' => 'A human signs an Intent Mandate (cap €500).', 'side' => 'l'],
                        ['n' => '2', 'label' => 'Cart', 'desc' => 'The agent signs a Cart Mandate (€448) referencing it.', 'side' => 'l'],
                        ['n' => '3', 'label' => 'verify', 'desc' => 'The merchant walks the signed chain.', 'side' => 'r'],
                        ['n' => '4', 'label' => 'authorized', 'desc' => 'Every check passes → authorized.', 'side' => 'r'],
                    ],
                ],
                'facts' => [
                    ['term' => 'Mandate', 'def' => 'A signed token (JWS) of intent, or of an exact cart.'],
                    ['term' => 'Chain', 'def' => 'The cart references the intent; both signatures must verify.'],
                    ['term' => 'Checks', 'def' => 'signature · intent-ref · merchant · cap · expiry.'],
                    ['term' => 'Tamper-evident', 'def' => 'Any change breaks a signature → rejected.'],
                ],
                'specLabel' => 'mandate chain',
                'spec' => 'Intent  { cap: 500, merchant: "acme", exp: … } .sig' . "\n" .
                    'Cart    { total: 448, intentRef: "…", items: [ … ] } .sig' . "\n" .
                    '' . "\n" .
                    'verify → ✓ sig ✓ intentRef ✓ merchant ✓ cap ✓ expiry → AUTHORIZED',
                'backendExample' => 'Mint an Intent Mandate, mint the exact Cart Mandate, verify the chain, then tamper with the token to see verification fail.',
                'frontendExample' => 'Signed Quote Authorization gives visitors a trusted surface for approving an agent purchase with a spending cap.',
                'frontendUrl' => '/desiderio/agent-nexus/ap2',
                'module' => 'agentstack_ap2', 'plugin' => 'Signed Quote Authorization',
            ],
        ];
    }
}
