<?php

declare(strict_types=1);

use Webconsulting\AgentNexus\A2a\Controller\SendController;
use Webconsulting\AgentNexus\Agui\Controller\RunController;
use Webconsulting\AgentNexus\Ap2\Controller\MandateController;
use Webconsulting\AgentNexus\Ucp\Controller\CheckoutController;

return [
    'a2a_send' => [
        'path' => '/a2a/send',
        'target' => SendController::class . '::send',
    ],
    'agui_run' => [
        'path' => '/agui/run',
        'target' => RunController::class . '::run',
    ],
    'ap2_mint' => [
        'path' => '/ap2/mint',
        'target' => MandateController::class . '::mint',
    ],
    'ap2_verify' => [
        'path' => '/ap2/verify',
        'target' => MandateController::class . '::verify',
    ],
    'ucp_checkout' => [
        'path' => '/ucp/checkout',
        'target' => CheckoutController::class . '::checkout',
    ],
];
