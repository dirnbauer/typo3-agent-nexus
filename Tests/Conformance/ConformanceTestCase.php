<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Tests\Conformance;

use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * Base class for the protocol conformance tests: assertions that a payload
 * validates against an official specification schema.
 */
abstract class ConformanceTestCase extends UnitTestCase
{
    protected static function assertConformsTo(string $schemaUri, mixed $data, string $message = ''): void
    {
        $errors = SchemaValidator::errors($data, $schemaUri);
        self::assertSame(
            [],
            $errors,
            ($message !== '' ? $message . "\n" : '')
                . 'Does not conform to ' . $schemaUri . ":\n"
                . (string)json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        );
    }

    protected static function assertViolates(string $schemaUri, mixed $data, string $message = ''): void
    {
        self::assertNotSame([], SchemaValidator::errors($data, $schemaUri), $message !== '' ? $message : 'Expected a schema violation.');
    }

    /**
     * Fields a specification marks REQUIRED that its generated JSON Schema does
     * not enforce (A2A's schema carries no `required` lists).
     *
     * @param array<array-key, mixed> $data
     * @param list<string> $fields
     */
    protected static function assertHasFields(array $data, array $fields, string $type): void
    {
        foreach ($fields as $field) {
            self::assertArrayHasKey($field, $data, sprintf('%s is missing its required field "%s".', $type, $field));
        }
    }

    /**
     * @return array<array-key, mixed>
     */
    protected static function fixture(string $relativePath): array
    {
        $path = SchemaValidator::schemaDirectory() . '/' . $relativePath;
        $data = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        return $data;
    }
}
