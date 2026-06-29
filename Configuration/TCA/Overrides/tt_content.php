<?php

declare(strict_types=1);

defined('TYPO3') or die();

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Extbase\Utility\ExtensionUtility;

$plugins = [
    [
        'plugin' => 'Inquiry',
        'title' => 'A2UI: Smart Project Inquiry',
        'icon' => 'a2ui-plugin-inquiry',
        'description' => 'AI-assisted adaptive inquiry: the visitor describes their need and the agent builds the right intake form.',
        'flexForm' => 'Inquiry.xml',
    ],
    [
        'plugin' => 'Assistant',
        'title' => 'AG-UI: AI Site Assistant',
        'icon' => 'agui-plugin-assistant',
        'description' => 'A streaming AI assistant that answers visitor questions live and captures a lead only on approval.',
        'flexForm' => 'Assistant.xml',
    ],
    [
        'plugin' => 'Concierge',
        'title' => 'A2A: Expert Router',
        'icon' => 'a2a-plugin-concierge',
        'description' => 'Reads a visitor request and delegates it to the right specialist agent with the A2A task lifecycle.',
        'flexForm' => 'Concierge.xml',
    ],
    [
        'plugin' => 'Checkout',
        'title' => 'UCP: Package & Quote Builder',
        'icon' => 'ucp-plugin-checkout',
        'description' => 'An AI shopping agent assembles a recommended service package and simulated quote from visitor needs.',
        'flexForm' => 'Checkout.xml',
    ],
    [
        'plugin' => 'TrustedSurface',
        'title' => 'AP2: Signed Quote Authorization',
        'icon' => 'ap2-plugin-trusted',
        'description' => 'A visitor approves a quote up to a spending cap and receives a verifiable simulated authorization receipt.',
        'flexForm' => 'TrustedSurface.xml',
    ],
];

foreach ($plugins as $plugin) {
    $cType = ExtensionUtility::registerPlugin(
        'AgentNexus',
        $plugin['plugin'],
        $plugin['title'],
        $plugin['icon'],
        'plugins',
        $plugin['description'],
    );

    ExtensionManagementUtility::addPiFlexFormValue(
        '*',
        'FILE:EXT:agent_nexus/Configuration/FlexForms/' . $plugin['flexForm'],
        $cType,
    );

    ExtensionManagementUtility::addToAllTCAtypes(
        'tt_content',
        '--div--;Plugin,pi_flexform',
        $cType,
        'after:palette:headers',
    );

    $GLOBALS['TCA']['tt_content']['ctrl']['typeicon_classes'][$cType] = $plugin['icon'];
}
