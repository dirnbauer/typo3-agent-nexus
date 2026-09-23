<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Ucp\Checkout;

/**
 * Amounts as people read them.
 *
 * UCP carries every amount as an integer in the currency's minor unit; this is
 * the one place that turns such an integer into text ("€49.00"). Whatever a
 * language model is told about prices comes from here, already formatted, so
 * the model never has a number to do arithmetic with.
 *
 * Only currencies with two decimal places are sold here.
 */
final class Money
{
    private const array SYMBOLS = ['EUR' => '€', 'USD' => '$', 'GBP' => '£'];

    public static function format(int $minorUnits, string $currency = 'EUR'): string
    {
        $absolute = abs($minorUnits);
        $amount = number_format(intdiv($absolute, 100), 0, '.', ',') . '.' . sprintf('%02d', $absolute % 100);
        $sign = $minorUnits < 0 ? '-' : '';
        $symbol = self::SYMBOLS[strtoupper($currency)] ?? null;

        return $symbol !== null
            ? $sign . $symbol . $amount
            : $sign . $amount . ' ' . strtoupper($currency);
    }
}
