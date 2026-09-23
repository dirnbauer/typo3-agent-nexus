<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Tests\Conformance\Agui;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Webconsulting\AgentNexus\Agui\Protocol\EventType;
use Webconsulting\AgentNexus\Agui\Service\EventCatalog;
use Webconsulting\AgentNexus\Tests\Conformance\ConformanceTestCase;
use Webconsulting\AgentNexus\Tests\Conformance\SchemaValidator;

/**
 * The event catalogue is the one place Agent Nexus writes the AG-UI event set
 * down; here it is held against the specification's own schema, field by
 * field, and every example the event factory builds is validated.
 */
final class EventCatalogueTest extends ConformanceTestCase
{
    /** @var array<string, mixed>|null */
    private static ?array $schema = null;

    #[Test]
    public function theCatalogueHasExactlyTheEventTypesOfTheSchema(): void
    {
        $enum = self::definition('EventType')['enum'] ?? null;
        self::assertIsArray($enum);
        $catalogue = array_map(static fn(EventType $type): string => $type->value, EventType::cases());

        self::assertEqualsCanonicalizing($enum, $catalogue);
        self::assertCount(31, $catalogue);
    }

    /**
     * @return iterable<string, array{EventType}>
     */
    public static function eventTypes(): iterable
    {
        foreach (EventType::cases() as $type) {
            yield $type->value => [$type];
        }
    }

    #[Test]
    #[DataProvider('eventTypes')]
    public function theFieldsOfEveryEventMatchTheSchema(EventType $type): void
    {
        $definition = self::definition($type->schemaAnchor());
        $properties = $definition['properties'] ?? null;
        $required = $definition['required'] ?? null;
        self::assertIsArray($properties);
        self::assertIsArray($required);

        self::assertEqualsCanonicalizing(['type', ...array_keys($type->required())], $required, 'required fields');
        self::assertEqualsCanonicalizing(
            ['type', ...array_keys($type->required()), ...array_keys($type->optional())],
            array_keys($properties),
            'declared fields',
        );
        $composed = array_map(
            static fn(mixed $ref): string => is_array($ref) && is_string($ref['$ref'] ?? null) ? $ref['$ref'] : '',
            is_array($definition['allOf'] ?? null) ? $definition['allOf'] : [],
        );
        self::assertContains('#/$defs/BaseEvent', $composed, 'every event has the envelope');
        self::assertSame($type->attributable(), in_array('#/$defs/Attributable', $composed, true), 'attribution');
        self::assertFalse($definition['unevaluatedProperties'] ?? null, 'the event is closed');
    }

    #[Test]
    #[DataProvider('eventTypes')]
    public function theFactoryExampleOfEveryEventConforms(EventType $type): void
    {
        $example = EventCatalog::example($type);

        self::assertConformsTo(SchemaValidator::AGUI_SCHEMA_ID . '#' . $type->schemaAnchor(), $example);
        self::assertConformsTo(SchemaValidator::AGUI_SCHEMA_ID . '#Event', $example);
    }

    /**
     * @return array<string, mixed>
     */
    private static function definition(string $name): array
    {
        if (self::$schema === null) {
            $schema = json_decode((string)file_get_contents(SchemaValidator::schemaDirectory() . '/agui/1.0/schema.json'), true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($schema);
            self::$schema = $schema;
        }
        $definition = self::$schema['$defs'][$name] ?? null;
        self::assertIsArray($definition, 'No definition ' . $name . ' in the schema.');
        return $definition;
    }
}
