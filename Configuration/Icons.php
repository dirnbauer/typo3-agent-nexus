<?php

declare(strict_types=1);

use TYPO3\CMS\Core\Imaging\IconProvider\SvgIconProvider;

/**
 * The Agent Nexus icon family.
 *
 * All icons are drawn on the same 16 grid with 1.5px strokes in currentColor and
 * one accent in var(--icon-color-accent), so they read as one set in both backend
 * color schemes. The two layers say different things on purpose:
 *
 *   agentnexus-module-*  — the protocol *edge*: an agent and who it talks to.
 *   agentnexus-plugin-*  — the *widget* an editor places on a page.
 *   agentnexus-status-*  — the hub's health chips.
 *
 * The pre-3.0 identifiers are kept as aliases so third-party TCA, TSconfig and
 * templates keep working. They are deprecated and will be removed in 4.0.
 */
$icons = [
    // ---- module tree + one per protocol ---------------------------------
    'agentnexus-module' => 'agentnexus-module.svg',
    'agentnexus-module-a2ui' => 'agentnexus-module-a2ui.svg',
    'agentnexus-module-agui' => 'agentnexus-module-agui.svg',
    'agentnexus-module-a2a' => 'agentnexus-module-a2a.svg',
    'agentnexus-module-ucp' => 'agentnexus-module-ucp.svg',
    'agentnexus-module-ap2' => 'agentnexus-module-ap2.svg',

    // ---- frontend plugins -------------------------------------------------
    'agentnexus-plugin-inquiry' => 'agentnexus-plugin-inquiry.svg',
    'agentnexus-plugin-assistant' => 'agentnexus-plugin-assistant.svg',
    'agentnexus-plugin-concierge' => 'agentnexus-plugin-concierge.svg',
    'agentnexus-plugin-checkout' => 'agentnexus-plugin-checkout.svg',
    'agentnexus-plugin-trustedsurface' => 'agentnexus-plugin-trustedsurface.svg',
    'agentnexus-plugin-protocolinfo' => 'agentnexus-plugin-protocolinfo.svg',

    // ---- hub health chips -------------------------------------------------
    'agentnexus-status-ok' => 'agentnexus-status-ok.svg',
    'agentnexus-status-warn' => 'agentnexus-status-warn.svg',
    'agentnexus-status-danger' => 'agentnexus-status-danger.svg',

    // ---- deprecated since 3.0, removed in 4.0 -----------------------------
    'agentnexus-overview' => 'agentnexus-module.svg',
    'a2ui-module' => 'agentnexus-module-a2ui.svg',
    'agui-module' => 'agentnexus-module-agui.svg',
    'a2a-module' => 'agentnexus-module-a2a.svg',
    'ucp-module' => 'agentnexus-module-ucp.svg',
    'ap2-module' => 'agentnexus-module-ap2.svg',
    'a2ui-plugin-inquiry' => 'agentnexus-plugin-inquiry.svg',
    'agui-plugin-assistant' => 'agentnexus-plugin-assistant.svg',
    'a2a-plugin-concierge' => 'agentnexus-plugin-concierge.svg',
    'ucp-plugin-checkout' => 'agentnexus-plugin-checkout.svg',
    'ap2-plugin-trusted' => 'agentnexus-plugin-trustedsurface.svg',
];

$registry = [];
foreach ($icons as $identifier => $file) {
    $registry[$identifier] = [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:agent_nexus/Resources/Public/Icons/' . $file,
    ];
}

return $registry;
