<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Tests\Conformance\Ap2;

use PHPUnit\Framework\Attributes\Test;
use Webconsulting\AgentNexus\Ap2\Crypto\Json;
use Webconsulting\AgentNexus\Ap2\Mandate\ConstraintType;
use Webconsulting\AgentNexus\Ap2\Mandate\MandateSchema;
use Webconsulting\AgentNexus\Ap2\Mandate\MandateType;
use Webconsulting\AgentNexus\Ap2\Mandate\Receipt;

/**
 * The PHP catalogue the verifiers and the reference screen use
 * ({@see MandateSchema}) says exactly what the vendored AP2 v0.2.0 schemas say:
 * the same fields, the same required ones, the same selectively disclosable
 * ones and the same types. If a new AP2 release changes a schema, this fails
 * until the catalogue follows.
 */
final class MandateSchemaCatalogueTest extends Ap2ConformanceTestCase
{
    private const array FILES = [
        'mandate.checkout.open.1' => 'ap2/open_checkout_mandate.json',
        'mandate.checkout.1' => 'ap2/checkout_mandate.json',
        'mandate.payment.open.1' => 'ap2/open_payment_mandate.json',
        'mandate.payment.1' => 'ap2/payment_mandate.json',
    ];

    /** How the catalogue names what a schema property references. */
    private const array REFERENCES = [
        'types/merchant.json' => 'Merchant',
        'types/amount.json' => 'Amount',
        'types/payment_instrument.json' => 'PaymentInstrument',
        'types/pisp.json' => 'PISP',
        'types/receipt_status.json' => 'Status',
        '#/$defs/line_item_requirements' => 'LineItemRequirement',
        '#/$defs/item' => 'Item',
    ];

    #[Test]
    public function everyMandateTypeNamesItsSchema(): void
    {
        foreach (MandateType::cases() as $type) {
            $schema = self::schema(self::FILES[$type->value]);
            self::assertSame($schema['$id'] ?? null, $type->schemaId(), $type->value);
            self::assertSame($type->value, Json::map(Json::map($schema['properties'] ?? null)['vct'] ?? null)['const'] ?? null, $type->value);
        }
    }

    #[Test]
    public function theMandateFieldsMatchTheSchemas(): void
    {
        foreach (MandateType::cases() as $type) {
            self::assertSame(
                self::fromSchema(self::schema(self::FILES[$type->value])),
                self::fromCatalogue(MandateSchema::fields($type)),
                $type->value,
            );
        }
    }

    #[Test]
    public function theConstraintsMatchTheSchemaDefinitions(): void
    {
        $seen = [];
        foreach ([MandateType::OpenCheckout, MandateType::OpenPayment] as $mandate) {
            foreach (Json::map(self::schema(self::FILES[$mandate->value])['$defs'] ?? null) as $name => $definition) {
                $definition = Json::map($definition);
                $const = Json::map(Json::map($definition['properties'] ?? null)['type'] ?? null)['const'] ?? null;
                if (!is_string($const)) {
                    continue;
                }
                $constraint = ConstraintType::tryFrom($const);
                self::assertNotNull($constraint, sprintf('The catalogue knows the constraint %s ($defs/%s).', $const, $name));
                self::assertSame($mandate, $constraint->mandate(), $const);
                self::assertSame(self::fromSchema($definition), self::fromCatalogue(MandateSchema::constraintFields($constraint)), $const);
                $seen[] = $constraint;
            }
        }

        self::assertEqualsCanonicalizing(ConstraintType::cases(), $seen, 'Every constraint type is in a schema, and only those.');
    }

    #[Test]
    public function theSharedTypesMatchTheSchemas(): void
    {
        $openCheckout = Json::map(self::schema(self::FILES['mandate.checkout.open.1'])['$defs'] ?? null);
        $expected = [
            'Merchant' => self::schema('ap2/types/merchant.json'),
            'Amount' => self::schema('ap2/types/amount.json'),
            'PaymentInstrument' => self::schema('ap2/types/payment_instrument.json'),
            'PISP' => self::schema('ap2/types/pisp.json'),
            'LineItemRequirement' => Json::map($openCheckout['line_item_requirements'] ?? null),
            'Item' => Json::map($openCheckout['item'] ?? null),
        ];

        $types = MandateSchema::types();
        self::assertSame(array_keys($expected), array_keys($types));
        foreach ($expected as $name => $schema) {
            self::assertSame(self::fromSchema($schema), self::fromCatalogue($types[$name]), $name);
        }
    }

    #[Test]
    public function theReceiptsMatchTheSchemasIncludingTheStatusDependentFields(): void
    {
        $receipts = MandateSchema::receipts();
        self::assertSame([Receipt::CHECKOUT, Receipt::PAYMENT], array_keys($receipts));

        foreach ($receipts as $name => $fields) {
            $schema = self::schema('ap2/' . $name . '.json');
            self::assertSame($schema['$id'] ?? null, MandateSchema::RECEIPT_SCHEMAS[$name]);

            $when = [];
            foreach (Json::objects($schema['oneOf'] ?? null) as $branch) {
                $status = Json::string(Json::map(Json::map($branch['properties'] ?? null)['status'] ?? null)['const'] ?? null);
                foreach (Json::list($branch['required'] ?? null) as $field) {
                    $when[Json::string($field)] = $status;
                }
            }
            $required = array_merge(array_map(Json::string(...), Json::list($schema['required'] ?? null)), array_keys($when));

            $fromSchema = [];
            foreach (Json::map($schema['properties'] ?? null) as $field => $property) {
                $fromSchema[$field] = [self::typeOf(Json::map($property)), in_array($field, $required, true), $when[$field] ?? ''];
            }
            $fromCatalogue = [];
            foreach ($fields as $field) {
                $fromCatalogue[$field['name']] = [$field['type'], $field['required'], $field['when']];
            }
            ksort($fromSchema);
            ksort($fromCatalogue);
            self::assertSame($fromSchema, $fromCatalogue, $name);
        }
        self::assertSame(['Success', 'Error'], Json::list(self::schema('ap2/types/receipt_status.json')['enum'] ?? null));
    }

    #[Test]
    public function theRecurrenceFrequenciesMatchTheSchema(): void
    {
        $recurrence = Json::map(Json::map(self::schema(self::FILES['mandate.payment.open.1'])['$defs'] ?? null)['agent_recurrence'] ?? null);

        self::assertSame(MandateSchema::FREQUENCIES, Json::list(Json::map(Json::map($recurrence['properties'] ?? null)['frequency'] ?? null)['enum'] ?? null));
    }

    /**
     * @param array<string, mixed> $schema
     * @return array<string, array{string, bool, string}> field => [type, required, disclosable]
     */
    private static function fromSchema(array $schema): array
    {
        $required = Json::list($schema['required'] ?? null);
        $fields = [];
        foreach (Json::map($schema['properties'] ?? null) as $name => $property) {
            $property = Json::map($property);
            $disclosable = match (true) {
                ($property['x-selectively-disclosable-field'] ?? false) === true => 'claim',
                ($property['x-selectively-disclosable-array'] ?? false) === true => 'elements',
                default => '',
            };
            $fields[$name] = [self::typeOf($property), in_array($name, $required, true), $disclosable];
        }
        ksort($fields);
        return $fields;
    }

    /**
     * @param list<array{name: string, type: string, required: bool, disclosable: string, when: string}> $fields
     * @return array<string, array{string, bool, string}>
     */
    private static function fromCatalogue(array $fields): array
    {
        $map = [];
        foreach ($fields as $field) {
            $map[$field['name']] = [$field['type'], $field['required'], $field['disclosable']];
        }
        ksort($map);
        return $map;
    }

    /**
     * The catalogue's name for a schema property's type.
     *
     * @param array<string, mixed> $property
     */
    private static function typeOf(array $property): string
    {
        $reference = $property['$ref'] ?? null;
        if (is_string($reference)) {
            self::assertArrayHasKey($reference, self::REFERENCES, 'Unknown reference ' . $reference);
            return self::REFERENCES[$reference];
        }
        $type = Json::string($property['type'] ?? null);
        if ($type === 'array') {
            $items = Json::map($property['items'] ?? null);
            return (isset($items['anyOf']) || isset($items['oneOf']) ? 'Constraint' : self::typeOf($items)) . '[]';
        }
        if ($type === 'string' && isset($property['enum'])) {
            return 'Frequency';
        }
        return $type;
    }
}
