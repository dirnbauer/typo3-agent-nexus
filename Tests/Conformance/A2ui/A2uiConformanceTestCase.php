<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Tests\Conformance\A2ui;

use Webconsulting\AgentNexus\Tests\Conformance\ConformanceTestCase;

/**
 * Conformance tests for A2UI. The assertions validate with {@see A2uiSchemas},
 * which binds the envelope's `catalog.json` placeholder to the basic
 * catalogue as the specification instructs; the shared harness has no such
 * binding.
 */
abstract class A2uiConformanceTestCase extends ConformanceTestCase
{
    protected static function assertConformsTo(string $schemaUri, mixed $data, string $message = ''): void
    {
        self::assertSame(
            [],
            A2uiSchemas::errors($data, $schemaUri),
            ($message !== '' ? $message . "\n" : '')
                . 'Does not conform to ' . $schemaUri . ":\n"
                . (string)json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        );
    }

    protected static function assertViolates(string $schemaUri, mixed $data, string $message = ''): void
    {
        self::assertNotSame([], A2uiSchemas::errors($data, $schemaUri), $message !== '' ? $message : 'Expected a schema violation.');
    }
}
