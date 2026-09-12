<?php

declare(strict_types=1);

$config = \TYPO3\CodingStandards\CsFixerConfig::create();
$config->getFinder()
    ->in(__DIR__ . '/Classes')
    ->in(__DIR__ . '/Configuration')
    ->in(__DIR__ . '/Tests')
    ->append([__DIR__ . '/ext_localconf.php'])
    ->append([__DIR__ . '/.php-cs-fixer.dist.php']);

$config->setCacheFile(__DIR__ . '/var/cache/.php-cs-fixer.cache');

return $config;
