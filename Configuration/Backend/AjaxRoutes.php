<?php

declare(strict_types=1);

use Webconsulting\AgentNexus\A2ui\Controller\PlaygroundAjaxController;
use Webconsulting\AgentNexus\Agentstack\Controller\TrafficController;
use Webconsulting\AgentNexus\Agui\Controller\RunController;
use Webconsulting\AgentNexus\Ap2\Controller\MandateController;

/**
 * Backend-only endpoints of the consoles.
 *
 * A console that plays a *client* calls the public API like any other agent
 * would (A2A JSON-RPC, UCP REST); only what needs a backend user lives here.
 * The `agentnexus` option puts a route's exchanges into the traffic log;
 * `inheritAccessFromModule` limits a route to users who may open its screen.
 */
return [
    'agentnexus_a2ui_generate' => [
        'path' => '/agentnexus/a2ui/generate',
        'methods' => ['POST'],
        'target' => PlaygroundAjaxController::class . '::generate',
        'inheritAccessFromModule' => 'agentnexus_a2ui_playground',
        'agentnexus' => ['protocol' => 'a2ui', 'operation' => 'createSurface'],
    ],
    'agentnexus_a2ui_action' => [
        'path' => '/agentnexus/a2ui/action',
        'methods' => ['POST'],
        'target' => PlaygroundAjaxController::class . '::action',
        'inheritAccessFromModule' => 'agentnexus_a2ui_playground',
        'agentnexus' => ['protocol' => 'a2ui', 'operation' => 'action'],
    ],
    'agentnexus_agui_run' => [
        'path' => '/agentnexus/agui/run',
        'methods' => ['POST'],
        'target' => RunController::class . '::run',
        'inheritAccessFromModule' => 'agentnexus_agui_console',
        'agentnexus' => ['protocol' => 'agui', 'operation' => 'RunAgent'],
    ],
    'agentnexus_ap2_mint' => [
        'path' => '/agentnexus/ap2/mint',
        'methods' => ['POST'],
        'target' => MandateController::class . '::mint',
        'inheritAccessFromModule' => 'agentnexus_ap2_studio',
        'agentnexus' => ['protocol' => 'ap2', 'operation' => 'MintMandate'],
    ],
    'agentnexus_ap2_verify' => [
        'path' => '/agentnexus/ap2/verify',
        'methods' => ['POST'],
        'target' => MandateController::class . '::verify',
        'inheritAccessFromModule' => 'agentnexus_ap2_studio',
        'agentnexus' => ['protocol' => 'ap2', 'operation' => 'VerifyMandate'],
    ],
    'agentnexus_traffic_poll' => [
        'path' => '/agentnexus/traffic/poll',
        'methods' => ['GET'],
        'target' => TrafficController::class . '::pollAction',
        'inheritAccessFromModule' => 'agentnexus_traffic',
    ],
];
