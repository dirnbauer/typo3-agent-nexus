<?php

declare(strict_types=1);

defined('TYPO3') or die();

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Extbase\Utility\ExtensionUtility;

$plugins = [
    [
        'plugin' => 'Inquiry',
        'legacyExtension' => 'A2uiIntegration',
        'title' => 'A2UI: Project inquiry',
        'icon' => 'agentnexus-plugin-inquiry',
        'description' => 'The visitor describes what they need. The agent builds a matching inquiry form.',
        'flexForm' => 'Inquiry.xml',
    ],
    [
        'plugin' => 'Assistant',
        'legacyExtension' => 'AguiIntegration',
        'title' => 'AG-UI: AI site assistant',
        'icon' => 'agentnexus-plugin-assistant',
        'description' => 'An AI assistant answers visitor questions live. It saves a lead only after the visitor approves.',
        'flexForm' => 'Assistant.xml',
    ],
    [
        'plugin' => 'Concierge',
        'legacyExtension' => 'A2aIntegration',
        'title' => 'A2A: Expert router',
        'icon' => 'agentnexus-plugin-concierge',
        'description' => 'Reads a visitor request and hands it to the right specialist agent as an A2A task.',
        'flexForm' => 'Concierge.xml',
    ],
    [
        'plugin' => 'Checkout',
        'legacyExtension' => 'UcpIntegration',
        'title' => 'UCP: Package and quote builder',
        'icon' => 'agentnexus-plugin-checkout',
        'description' => 'An AI shopping agent builds a service package and a simulated quote from what the visitor needs.',
        'flexForm' => 'Checkout.xml',
    ],
    [
        'plugin' => 'TrustedSurface',
        'legacyExtension' => 'Ap2Integration',
        'title' => 'AP2: Signed quote approval',
        'icon' => 'agentnexus-plugin-trustedsurface',
        'description' => 'The visitor approves a quote up to a spending cap and gets a simulated receipt that can be verified.',
        'flexForm' => 'TrustedSurface.xml',
    ],
    [
        'plugin' => 'ProtocolInfo',
        'legacyExtension' => null,
        'title' => 'Agent Nexus: Protocol info',
        'icon' => 'agentnexus-plugin-protocolinfo',
        'description' => 'Explains one protocol next to its demo: the sequence diagram, the endpoints on this site and the steps of a request.',
        'flexForm' => 'ProtocolInfo.xml',
    ],
    [
        'plugin' => 'Hub',
        'legacyExtension' => null,
        'title' => 'Agent Nexus: Protocol hub',
        'icon' => 'agentnexus-plugin-hub',
        'description' => 'The landing element: one card per protocol, with its status, its endpoints on this site and a link to its demo.',
        'flexForm' => 'Hub.xml',
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
    // replaced keep their old CType editable so existing records still open —
    // but deliberately do NOT get a "new content element" wizard entry, because
    // nobody should create one any more. The suffixed label tells an editor who
    // opens such a record what to do about it; the "Agent Nexus: migrate legacy
    // content element types" upgrade wizard rewrites them in bulk.
    if ($plugin['legacyExtension'] !== null) {
        $legacyCType = strtolower($plugin['legacyExtension'] . '_' . $plugin['plugin']);
        $configurePluginType($legacyCType, $plugin);

        $GLOBALS['TCA']['tt_content']['columns']['CType']['config']['items'][] = [
            'label' => $plugin['title'] . ' (deprecated, migrate to ' . $cType . ')',
            'value' => $legacyCType,
            'icon' => $plugin['icon'],
            'group' => 'plugins',
        ];
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
