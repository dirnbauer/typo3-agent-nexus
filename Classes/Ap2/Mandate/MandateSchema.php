<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Ap2\Mandate;

use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Webconsulting\AgentNexus\Ap2\Crypto\Json;

/**
 * The AP2 v0.2.0 schemas (code/sdk/schemas/ap2) as one PHP catalogue: the
 * fields of every mandate, constraint, shared type and receipt, which of them
 * are required, and which are selectively disclosable.
 *
 * The verifiers check mandate content against it, the reference screen lists
 * it, and a conformance test compares it with the vendored JSON Schemas, so
 * the three cannot drift apart.
 *
 * A field is described as `{name, type, required, disclosable, when}`:
 * `disclosable` is `claim` (x-selectively-disclosable-field) or `elements`
 * (x-selectively-disclosable-array); `when` names the receipt status a field
 * belongs to ("Success", "Error") or is empty.
 */
#[Exclude]
final class MandateSchema
{
    /** @var array<string, list<array{0: string, 1: string, 2: bool, 3?: string}>> */
    private const array MANDATES = [
        'mandate.checkout.open.1' => [
            ['vct', 'string', true],
            ['constraints', 'Constraint[]', true],
            ['cnf', 'object', true],
            ['iat', 'integer', false],
            ['exp', 'integer', false],
        ],
        'mandate.checkout.1' => [
            ['vct', 'string', true],
            ['checkout_jwt', 'string', true, 'claim'],
            ['checkout_hash', 'string', true],
            ['iat', 'integer', false],
            ['exp', 'integer', false],
        ],
        'mandate.payment.open.1' => [
            ['vct', 'string', true],
            ['constraints', 'Constraint[]', true],
            ['cnf', 'object', true],
            ['payee', 'Merchant', false],
            ['payment_amount', 'Amount', false],
            ['payment_instrument', 'PaymentInstrument', false],
            ['pisp', 'PISP', false],
            ['execution_date', 'string', false],
            ['risk_data', 'object', false],
            ['iat', 'integer', false],
            ['exp', 'integer', false],
        ],
        'mandate.payment.1' => [
            ['vct', 'string', true],
            ['transaction_id', 'string', true],
            ['payee', 'Merchant', true],
            ['pisp', 'PISP', false],
            ['payment_amount', 'Amount', true],
            ['payment_instrument', 'PaymentInstrument', true],
            ['execution_date', 'string', false],
            ['risk_data', 'object', false],
            ['iat', 'integer', false],
            ['exp', 'integer', false],
        ],
    ];

    /** @var array<string, list<array{0: string, 1: string, 2: bool, 3?: string}>> */
    private const array CONSTRAINTS = [
        'checkout.allowed_merchants' => [
            ['type', 'string', true],
            ['allowed', 'Merchant[]', true, 'elements'],
        ],
        'checkout.line_items' => [
            ['type', 'string', true],
            ['items', 'LineItemRequirement[]', true],
        ],
        'payment.amount_range' => [
            ['type', 'string', true],
            ['currency', 'string', true],
            ['max', 'integer', true],
            ['min', 'integer', false],
        ],
        'payment.allowed_payees' => [
            ['type', 'string', true],
            ['allowed', 'Merchant[]', true, 'elements'],
        ],
        'payment.allowed_payment_instruments' => [
            ['type', 'string', true],
            ['allowed', 'PaymentInstrument[]', true, 'elements'],
        ],
        'payment.allowed_pisps' => [
            ['type', 'string', true],
            ['allowed', 'PISP[]', true],
        ],
        'payment.budget' => [
            ['type', 'string', true],
            ['max', 'number', true],
            ['currency', 'string', true],
        ],
        'payment.agent_recurrence' => [
            ['type', 'string', true],
            ['frequency', 'Frequency', true],
            ['max_occurrences', 'integer', false],
        ],
        'payment.execution_date' => [
            ['type', 'string', true],
            ['not_before', 'string', false],
            ['not_after', 'string', false],
        ],
        'payment.reference' => [
            ['type', 'string', true],
            ['conditional_transaction_id', 'string', true],
        ],
    ];

    /** @var array<string, list<array{0: string, 1: string, 2: bool, 3?: string}>> */
    private const array TYPES = [
        'Merchant' => [
            ['id', 'string', true],
            ['name', 'string', true],
            ['website', 'string', false],
        ],
        'Amount' => [
            ['amount', 'integer', true],
            ['currency', 'string', true],
        ],
        'PaymentInstrument' => [
            ['id', 'string', true],
            ['type', 'string', true],
            ['description', 'string', false],
        ],
        'PISP' => [
            ['legal_name', 'string', true],
            ['brand_name', 'string', true],
            ['domain_name', 'string', true],
        ],
        'LineItemRequirement' => [
            ['id', 'string', true],
            ['acceptable_items', 'Item[]', true, 'elements'],
            ['quantity', 'integer', true],
        ],
        'Item' => [
            ['id', 'string', true],
            ['title', 'string', true],
        ],
    ];

    /** @var array<string, list<array{0: string, 1: string, 2: bool, 3?: string, 4?: string}>> */
    private const array RECEIPTS = [
        'checkout_receipt' => [
            ['status', 'Status', true],
            ['iss', 'string', true],
            ['iat', 'integer', true],
            ['reference', 'string', true],
            ['order_id', 'string', true, '', 'Success'],
            ['error', 'string', true, '', 'Error'],
            ['error_description', 'string', true, '', 'Error'],
        ],
        'payment_receipt' => [
            ['status', 'Status', true],
            ['iss', 'string', true],
            ['iat', 'integer', true],
            ['reference', 'string', true],
            ['payment_id', 'string', true],
            ['psp_confirmation_id', 'string', true, '', 'Success'],
            ['network_confirmation_id', 'string', true, '', 'Success'],
            ['error', 'string', true, '', 'Error'],
            ['error_description', 'string', true, '', 'Error'],
        ],
    ];

    /** payment.agent_recurrence frequencies. */
    public const array FREQUENCIES = ['ON_DEMAND', 'DAILY', 'WEEKLY', 'BIWEEKLY', 'MONTHLY', 'QUARTERLY', 'ANNUALLY'];

    /** Schema $ids of the receipts. */
    public const array RECEIPT_SCHEMAS = [
        'checkout_receipt' => 'https://ap2-protocol.org/schemas/checkout_receipt.json',
        'payment_receipt' => 'https://ap2-protocol.org/schemas/payment_receipt.json',
    ];

    /**
     * @return list<array{name: string, type: string, required: bool, disclosable: string, when: string}>
     */
    public static function fields(MandateType $type): array
    {
        return self::describe(self::MANDATES[$type->value]);
    }

    /**
     * @return list<array{name: string, type: string, required: bool, disclosable: string, when: string}>
     */
    public static function constraintFields(ConstraintType $type): array
    {
        return self::describe(self::CONSTRAINTS[$type->value]);
    }

    /**
     * Shared types: Merchant, Amount, PaymentInstrument, PISP, LineItemRequirement, Item.
     *
     * @return array<string, list<array{name: string, type: string, required: bool, disclosable: string, when: string}>>
     */
    public static function types(): array
    {
        return array_map(self::describe(...), self::TYPES);
    }

    /**
     * @return array<string, list<array{name: string, type: string, required: bool, disclosable: string, when: string}>>
     */
    public static function receipts(): array
    {
        return array_map(self::describe(...), self::RECEIPTS);
    }

    /**
     * Why a mandate's content does not match its schema; empty when it does.
     *
     * @param array<string, mixed> $content
     * @return list<string>
     */
    public static function violations(MandateType $type, array $content): array
    {
        if (($content['vct'] ?? null) !== $type->value) {
            return [sprintf('vct is "%s", expected "%s".', is_string($content['vct'] ?? null) ? $content['vct'] : '', $type->value)];
        }
        $violations = self::objectViolations(self::MANDATES[$type->value], $content, '');
        if ($type->isOpen()) {
            $required = $type === MandateType::OpenCheckout ? ConstraintType::LineItems : ConstraintType::Reference;
            $present = array_any(Json::objects($content['constraints'] ?? null), static fn(array $constraint): bool => ($constraint['type'] ?? null) === $required->value);
            if (!$present) {
                $violations[] = sprintf('The open mandate must contain a %s constraint.', $required->value);
            }
            foreach (Json::objects($content['constraints'] ?? null) as $index => $constraint) {
                $known = ConstraintType::tryFrom(is_string($constraint['type'] ?? null) ? $constraint['type'] : '');
                if ($known !== null) {
                    $violations = [...$violations, ...self::objectViolations(self::CONSTRAINTS[$known->value], $constraint, 'constraints[' . $index . '].')];
                }
            }
        }
        return $violations;
    }

    /**
     * Why a receipt's claims do not match its schema; empty when they do.
     *
     * @param array<string, mixed> $claims
     * @return list<string>
     */
    public static function receiptViolations(string $receipt, array $claims): array
    {
        $status = $claims['status'] ?? null;
        $fields = array_values(array_filter(
            self::RECEIPTS[$receipt] ?? [],
            static fn(array $field): bool => ($field[4] ?? '') === '' || $field[4] === $status,
        ));
        return self::objectViolations($fields, $claims, '');
    }

    /**
     * @param list<array{0: string, 1: string, 2: bool, 3?: string, 4?: string}> $fields
     * @param array<string, mixed> $object
     * @return list<string>
     */
    private static function objectViolations(array $fields, array $object, string $path): array
    {
        $violations = [];
        foreach ($fields as $field) {
            [$name, $type, $required] = $field;
            if (!array_key_exists($name, $object)) {
                if ($required) {
                    $violations[] = sprintf('%s%s is missing.', $path, $name);
                }
                continue;
            }
            $violations = [...$violations, ...self::valueViolations($type, $object[$name], $path . $name)];
        }
        return $violations;
    }

    /**
     * @return list<string>
     */
    private static function valueViolations(string $type, mixed $value, string $path): array
    {
        if (str_ends_with($type, '[]')) {
            if (!is_array($value) || !array_is_list($value)) {
                return [sprintf('%s must be an array.', $path)];
            }
            $violations = [];
            foreach ($value as $index => $item) {
                $violations = [...$violations, ...self::valueViolations(substr($type, 0, -2), $item, $path . '[' . $index . ']')];
            }
            if ($type === 'LineItemRequirement[]' && $value === []) {
                $violations[] = sprintf('%s needs at least one item.', $path);
            }
            return $violations;
        }
        if (isset(self::TYPES[$type])) {
            return Json::isObject($value)
                ? self::objectViolations(self::TYPES[$type], Json::map($value), $path . '.')
                : [sprintf('%s must be an object.', $path)];
        }
        $valid = match ($type) {
            'string' => is_string($value),
            'integer' => is_int($value),
            'number' => is_int($value) || is_float($value),
            'object' => Json::isObject($value),
            'Constraint' => Json::isObject($value) && is_string(Json::map($value)['type'] ?? null),
            'Frequency' => in_array($value, self::FREQUENCIES, true),
            'Status' => $value === 'Success' || $value === 'Error',
            default => false,
        };
        if ($type === 'integer' && is_int($value) && str_ends_with($path, 'quantity') && $value < 1) {
            return [sprintf('%s must be greater than 0.', $path)];
        }
        return $valid ? [] : [sprintf('%s must be %s.', $path, match ($type) {
            'string' => 'a string',
            'integer' => 'an integer',
            'number' => 'a number',
            'object' => 'an object',
            'Constraint' => 'a constraint object with a type',
            'Frequency' => 'one of ' . implode(', ', self::FREQUENCIES),
            'Status' => '"Success" or "Error"',
            default => $type,
        })];
    }

    /**
     * @param list<array{0: string, 1: string, 2: bool, 3?: string, 4?: string}> $fields
     * @return list<array{name: string, type: string, required: bool, disclosable: string, when: string}>
     */
    private static function describe(array $fields): array
    {
        return array_map(static fn(array $field): array => [
            'name' => $field[0],
            'type' => $field[1],
            'required' => $field[2],
            'disclosable' => $field[3] ?? '',
            'when' => $field[4] ?? '',
        ], $fields);
    }
}
