<?php

declare(strict_types=1);

defined('TYPO3') or die();

use TYPO3\CMS\Core\Cache\Backend\FileBackend;
use TYPO3\CMS\Core\Cache\Frontend\VariableFrontend;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Extbase\Utility\ExtensionUtility;
use Webconsulting\AgentNexus\A2a\Controller\ConciergePluginController;
use Webconsulting\AgentNexus\A2a\Eid\AgentCardEndpoint;
use Webconsulting\AgentNexus\A2a\Eid\ConciergeEndpoint;
use Webconsulting\AgentNexus\A2a\Eid\RpcEndpoint;
use Webconsulting\AgentNexus\A2ui\Controller\InquiryPluginController;
use Webconsulting\AgentNexus\A2ui\Eid\InquiryEndpoint;
use Webconsulting\AgentNexus\Agui\Controller\AssistantPluginController;
use Webconsulting\AgentNexus\Agui\Eid\AssistantEndpoint;
use Webconsulting\AgentNexus\Ap2\Controller\TrustedSurfacePluginController;
use Webconsulting\AgentNexus\Ap2\Eid\AuthorizeEndpoint;
use Webconsulting\AgentNexus\Ucp\Controller\CheckoutPluginController;
use Webconsulting\AgentNexus\Ucp\Eid\CheckoutEndpoint;
use Webconsulting\AgentNexus\Ucp\Eid\ManifestEndpoint;

$GLOBALS['TYPO3_CONF_VARS']['FE']['eID_include']['a2ui_generate'] = InquiryEndpoint::class . '::generate';
$GLOBALS['TYPO3_CONF_VARS']['FE']['eID_include']['a2ui_submit'] = InquiryEndpoint::class . '::submit';
$GLOBALS['TYPO3_CONF_VARS']['FE']['eID_include']['agui_assistant'] = AssistantEndpoint::class . '::run';
$GLOBALS['TYPO3_CONF_VARS']['FE']['eID_include']['a2a_card'] = AgentCardEndpoint::class . '::card';
$GLOBALS['TYPO3_CONF_VARS']['FE']['eID_include']['a2a_rpc'] = RpcEndpoint::class . '::rpc';
$GLOBALS['TYPO3_CONF_VARS']['FE']['eID_include']['a2a_concierge'] = ConciergeEndpoint::class . '::run';
$GLOBALS['TYPO3_CONF_VARS']['FE']['eID_include']['ucp_manifest'] = ManifestEndpoint::class . '::manifest';
$GLOBALS['TYPO3_CONF_VARS']['FE']['eID_include']['ucp_checkout'] = CheckoutEndpoint::class . '::run';
$GLOBALS['TYPO3_CONF_VARS']['FE']['eID_include']['ap2_authorize'] = AuthorizeEndpoint::class . '::authorize';

$plugins = [
    'Inquiry' => [
        'controller' => InquiryPluginController::class,
        'legacyExtension' => 'A2uiIntegration',
    ],
    'Assistant' => [
        'controller' => AssistantPluginController::class,
        'legacyExtension' => 'AguiIntegration',
    ],
    'Concierge' => [
        'controller' => ConciergePluginController::class,
        'legacyExtension' => 'A2aIntegration',
    ],
    'Checkout' => [
        'controller' => CheckoutPluginController::class,
        'legacyExtension' => 'UcpIntegration',
    ],
    'TrustedSurface' => [
        'controller' => TrustedSurfacePluginController::class,
        'legacyExtension' => 'Ap2Integration',
    ],
];

$legacyTypoScriptAliases = [];

foreach ($plugins as $pluginName => $plugin) {
    ExtensionUtility::configurePlugin(
        'AgentNexus',
        $pluginName,
        [$plugin['controller'] => 'show'],
        [],
    );

    $legacyCType = strtolower($plugin['legacyExtension'] . '_' . $pluginName);
    $canonicalCType = strtolower('AgentNexus_' . $pluginName);
    $legacyTypoScriptAliases[] = 'tt_content.' . $legacyCType . ' =< tt_content.' . $canonicalCType;
}

ExtensionManagementUtility::addTypoScript(
    'AgentNexus',
    'setup',
    '
# Render records created by the former per-protocol packages through Agent Nexus.
' . implode("\n", $legacyTypoScriptAliases),
    'defaultContentRendering',
);

foreach (['a2ui', 'agui', 'a2a', 'ucp', 'ap2'] as $cacheName) {
    $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations'][$cacheName] ??= [
        'frontend' => VariableFrontend::class,
        'backend' => FileBackend::class,
        'groups' => ['system'],
    ];
}
