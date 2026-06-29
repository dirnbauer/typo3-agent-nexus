<?php

declare(strict_types=1);

use Webconsulting\AgentNexus\A2a\Controller\A2aController;
use Webconsulting\AgentNexus\A2ui\Controller\A2UIController;
use Webconsulting\AgentNexus\Agentstack\Controller\OverviewController;
use Webconsulting\AgentNexus\Agui\Controller\AguiController;
use Webconsulting\AgentNexus\Ap2\Controller\Ap2Controller;
use Webconsulting\AgentNexus\Ucp\Controller\UcpController;

return [
    'agentstack' => [
        'labels' => 'LLL:EXT:agent_nexus/Resources/Private/Language/locallang_mod.xlf',
        'iconIdentifier' => 'agentnexus-module',
        'position' => ['after' => 'web'],
    ],
    'agentstack_overview' => [
        'parent' => 'agentstack',
        'position' => ['top'],
        'access' => 'user',
        'workspaces' => 'live',
        'path' => '/module/agent-nexus/overview',
        'labels' => 'LLL:EXT:agent_nexus/Resources/Private/Language/locallang_overview.xlf',
        'extensionName' => 'AgentNexus',
        'iconIdentifier' => 'agentnexus-overview',
        'controllerActions' => [
            OverviewController::class => [
                'index',
            ],
        ],
    ],
    'agentstack_a2ui' => [
        'parent' => 'agentstack',
        'position' => ['after' => 'agentstack_overview'],
        'access' => 'user',
        'workspaces' => 'live',
        'path' => '/module/agent-nexus/a2ui',
        'labels' => 'LLL:EXT:agent_nexus/Resources/Private/Language/locallang_a2ui.xlf',
        'extensionName' => 'AgentNexus',
        'iconIdentifier' => 'a2ui-module',
        'controllerActions' => [
            A2UIController::class => [
                'dashboard',
                'generate',
                'respond',
                'demo',
            ],
        ],
    ],
    'agentstack_agui' => [
        'parent' => 'agentstack',
        'position' => ['after' => 'agentstack_a2ui'],
        'access' => 'user',
        'workspaces' => 'live',
        'path' => '/module/agent-nexus/agui',
        'labels' => 'LLL:EXT:agent_nexus/Resources/Private/Language/locallang_agui.xlf',
        'extensionName' => 'AgentNexus',
        'iconIdentifier' => 'agui-module',
        'controllerActions' => [
            AguiController::class => [
                'console',
                'catalog',
            ],
        ],
    ],
    'agentstack_a2a' => [
        'parent' => 'agentstack',
        'position' => ['after' => 'agentstack_agui'],
        'access' => 'user',
        'workspaces' => 'live',
        'path' => '/module/agent-nexus/a2a',
        'labels' => 'LLL:EXT:agent_nexus/Resources/Private/Language/locallang_a2a.xlf',
        'extensionName' => 'AgentNexus',
        'iconIdentifier' => 'a2a-module',
        'controllerActions' => [
            A2aController::class => [
                'console',
                'catalog',
            ],
        ],
    ],
    'agentstack_ucp' => [
        'parent' => 'agentstack',
        'position' => ['after' => 'agentstack_a2a'],
        'access' => 'user',
        'workspaces' => 'live',
        'path' => '/module/agent-nexus/ucp',
        'labels' => 'LLL:EXT:agent_nexus/Resources/Private/Language/locallang_ucp.xlf',
        'extensionName' => 'AgentNexus',
        'iconIdentifier' => 'ucp-module',
        'controllerActions' => [
            UcpController::class => [
                'console',
                'catalog',
            ],
        ],
    ],
    'agentstack_ap2' => [
        'parent' => 'agentstack',
        'position' => ['after' => 'agentstack_ucp'],
        'access' => 'user',
        'workspaces' => 'live',
        'path' => '/module/agent-nexus/ap2',
        'labels' => 'LLL:EXT:agent_nexus/Resources/Private/Language/locallang_ap2.xlf',
        'extensionName' => 'AgentNexus',
        'iconIdentifier' => 'ap2-module',
        'controllerActions' => [
            Ap2Controller::class => [
                'studio',
                'catalog',
            ],
        ],
    ],
];
