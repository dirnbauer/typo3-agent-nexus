<?php

declare(strict_types=1);

/*
 * Unit test bootstrap.
 *
 * Agent Nexus declares its services final, which is right for production and
 * impossible for PHPUnit to double. bypass-finals strips the keyword while
 * classes are loaded — in the test process only, and only for this extension's
 * own classes, so nothing in TYPO3 or in a vendor package is affected.
 *
 * It has to run before the testing framework's bootstrap, because that one
 * starts loading classes.
 */

$root = dirname(__DIR__, 2);

require $root . '/.Build/vendor/autoload.php';

DG\BypassFinals::setWhitelist([$root . '/Classes/*']);
DG\BypassFinals::enable();

require $root . '/.Build/vendor/typo3/testing-framework/Resources/Core/Build/UnitTestsBootstrap.php';
