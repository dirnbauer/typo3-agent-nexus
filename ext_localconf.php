<?php

declare(strict_types=1);

defined('TYPO3') or die();

// The protocol endpoints are served by Shared\Http\Api\ApiRouter (see
// Configuration/RequestMiddlewares.php), not by eIDs: the specifications pin
// them to paths such as /.well-known/agent-card.json and
// /checkout-sessions/{id}, which an eID cannot express.

use TYPO3\CMS\Core\Cache\Backend\FileBackend;
use TYPO3\CMS\Core\Cache\Frontend\VariableFrontend;
use TYPO3\CMS\Extbase\Utility\ExtensionUtility;
use Webconsulting\AgentNexus\A2a\Controller\ConciergePluginController;
use Webconsulting\AgentNexus\A2ui\Controller\InquiryPluginController;
use Webconsulting\AgentNexus\Agentstack\Controller\HubPluginController;
use Webconsulting\AgentNexus\Agentstack\Controller\ProtocolInfoPluginController;
use Webconsulting\AgentNexus\Agui\Controller\AssistantPluginController;
use Webconsulting\AgentNexus\Ap2\Controller\TrustedSurfacePluginController;
use Webconsulting\AgentNexus\Ucp\Controller\CheckoutPluginController;

$plugins = [
    'Inquiry' => InquiryPluginController::class,
    'Assistant' => AssistantPluginController::class,
    'Concierge' => ConciergePluginController::class,
    'Checkout' => CheckoutPluginController::class,
    'TrustedSurface' => TrustedSurfacePluginController::class,
    'ProtocolInfo' => ProtocolInfoPluginController::class,
    'Hub' => HubPluginController::class,
];

foreach ($plugins as $pluginName => $controller) {
    ExtensionUtility::configurePlugin(
        'AgentNexus',
        $pluginName,
        [$controller => 'show'],
        [],
    );
}

// Rate-limit counters and UCP idempotency records. In no cache group, so only a
// full flush clears it: clearing the frontend or system caches during a demo
// must not reset a visitor's rate limit, and an idempotency key should survive
// the 24 hours UCP asks for.
$GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['agentnexus'] ??= [
    'frontend' => VariableFrontend::class,
    'backend' => FileBackend::class,
    'groups' => [],
];
