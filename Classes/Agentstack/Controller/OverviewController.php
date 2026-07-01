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
 * The hub landing page: a field guide to the 2026 agent-protocol family.
 * A hero explains what the module is, a hand-drawn protocol map shows the
 * six edges around the agent, theory cards frame the architecture, a
 * comparison table and a decision helper make the protocols comparable,
 * and a tab per protocol dives into definition, sequence diagram, flow,
 * mechanics, spec snippet and examples. A glossary closes the page.
 */
#[AsController]
final class OverviewController extends ActionController
{
    /** Design-system CSS, loaded in this exact order. */
    private const CSS_FILES = [
        'EXT:agent_nexus/Resources/Public/Css/nexus-tokens.css',
        'EXT:agent_nexus/Resources/Public/Css/nexus-ui.css',
        'EXT:agent_nexus/Resources/Public/Css/nexus-backend.css',
    ];

    private const JS_OVERVIEW = '@webconsulting/agent-nexus/nexus-overview.js';

    private const FRONTEND_DEMO_URL = 'https://webconsulting-typo3-lab.ddev.site/desiderio/agent-nexus/a2ui';

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

    /** Hero stat chips (counted up by nexus-motion). */
    private const STATS = [
        ['value' => 5, 'label' => 'Protocols', 'hint' => 'A2UI · AG-UI · A2A · UCP · AP2'],
        ['value' => 9, 'label' => 'Frontend endpoints', 'hint' => 'eID JSON + SSE routes'],
        ['value' => 5, 'label' => 'Frontend plugins', 'hint' => 'shadcn-styled site widgets'],
    ];

    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly PageRenderer $pageRenderer,
        private readonly ModuleProvider $moduleProvider,
        private readonly BackendUriBuilder $backendUriBuilder,
    ) {}

    public function indexAction(): ResponseInterface
    {
        foreach (self::CSS_FILES as $cssFile) {
            $this->pageRenderer->addCssFile($cssFile);
        }
        $this->pageRenderer->loadJavaScriptModule(self::JS_OVERVIEW);

        $protocols = [];
        $moduleUrls = [];
        foreach ($this->protocols() as $protocol) {
            $protocol['moduleUrl'] = null;
            if ($this->moduleProvider->isModuleRegistered($protocol['module'])) {
                $protocol['moduleUrl'] = (string)$this->backendUriBuilder->buildUriFromRoute($protocol['module']);
            }
            $moduleUrls[$protocol['key']] = $protocol['moduleUrl'];
            $protocols[] = $protocol;
        }

        $comparison = [];
        foreach ($this->comparison() as $row) {
            $row['moduleUrl'] = $moduleUrls[$row['key']] ?? null;
            $comparison[] = $row;
        }

        $decisionTree = $this->decisionTree();

        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);
        $moduleTemplate->setTitle('Agent Nexus', 'Overview');
        $moduleTemplate->assignMultiple([
            'protocols' => $protocols,
            'stack' => self::STACK,
            'theory' => self::THEORY,
            'stats' => self::STATS,
            'comparison' => $comparison,
            'decision' => $decisionTree,
            'decisionJson' => json_encode(['rules' => $decisionTree['rules']], JSON_THROW_ON_ERROR),
            'glossary' => $this->glossary(),
            'heroPlaygroundUrl' => $moduleUrls['a2ui'] ?? null,
            'frontendDemoUrl' => self::FRONTEND_DEMO_URL,
        ]);

        return $moduleTemplate->renderResponse('Overview/Index');
    }

    /**
     * The study-guide content for each protocol. `flow.steps[].side` is 'l'
     * (left→right) or 'r' (right→left) and drives the diagram direction.
     * `diagram` names the generated Mermaid sequence-diagram partial.
     *
     * @return array<int, array<string, mixed>>
     */
    private function protocols(): array
    {
        return [
            [
                'key' => 'a2ui', 'name' => 'A2UI', 'accent' => 'a2ui', 'edge' => 'agent ↔ UI surface',
                'diagram' => 'A2ui',
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
                'diagram' => 'Agui',
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
                'diagram' => 'A2a',
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
                'diagram' => 'Ucp',
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
                'diagram' => 'Ap2',
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

    /**
     * One comparison-table row per protocol. `moduleUrl` is attached in
     * indexAction() from the registered playground modules.
     *
     * @return array<int, array<string, string>>
     */
    private function comparison(): array
    {
        return [
            [
                'key' => 'a2ui', 'name' => 'A2UI', 'accent' => 'a2ui',
                'edge' => 'agent ↔ UI surface',
                'transport' => 'JSON POST',
                'payload' => 'surface',
                'gate' => 'User reviews and submits the rendered form',
            ],
            [
                'key' => 'agui', 'name' => 'AG-UI', 'accent' => 'agui',
                'edge' => 'agent ↔ user',
                'transport' => 'SSE',
                'payload' => 'event stream',
                'gate' => 'Confirm gate pauses the run before any write',
            ],
            [
                'key' => 'a2a', 'name' => 'A2A', 'accent' => 'a2a',
                'edge' => 'agent ↔ agent',
                'transport' => 'JSON-RPC 2.0',
                'payload' => 'task + artifact',
                'gate' => 'input-required pause hands control back to the client',
            ],
            [
                'key' => 'ucp', 'name' => 'UCP', 'accent' => 'ucp',
                'edge' => 'agent ↔ merchant',
                'transport' => 'JSON POST',
                'payload' => 'cart',
                'gate' => 'Human authorizes the order before confirmation',
            ],
            [
                'key' => 'ap2', 'name' => 'AP2', 'accent' => 'ap2',
                'edge' => 'authorization',
                'transport' => 'signed JWT (JWS)',
                'payload' => 'mandate',
                'gate' => 'Human signs the Intent Mandate (cap + scope)',
            ],
        ];
    }

    /**
     * "Which protocol do I need?" — three button-group questions plus an
     * ordered rule list. The first rule whose `if` conditions all match the
     * answers wins; the last rule has no conditions, so every combination
     * resolves to a protocol.
     *
     * @return array{questions: array<int, array<string, mixed>>, rules: array<int, array<string, mixed>>}
     */
    private function decisionTree(): array
    {
        return [
            'questions' => [
                [
                    'id' => 'who',
                    'label' => 'Who talks?',
                    'options' => [
                        ['value' => 'user', 'label' => 'Agent ↔ user'],
                        ['value' => 'ui', 'label' => 'Agent ↔ UI'],
                        ['value' => 'agent', 'label' => 'Agent ↔ agent'],
                        ['value' => 'merchant', 'label' => 'Agent ↔ merchant'],
                        ['value' => 'payment', 'label' => 'Agent ↔ payment'],
                    ],
                ],
                [
                    'id' => 'streaming',
                    'label' => 'Streaming?',
                    'options' => [
                        ['value' => 'yes', 'label' => 'Yes — live updates'],
                        ['value' => 'no', 'label' => 'No — request / response'],
                    ],
                ],
                [
                    'id' => 'money',
                    'label' => 'Money involved?',
                    'options' => [
                        ['value' => 'yes', 'label' => 'Yes'],
                        ['value' => 'no', 'label' => 'No'],
                    ],
                ],
            ],
            'rules' => [
                [
                    'if' => ['who' => 'payment'],
                    'key' => 'ap2', 'name' => 'AP2', 'accent' => 'ap2',
                    'why' => 'Payment authority is exactly what AP2 proves — a human-signed Intent Mandate plus a matching Cart Mandate, verifiable end to end.',
                ],
                [
                    'if' => ['who' => 'merchant'],
                    'key' => 'ucp', 'name' => 'UCP', 'accent' => 'ucp',
                    'why' => 'Carts and checkout against a merchant manifest are UCP\'s handshake — add AP2 when the authorization needs to be signed.',
                ],
                [
                    'if' => ['money' => 'yes'],
                    'key' => 'ap2', 'name' => 'AP2', 'accent' => 'ap2',
                    'why' => 'Whenever money is committed, prove authority first: AP2\'s signed mandate chain makes the human-approved scope verifiable.',
                ],
                [
                    'if' => ['who' => 'agent'],
                    'key' => 'a2a', 'name' => 'A2A', 'accent' => 'a2a',
                    'why' => 'Two independent agents cooperating is A2A: discover the Agent Card, delegate over JSON-RPC, follow the task lifecycle.',
                ],
                [
                    'if' => ['who' => 'ui'],
                    'key' => 'a2ui', 'name' => 'A2UI', 'accent' => 'a2ui',
                    'why' => 'The agent should shape the interface as data — an A2UI surface your trusted client renders natively, never executable code.',
                ],
                [
                    'if' => ['who' => 'user', 'streaming' => 'yes'],
                    'key' => 'agui', 'name' => 'AG-UI', 'accent' => 'agui',
                    'why' => 'A person watching an agent work in real time wants AG-UI: one SSE stream of typed events with a confirm gate before writes.',
                ],
                [
                    'if' => ['streaming' => 'yes'],
                    'key' => 'agui', 'name' => 'AG-UI', 'accent' => 'agui',
                    'why' => 'Live, interruptible output means an event stream — AG-UI keeps the UI in lockstep while the agent thinks.',
                ],
                [
                    'if' => [],
                    'key' => 'a2ui', 'name' => 'A2UI', 'accent' => 'a2ui',
                    'why' => 'No live stream and no money — let the agent emit a surface once and render it natively. Simple, safe, inspectable.',
                ],
            ],
        ];
    }

    /**
     * Glossary terms surfaced at the end of the field guide.
     *
     * @return array<int, array<string, string>>
     */
    private function glossary(): array
    {
        return [
            ['term' => 'Surface', 'tag' => 'A2UI', 'accent' => 'a2ui', 'def' => 'The unit an A2UI agent emits: a flat list of components with exactly one root, rebuilt into a native UI tree by a trusted renderer.'],
            ['term' => 'Data model', 'tag' => 'A2UI', 'accent' => 'a2ui', 'def' => 'The live JSON document a surface binds to. Inputs read and write it via JSON-Pointer paths; it stays local until an action fires.'],
            ['term' => 'Generative UI', 'tag' => 'AG-UI', 'accent' => 'agui', 'def' => 'Tool calls that render as real interface components — tables, cards, confirm dialogs — instead of plain text in a chat bubble.'],
            ['term' => 'RunAgentInput', 'tag' => 'AG-UI', 'accent' => 'agui', 'def' => 'The request body that opens an AG-UI run: thread id, message history, shared state, and the tools the frontend offers the agent.'],
            ['term' => 'SSE', 'tag' => 'transport', 'accent' => 'mcp', 'def' => 'Server-Sent Events — one long-lived HTTP response that pushes small text frames to the browser. AG-UI\'s transport of choice.'],
            ['term' => 'Event stream', 'tag' => 'AG-UI', 'accent' => 'agui', 'def' => 'The ordered sequence of typed events (TEXT_MESSAGE, TOOL_CALL, STATE_DELTA, lifecycle markers) an agent emits during a run.'],
            ['term' => 'Human-in-the-loop', 'tag' => 'pattern', 'accent' => 'mcp', 'def' => 'A deliberate pause where a person must approve before the agent proceeds — the confirm gate every protocol here places before a write.'],
            ['term' => 'Agent Card', 'tag' => 'A2A', 'accent' => 'a2a', 'def' => 'A public manifest describing an agent\'s identity, skills and endpoints, fetched by clients to discover what can be delegated.'],
            ['term' => 'Task lifecycle', 'tag' => 'A2A', 'accent' => 'a2a', 'def' => 'The defined states a delegated task moves through: submitted → working → input-required → completed (or failed).'],
            ['term' => 'Artifact', 'tag' => 'A2A', 'accent' => 'a2a', 'def' => 'The structured result a server agent returns when a task completes — a document, a plan, a dataset; not just a chat message.'],
            ['term' => 'JSON-RPC', 'tag' => 'transport', 'accent' => 'mcp', 'def' => 'A minimal remote-procedure-call envelope over JSON (method + params + id). A2A and MCP both speak JSON-RPC 2.0.'],
            ['term' => 'Merchant manifest', 'tag' => 'UCP', 'accent' => 'ucp', 'def' => 'The machine-readable catalog + checkout contract a merchant publishes so shopping agents can discover products and rules.'],
            ['term' => 'Checkout session', 'tag' => 'UCP', 'accent' => 'ucp', 'def' => 'The stateful UCP conversation that walks a cart through discovering → building_cart → review → authorization → confirmed.'],
            ['term' => 'Intent Mandate', 'tag' => 'AP2', 'accent' => 'ap2', 'def' => 'A human-signed token granting an agent bounded purchasing authority: a spending cap, a merchant scope, and an expiry.'],
            ['term' => 'Cart Mandate', 'tag' => 'AP2', 'accent' => 'ap2', 'def' => 'An agent-signed token for one exact order that references the Intent Mandate — the second link in the verifiable chain.'],
            ['term' => 'JWS / JWT', 'tag' => 'crypto', 'accent' => 'ap2', 'def' => 'JSON Web Signature / Token — a compact signed payload. Change one byte and the signature no longer verifies; AP2 relies on this.'],
            ['term' => 'eID endpoint', 'tag' => 'TYPO3', 'accent' => 'mcp', 'def' => 'A lightweight TYPO3 frontend entry point (?eID=…) that skips page rendering — how this extension serves its JSON and SSE routes.'],
            ['term' => 'Import map', 'tag' => 'TYPO3', 'accent' => 'mcp', 'def' => 'The browser mapping from bare module names like @webconsulting/agent-nexus/ to real URLs, so backend JS loads as native ES modules.'],
        ];
    }
}
