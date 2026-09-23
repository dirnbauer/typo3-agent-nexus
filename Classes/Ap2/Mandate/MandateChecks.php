<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Ap2\Mandate;

use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Webconsulting\AgentNexus\Ap2\Crypto\Jcs;
use Webconsulting\AgentNexus\Ap2\Crypto\Json;

/**
 * The content checks both mandate verifiers share: the right types in the
 * right order, complete content, and values the open mandate pre-set.
 *
 * @internal
 */
#[Exclude]
final class MandateChecks
{
    /**
     * @param list<array<string, mixed>> $mandates open first
     */
    public static function types(array $mandates, MandateType $closed): Check
    {
        $found = array_map(static fn(array $mandate): string => Json::string($mandate['vct'] ?? null, '(no vct)'), $mandates);
        $expected = count($mandates) === 1 ? [$closed->value] : [$closed->open()->value, $closed->value];
        if (count($mandates) > 2) {
            return Check::fail(CheckType::MandateType, 'AP2 v0.2 closes one open mandate; this chain has ' . count($mandates) . ' mandates.');
        }
        return $found === $expected
            ? Check::pass(CheckType::MandateType, implode(' → ', $found))
            : Check::fail(CheckType::MandateType, sprintf('Expected %s, found %s.', implode(' → ', $expected), implode(' → ', $found)));
    }

    /**
     * @param array<string, mixed> $closed
     * @param array<string, mixed>|null $open
     */
    public static function content(MandateType $type, array $closed, ?array $open): Check
    {
        $violations = MandateSchema::violations($type, $closed);
        if ($open !== null) {
            $violations = [...$violations, ...MandateSchema::violations($type->open(), $open)];
        }
        return $violations === []
            ? Check::pass(CheckType::Content, 'Every required claim is present')
            : Check::fail(CheckType::Content, implode(' ', array_slice($violations, 0, 3)));
    }

    /**
     * An open mandate may already fix claims of the closed one; the closed
     * mandate must then carry exactly the same value (payee: same merchant).
     *
     * @param array<string, mixed> $open
     * @param array<string, mixed> $closed
     * @param list<string> $fields
     */
    public static function presets(array $open, array $closed, array $fields): Check
    {
        $checked = [];
        foreach ($fields as $field) {
            if (!array_key_exists($field, $open)) {
                continue;
            }
            $same = $field === 'payee'
                ? ConstraintEvaluator::merchantMatches(Json::map($open['payee']), Json::map($closed['payee'] ?? null))
                : array_key_exists($field, $closed) && self::equal($open[$field], $closed[$field]);
            if (!$same) {
                return Check::fail(CheckType::PresetValues, sprintf('The open mandate fixed %s, and the closed mandate changes it.', $field));
            }
            $checked[] = $field;
        }
        return Check::pass(CheckType::PresetValues, $checked === [] ? 'Nothing was fixed in advance' : implode(', ', $checked) . ' unchanged');
    }

    private static function equal(mixed $a, mixed $b): bool
    {
        try {
            return Jcs::canonicalize($a) === Jcs::canonicalize($b);
        } catch (\Throwable) {
            return false;
        }
    }
}
