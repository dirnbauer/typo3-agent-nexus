<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Ap2\Mandate;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * Amounts as AP2 carries them: integers in the minor unit of the currency.
 * Formatting and parsing stay in integers, so no float ever decides whether
 * a payment is within a cap.
 */
#[Exclude]
final class Money
{
    /** €447.00 for euros, "USD 199.00" for anything else. */
    public static function format(int $minor, string $currency = 'EUR'): string
    {
        $sign = $minor < 0 ? '-' : '';
        $absolute = abs($minor);
        $amount = number_format(intdiv($absolute, 100), 0, '.', ',') . '.' . sprintf('%02d', $absolute % 100);
        return $currency === 'EUR' ? $sign . '€' . $amount : $sign . $currency . ' ' . $amount;
    }

    /**
     * Minor units from what a person types: "500", "500.5", "500,50".
     * Null for anything else, including more than two decimals.
     */
    public static function parse(string $input, int $max = 100000000): ?int
    {
        $input = trim($input);
        if (preg_match('/^(\d{1,9})(?:[.,](\d{1,2}))?$/', $input, $match) !== 1) {
            return null;
        }
        $minor = (int)$match[1] * 100 + (int)str_pad($match[2] ?? '0', 2, '0');
        return $minor > $max ? null : $minor;
    }
}
