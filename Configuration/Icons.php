<?php

declare(strict_types=1);

use TYPO3\CMS\Core\Imaging\IconProvider\SvgIconProvider;

/**
 * The Agent Nexus icon family.
 *
 * Every icon works in both backend color schemes: the silhouette is
 * currentColor and the one accent is var(--icon-color-accent). The grid follows
 * what each icon sits next to, which is what the TYPO3 v14 icon language asks
 * for:
 *
 *   agentnexus-module[-*]  64 grid, filled art — these sit in the module tree
 *                          beside Core's own module icons, so they match those
 *                          rather than the plugin icons. One centred silhouette
 *                          each; the accent marks what the protocol produces.
 *   agentnexus-plugin-*    16 grid, 1.5px line art — the content element
 *                          wizard and the list module.
 *   agentnexus-status-*    16 grid, 1.5px line art — the hub's health chips.
 *
 * Extension.svg is the same drawing as agentnexus-module: it is the brand mark.
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
