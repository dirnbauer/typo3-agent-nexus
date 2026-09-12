<?php

declare(strict_types=1);

defined('TYPO3') or die();

/**
 * The seed key that makes `agentnexus:seed-site` idempotent.
 *
 * DataHandler only writes fields it knows from TCA, and the seed command goes
 * through DataHandler for everything, so the column has to be declared here. It
 * is machine state, never editable — hence "passthrough" and no showitem entry.
 */
$GLOBALS['TCA']['pages']['columns']['tx_agentnexus_seed_key'] = [
    'config' => [
        'type' => 'passthrough',
    ],
];
