<?php

declare(strict_types=1);

defined('TYPO3') or die();

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Extbase\Utility\ExtensionUtility;

$plugins = [
    [
        'plugin' => 'Inquiry',
        'legacyExtension' => 'A2uiIntegration',
        'title' => 'A2UI: Smart Project Inquiry',
        'icon' => 'agentnexus-plugin-inquiry',
        'description' => 'AI-assisted adaptive inquiry: the visitor describes their need and the agent builds the right intake form.',
        'flexForm' => 'Inquiry.xml',
    ],
    [
        'plugin' => 'Assistant',
        'legacyExtension' => 'AguiIntegration',
        'title' => 'AG-UI: AI Site Assistant',
        'icon' => 'agentnexus-plugin-assistant',
        'description' => 'A streaming AI assistant that answers visitor questions live and captures a lead only on approval.',
        'flexForm' => 'Assistant.xml',
    ],
    [
        'plugin' => 'Concierge',
        'legacyExtension' => 'A2aIntegration',
        'title' => 'A2A: Expert Router',
        'icon' => 'agentnexus-plugin-concierge',
        'description' => 'Reads a visitor request and delegates it to the right specialist agent with the A2A task lifecycle.',
        'flexForm' => 'Concierge.xml',
    ],
    [
        'plugin' => 'Checkout',
        'legacyExtension' => 'UcpIntegration',
        'title' => 'UCP: Package & Quote Builder',
        'icon' => 'agentnexus-plugin-checkout',
        'description' => 'An AI shopping agent assembles a recommended service package and simulated quote from visitor needs.',
        'flexForm' => 'Checkout.xml',
    ],
    [
        'plugin' => 'TrustedSurface',
        'legacyExtension' => 'Ap2Integration',
        'title' => 'AP2: Signed Quote Authorization',
        'icon' => 'agentnexus-plugin-trustedsurface',
        'description' => 'A visitor approves a quote up to a spending cap and receives a verifiable simulated authorization receipt.',
        'flexForm' => 'TrustedSurface.xml',
    ],
    [
        'plugin' => 'ProtocolInfo',
        'legacyExtension' => null,
        'title' => 'Agent Nexus: Protocol info',
        'icon' => 'agentnexus-plugin-protocolinfo',
        'description' => 'Explains one protocol next to its demo: the sequence diagram, the endpoints this site exposes and how a request flows through it.',
        'flexForm' => 'ProtocolInfo.xml',
    ],
];

$configurePluginType = static function (string $cType, array $plugin): void {
    $GLOBALS['TCA']['tt_content']['types'][$cType] ??= $GLOBALS['TCA']['tt_content']['types']['header'] ?? ['showitem' => ''];
    $GLOBALS['TCA']['tt_content']['types'][$cType]['columnsOverrides']['pi_flexform']['config']['ds']
        = 'FILE:EXT:agent_nexus/Configuration/FlexForms/' . $plugin['flexForm'];

    ExtensionManagementUtility::addToAllTCAtypes(
        'tt_content',
        '--div--;core.form.tabs:plugin, pi_flexform',
        $cType,
        'after:palette:headers',
    );

    $GLOBALS['TCA']['tt_content']['ctrl']['typeicon_classes'][$cType] = $plugin['icon'];
};

foreach ($plugins as $plugin) {
    $cType = ExtensionUtility::registerPlugin(
        'AgentNexus',
        $plugin['plugin'],
        $plugin['title'],
        $plugin['icon'],
        'plugins',
        $plugin['description'],
    );

    $configurePluginType($cType, $plugin);

    // Plugins that existed in one of the five per-protocol packages Agent Nexus
    // replaced keep their old CType registered so existing records still open.
    if ($plugin['legacyExtension'] !== null) {
        $legacyCType = strtolower($plugin['legacyExtension'] . '_' . $plugin['plugin']);
        $configurePluginType($legacyCType, $plugin);
    }
}

/**
 * The seed key that makes `agentnexus:seed-site` idempotent — see
 * Configuration/TCA/Overrides/pages.php for why it has to be declared in TCA.
 */
$GLOBALS['TCA']['tt_content']['columns']['tx_agentnexus_seed_key'] = [
    'config' => [
        'type' => 'passthrough',
    ],
];
