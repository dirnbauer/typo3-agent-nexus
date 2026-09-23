<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Tests\Conformance\A2ui;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Webconsulting\AgentNexus\A2ui\Domain\Model\A2uiVersion;
use Webconsulting\AgentNexus\A2ui\Domain\Model\PropertyDefinition;
use Webconsulting\AgentNexus\A2ui\Domain\Repository\ComponentRegistry;
use Webconsulting\AgentNexus\Tests\Conformance\ConformanceTestCase;
use Webconsulting\AgentNexus\Tests\Conformance\SchemaValidator;

/**
 * The registry is written out by hand; this test reads the published basic
 * catalogues (vendored under Tests/Conformance/Schemas/a2ui) and compares
 * every component, property, required list, enum, default and function
 * argument, so the two can never drift apart.
 */
final class CatalogueConformanceTest extends ConformanceTestCase
{
    private const array FILES = ['v0.9.1' => 'a2ui/v0.9.1', 'v1.0' => 'a2ui/v1.0'];

    /**
     * @return \Generator<string, array{A2uiVersion}>
     */
    public static function versions(): \Generator
    {
        foreach (A2uiVersion::cases() as $version) {
            yield $version->value => [$version];
        }
    }

    #[Test]
    #[DataProvider('versions')]
    public function theCatalogueIdIsThePublishedOne(A2uiVersion $version): void
    {
        $catalog = self::catalog($version);

        self::assertSame($version->catalogId(), $catalog['catalogId']);
        self::assertSame($version->catalogId(), $catalog['$id']);
    }

    #[Test]
    #[DataProvider('versions')]
    public function theComponentsAreThoseOfThePublishedCatalogue(A2uiVersion $version): void
    {
        $published = self::publishedComponents($version);
        $registry = new ComponentRegistry();

        self::assertSame(array_keys($published), array_keys($registry->components($version)));
        foreach ($registry->components($version) as $name => $definition) {
            $actual = [];
            foreach ($definition->properties as $property) {
                $actual[$property->name] = self::describe($property);
            }
            ksort($actual);
            self::assertSame($published[$name], $actual, $name . ' differs from the published ' . $version->value . ' catalogue.');
        }
    }

    #[Test]
    #[DataProvider('versions')]
    public function theCommonPropertiesAreThoseOfTheCommonTypes(A2uiVersion $version): void
    {
        $common = self::json($version, 'common_types.json')['$defs']['ComponentCommon'];
        $expected = array_keys($common['properties']);
        $expected[] = 'weight';
        $actual = array_map(static fn(PropertyDefinition $property): string => $property->name, (new ComponentRegistry())->commonProperties($version));
        sort($expected);
        sort($actual);

        self::assertSame($expected, $actual);
        self::assertSame(['id'], $common['required']);

        $attributes = self::json($version, 'common_types.json')['$defs']['AccessibilityAttributes']['properties'];
        self::assertSame(
            array_keys($attributes),
            array_map(static fn(PropertyDefinition $property): string => $property->name, (new ComponentRegistry())->accessibilityAttributes($version)),
        );
    }

    #[Test]
    #[DataProvider('versions')]
    public function theFunctionsAreThoseOfThePublishedCatalogue(A2uiVersion $version): void
    {
        $published = self::catalog($version)['functions'];
        $registry = new ComponentRegistry();

        self::assertSame(array_keys($published), array_keys($registry->functions($version)));
        foreach ($registry->functions($version) as $name => $function) {
            $schema = $published[$name];
            $returnType = $schema['returnType'] ?? $schema['properties']['returnType']['const'] ?? null;
            self::assertSame($returnType, $function->returnType, $name . ' returns something else.');

            $arguments = $schema['properties']['args'];
            $expected = [];
            foreach ($arguments['properties'] as $argument => $argumentSchema) {
                $expected[$argument] = self::publishedType($argumentSchema, in_array($argument, $arguments['required'] ?? [], true));
            }
            $actual = [];
            foreach ($function->arguments as $argument) {
                $actual[$argument->name] = self::describe($argument);
            }
            self::assertSame($expected, $actual, $name . ' takes other arguments.');

            $oneOf = array_merge(...array_map(static fn(array $rule): array => $rule['required'], $arguments['anyOf'] ?? []));
            self::assertSame($oneOf, $function->oneOfRequired, $name . ' needs one of other arguments.');
        }
    }

    #[Test]
    public function theIconNamesAreThePublishedOnes(): void
    {
        foreach (A2uiVersion::cases() as $version) {
            $name = self::catalog($version)['components']['Icon'];
            $properties = $name['properties'] ?? $name['allOf'][2]['properties'];
            self::assertSame($properties['name']['oneOf'][0]['enum'], ComponentRegistry::ICON_NAMES, $version->value);
        }
    }

    #[Test]
    public function onlyV091HasATheme(): void
    {
        $theme = self::catalog(A2uiVersion::V0_9_1)['$defs']['theme']['properties'];

        self::assertSame(
            array_keys($theme),
            array_map(static fn(PropertyDefinition $property): string => $property->name, (new ComponentRegistry())->themeProperties(A2uiVersion::V0_9_1)),
        );
        self::assertArrayNotHasKey('theme', self::catalog(A2uiVersion::V1_0)['$defs']);
    }

    /**
     * Each published component as property => description, the way
     * {@see describe()} writes a registry property.
     *
     * @return array<string, array<string, array<string, mixed>>>
     */
    private static function publishedComponents(A2uiVersion $version): array
    {
        $components = [];
        foreach (self::catalog($version)['components'] as $name => $schema) {
            $parts = $schema['allOf'] ?? [];
            if (isset($schema['properties'])) {
                $parts[] = ['properties' => $schema['properties'], 'required' => $schema['required'] ?? []];
            }
            $properties = [];
            $required = [];
            $checkable = false;
            foreach ($parts as $part) {
                if (isset($part['$ref'])) {
                    $checkable = $checkable || str_ends_with($part['$ref'], '/Checkable');
                    continue;
                }
                $properties += $part['properties'] ?? [];
                $required = [...$required, ...($part['required'] ?? [])];
            }
            unset($properties['component'], $properties['weight']);
            $described = [];
            foreach ($properties as $property => $propertySchema) {
                $described[$property] = self::publishedType($propertySchema, in_array($property, $required, true));
            }
            if ($checkable) {
                $described['checks'] = ['type' => 'Checks', 'required' => false];
            }
            ksort($described);
            $components[$name] = $described;
        }
        return $components;
    }

    /**
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private static function publishedType(array $schema, bool $required): array
    {
        $reference = static fn(array $s): string => isset($s['$ref']) && is_string($s['$ref']) ? substr($s['$ref'], (int)strrpos($s['$ref'], '/') + 1) : '';
        $type = match (true) {
            isset($schema['allOf']) && $reference($schema['allOf'][0]) === 'DynamicString' => 'DateTime',
            isset($schema['oneOf']) && isset($schema['oneOf'][0]['enum']) => 'IconName',
            isset($schema['oneOf']) => 'DynamicUri',
            $reference($schema) === 'Child' => 'ComponentId',
            $reference($schema) !== '' => $reference($schema),
            isset($schema['enum']) => 'enum',
            ($schema['type'] ?? null) === 'string' && ($schema['format'] ?? null) === 'uri' => 'uri',
            ($schema['type'] ?? null) === 'array' && isset($schema['items']['properties']['title']) => 'Tabs',
            ($schema['type'] ?? null) === 'array' && isset($schema['items']['properties']['label']) => 'Options',
            ($schema['type'] ?? null) === 'array' && $reference($schema['items'] ?? []) === 'DynamicBoolean' => 'DynamicBoolean[]',
            isset($schema['type']) && is_string($schema['type']) => $schema['type'],
            default => 'any',
        };
        $description = ['type' => $type, 'required' => $required];
        if ($type === 'enum' || $type === 'IconName') {
            $description['values'] = $type === 'enum' ? $schema['enum'] : $schema['oneOf'][0]['enum'];
        }
        if (array_key_exists('default', $schema)) {
            $description['default'] = $schema['default'];
        }
        $minimum = $schema['minimum'] ?? $schema['minItems'] ?? null;
        if ($minimum !== null) {
            $description['minimum'] = $minimum;
        }
        return $description;
    }

    /**
     * @return array<string, mixed>
     */
    private static function describe(PropertyDefinition $property): array
    {
        $description = ['type' => $property->type->value, 'required' => $property->required];
        if ($property->values !== []) {
            $description['values'] = $property->values;
        }
        if ($property->default !== null) {
            $description['default'] = $property->default;
        }
        if ($property->minimum !== null) {
            $description['minimum'] = $property->minimum;
        }
        return $description;
    }

    /**
     * @return array<string, mixed>
     */
    private static function catalog(A2uiVersion $version): array
    {
        return self::json($version, 'basic_catalog.json');
    }

    /**
     * @return array<string, mixed>
     */
    private static function json(A2uiVersion $version, string $file): array
    {
        $data = json_decode((string)file_get_contents(SchemaValidator::schemaDirectory() . '/' . self::FILES[$version->value] . '/' . $file), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        $object = [];
        foreach ($data as $key => $value) {
            $object[(string)$key] = $value;
        }
        return $object;
    }
}
