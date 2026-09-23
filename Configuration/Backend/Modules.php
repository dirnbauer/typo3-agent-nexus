<?php

declare(strict_types=1);

use Webconsulting\AgentNexus\A2a\Controller\A2aModuleController;
use Webconsulting\AgentNexus\A2ui\Controller\A2uiModuleController;
use Webconsulting\AgentNexus\Agentstack\Controller\InspectorController;
use Webconsulting\AgentNexus\Agentstack\Controller\OverviewController;
use Webconsulting\AgentNexus\Agentstack\Controller\TrafficController;
use Webconsulting\AgentNexus\Agui\Controller\AguiModuleController;
use Webconsulting\AgentNexus\Ap2\Controller\Ap2ModuleController;
use Webconsulting\AgentNexus\Ucp\Controller\UcpModuleController;

/**
 * The Agent Nexus backend.
 *
 *   Agent Nexus
 *   ├── Overview                 what runs here, which spec versions, recent activity
 *   ├── A2UI · AG-UI · A2A · UCP · AP2
 *   │     └── two screens each, switched through the DocHeader module menu
 *   ├── Inspector                one screen per protocol object: surfaces, runs,
 *   │                            tasks, checkout sessions, mandates
 *   └── Traffic                  every request, response and streamed event
 *
 * The second-level modules of 3.x were called agentstack_*; they are kept as
 * aliases so bookmarks and links keep working, and the "Agent Nexus: migrate
 * module permissions" upgrade wizard rewrites be_groups/be_users.
 *
 * Deliberately no `workspaces` restriction: nothing here edits versioned
 * content, and a live-only module disappears for anyone working in a draft
 * workspace.
 *
 * @param string $key label key prefix in locallang_modules.xlf
 * @return array{title: string, shortDescription: string, description: string}
 */
$labels = static fn(string $key): array => [
    'title' => 'LLL:EXT:agent_nexus/Resources/Private/Language/locallang_modules.xlf:' . $key . '.title',
    'shortDescription' => 'LLL:EXT:agent_nexus/Resources/Private/Language/locallang_modules.xlf:' . $key . '.short_description',
    'description' => 'LLL:EXT:agent_nexus/Resources/Private/Language/locallang_modules.xlf:' . $key . '.description',
];

/**
 * A protocol section: a parent with no screen of its own that opens its first
 * submodule, and one third-level module per screen.
 *
 * @param array<string, array{0: class-string, 1: string}> $screens name => [controller, action]
 * @return array<string, array<string, mixed>>
 */
$section = static function (string $protocol, string $after, array $screens) use ($labels): array {
    $modules = [
        'agentnexus_' . $protocol => [
            'parent' => 'agentnexus',
            'position' => ['after' => $after],
            'access' => 'user',
            'path' => '/module/agent-nexus/' . $protocol,
            'labels' => $labels($protocol),
            'iconIdentifier' => 'agentnexus-module-' . $protocol,
            'aliases' => ['agentstack_' . $protocol],
            'appearance' => ['dependsOnSubmodules' => true],
        ],
    ];
    foreach ($screens as $screen => [$controller, $action]) {
        $modules['agentnexus_' . $protocol . '_' . $screen] = [
            'parent' => 'agentnexus_' . $protocol,
            'access' => 'user',
            'path' => '/module/agent-nexus/' . $protocol . '/' . $screen,
            'labels' => $labels($protocol . '.' . $screen),
            'iconIdentifier' => 'agentnexus-module-' . $protocol,
            'routes' => [
                '_default' => ['target' => $controller . '::' . $action],
            ],
        ];
    }
    return $modules;
};

/**
 * One inspector screen per protocol object kind.
 *
 * @return array<string, array<string, mixed>>
 */
$inspector = static function (string $screen, string $kind, string $icon) use ($labels): array {
    return [
        'agentnexus_inspector_' . $screen => [
            'parent' => 'agentnexus_inspector',
            'access' => 'user',
            'path' => '/module/agent-nexus/inspector/' . $screen,
            'labels' => $labels('inspector.' . $screen),
            'iconIdentifier' => $icon,
            'routeOptions' => ['agentnexus_kind' => $kind],
            'routes' => [
                '_default' => ['target' => InspectorController::class . '::listAction'],
                'detail' => ['target' => InspectorController::class . '::detailAction'],
            ],
        ],
    ];
};

return [
    'agentnexus' => [
        'labels' => $labels('main'),
        'iconIdentifier' => 'agentnexus-module',
        'position' => ['after' => 'web'],
        'aliases' => ['agentstack'],
    ],
    'agentnexus_overview' => [
        'parent' => 'agentnexus',
        'position' => ['top'],
        'access' => 'user',
        'path' => '/module/agent-nexus/overview',
        'labels' => $labels('overview'),
        'iconIdentifier' => 'agentnexus-module',
        'aliases' => ['agentstack_overview'],
        'routes' => [
            '_default' => ['target' => OverviewController::class . '::indexAction'],
        ],
    ],
    ...$section('a2ui', 'agentnexus_overview', [
        'playground' => [A2uiModuleController::class, 'playgroundAction'],
        'catalog' => [A2uiModuleController::class, 'catalogAction'],
    ]),
    ...$section('agui', 'agentnexus_a2ui', [
        'console' => [AguiModuleController::class, 'consoleAction'],
        'events' => [AguiModuleController::class, 'eventsAction'],
    ]),
    ...$section('a2a', 'agentnexus_agui', [
        'console' => [A2aModuleController::class, 'consoleAction'],
        'card' => [A2aModuleController::class, 'cardAction'],
    ]),
    ...$section('ucp', 'agentnexus_a2a', [
        'console' => [UcpModuleController::class, 'consoleAction'],
        'profile' => [UcpModuleController::class, 'profileAction'],
    ]),
    ...$section('ap2', 'agentnexus_ucp', [
        'studio' => [Ap2ModuleController::class, 'studioAction'],
        'reference' => [Ap2ModuleController::class, 'referenceAction'],
    ]),
    'agentnexus_inspector' => [
        'parent' => 'agentnexus',
        'position' => ['after' => 'agentnexus_ap2'],
        'access' => 'user',
        'path' => '/module/agent-nexus/inspector',
        'labels' => $labels('inspector'),
        'iconIdentifier' => 'agentnexus-module-inspector',
        'appearance' => ['dependsOnSubmodules' => true],
    ],
    ...$inspector('tasks', 'task', 'agentnexus-module-a2a'),
    ...$inspector('runs', 'run', 'agentnexus-module-agui'),
    ...$inspector('checkouts', 'checkout', 'agentnexus-module-ucp'),
    ...$inspector('mandates', 'mandate', 'agentnexus-module-ap2'),
    ...$inspector('surfaces', 'surface', 'agentnexus-module-a2ui'),
    'agentnexus_traffic' => [
        'parent' => 'agentnexus',
        'position' => ['after' => 'agentnexus_inspector'],
        'access' => 'user',
        'path' => '/module/agent-nexus/traffic',
        'labels' => $labels('traffic'),
        'iconIdentifier' => 'agentnexus-module-traffic',
        'routes' => [
            '_default' => ['target' => TrafficController::class . '::listAction'],
            'detail' => ['target' => TrafficController::class . '::detailAction'],
        ],
    ],
];
