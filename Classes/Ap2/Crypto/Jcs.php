<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Ap2\Crypto;

/**
 * The JSON Canonicalization Scheme, RFC 8785.
 *
 * UCP signs a checkout as its JCS form (`ap2.merchant_authorization`), so the
 * same logical JSON must give the same bytes in every implementation. That
 * means ECMAScript's rules exactly: object members sorted by the UTF-16 code
 * units of their names, strings escaped the way `JSON.stringify` escapes them,
 * and numbers written the way `Number.prototype.toString` writes an IEEE 754
 * double.
 *
 * PHP arrays map to JSON the usual way: a list is an array, anything else an
 * object. An empty PHP array is `[]`; pass a `\stdClass` for `{}`.
 */
final class Jcs
{
    private const int MAX_DEPTH = 64;

    /**
     * @throws CryptoException for values JSON cannot represent (NaN, INF,
     *                         invalid UTF-8, resources)
     */
    public static function canonicalize(mixed $value): string
    {
        return self::value($value, 0);
    }

    /**
     * An IEEE 754 double the way ECMAScript's Number::toString writes it.
     */
    public static function number(float $number): string
    {
        if (is_nan($number) || is_infinite($number)) {
            throw new CryptoException('NaN and Infinity have no JSON representation.', 1758700131);
        }
        if ($number == 0.0) {
            return '0';
        }
        if ($number < 0) {
            return '-' . self::number(-$number);
        }

        [$digits, $exponent] = self::shortestDigits($number);
        $k = strlen($digits);
        $n = $exponent;

        if ($k <= $n && $n <= 21) {
            return $digits . str_repeat('0', $n - $k);
        }
        if ($n > 0 && $n <= 21) {
            return substr($digits, 0, $n) . '.' . substr($digits, $n);
        }
        if ($n > -6 && $n <= 0) {
            return '0.' . str_repeat('0', -$n) . $digits;
        }
        $sign = $n - 1 < 0 ? '-' : '+';
        $mantissa = $k === 1 ? $digits : $digits[0] . '.' . substr($digits, 1);
        return $mantissa . 'e' . $sign . abs($n - 1);
    }

    private static function value(mixed $value, int $depth): string
    {
        if ($depth > self::MAX_DEPTH) {
            throw new CryptoException('The value is nested too deeply.', 1758700132);
        }
        return match (true) {
            $value === null => 'null',
            $value === true => 'true',
            $value === false => 'false',
            is_int($value) => abs($value) <= 9007199254740992 ? (string)$value : self::number((float)$value),
            is_float($value) => self::number($value),
            is_string($value) => self::string($value),
            is_array($value) => array_is_list($value) ? self::array($value, $depth) : self::object($value, $depth),
            $value instanceof \JsonSerializable => self::value($value->jsonSerialize(), $depth + 1),
            $value instanceof \stdClass => self::object(get_object_vars($value), $depth),
            default => throw new CryptoException('The value has no JSON representation.', 1758700133),
        };
    }

    /**
     * @param list<mixed> $list
     */
    private static function array(array $list, int $depth): string
    {
        $items = [];
        foreach ($list as $item) {
            $items[] = self::value($item, $depth + 1);
        }
        return '[' . implode(',', $items) . ']';
    }

    /**
     * @param array<array-key, mixed> $object
     */
    private static function object(array $object, int $depth): string
    {
        $members = [];
        foreach ($object as $name => $item) {
            $name = (string)$name;
            $members[] = [self::utf16($name), self::string($name) . ':' . self::value($item, $depth + 1)];
        }
        // Code-unit order: big-endian UTF-16 compares bytewise in exactly that order.
        usort($members, static fn(array $a, array $b): int => strcmp($a[0], $b[0]));
        return '{' . implode(',', array_column($members, 1)) . '}';
    }

    private static function string(string $value): string
    {
        if (!mb_check_encoding($value, 'UTF-8')) {
            throw new CryptoException('Strings must be valid UTF-8.', 1758700134);
        }
        $escaped = preg_replace_callback(
            '/[\x00-\x1f"\\\\]/',
            static fn(array $match): string => match ($match[0]) {
                '"' => '\\"',
                '\\' => '\\\\',
                "\x08" => '\\b',
                "\t" => '\\t',
                "\n" => '\\n',
                "\x0c" => '\\f',
                "\r" => '\\r',
                default => sprintf('\\u%04x', ord($match[0])),
            },
            $value,
        );
        return '"' . ($escaped ?? '') . '"';
    }

    private static function utf16(string $value): string
    {
        $converted = mb_convert_encoding($value, 'UTF-16BE', 'UTF-8');
        return is_string($converted) ? $converted : $value;
    }

    /**
     * The shortest digit string that round-trips to the double, and the
     * position of the decimal point relative to it (the value is
     * 0.d1d2…dk × 10^n). PHP's own shortest representation (serialize_precision
     * -1, David Gay's mode 0) supplies the digits; this method only reads them.
     *
     * @return array{0: string, 1: int}
     */
    private static function shortestDigits(float $number): array
    {
        $previous = ini_get('serialize_precision');
        ini_set('serialize_precision', '-1');
        try {
            $repr = var_export($number, true);
        } finally {
            ini_set('serialize_precision', $previous === false ? '-1' : $previous);
        }
        if (preg_match('/^(\d+)(?:\.(\d+))?(?:[eE]([+-]?\d+))?$/', $repr, $match) !== 1) {
            throw new CryptoException('Unexpected number representation "' . $repr . '".', 1758700135);
        }
        $integer = $match[1];
        $fraction = $match[2] ?? '';
        $exponent = (int)($match[3] ?? 0);

        $digits = $integer . $fraction;
        $point = strlen($integer) + $exponent;
        $trimmed = ltrim($digits, '0');
        $point -= strlen($digits) - strlen($trimmed);
        $trimmed = rtrim($trimmed, '0');

        return [$trimmed === '' ? '0' : $trimmed, $point];
    }
}
