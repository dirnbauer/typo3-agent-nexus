<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Agui\Protocol;

/**
 * JSON facts for PHP values, as `json_encode()` will write them.
 *
 * A PHP array is a JSON array when its keys are 0…n-1 — the empty array
 * included — and a JSON object otherwise; a `\stdClass` is always an object.
 * AG-UI 1.0 closes its objects and separates `{}` from `[]`, so everything that
 * builds or checks events asks these questions rather than `is_array()`.
 */
final class Json
{
    public static function isObject(mixed $value): bool
    {
        return $value instanceof \stdClass || (is_array($value) && !array_is_list($value));
    }

    public static function isList(mixed $value): bool
    {
        return is_array($value) && array_is_list($value);
    }

    /**
     * The members of a JSON object, keyed by name.
     *
     * @return array<string, mixed>
     */
    public static function members(mixed $object): array
    {
        $members = [];
        $source = $object instanceof \stdClass ? get_object_vars($object) : (is_array($object) ? $object : []);
        foreach ($source as $name => $value) {
            $members[(string)$name] = $value;
        }
        return $members;
    }

    /**
     * A decoded JSON value (objects as `\stdClass`) as plain PHP data: objects
     * become associative arrays, except those that would not survive the trip
     * back — the empty object and objects keyed "0", "1", … stay `\stdClass`,
     * so `{}` is written as `{}` again and never as `[]`.
     */
    public static function toPhp(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $members = array_map(self::toPhp(...), self::members($value));
            return array_is_list($members) ? (object)$members : $members;
        }
        if (is_array($value)) {
            return array_map(self::toPhp(...), $value);
        }
        return $value;
    }

    /**
     * An object for an event field that must be one, even when empty.
     *
     * @param array<string, mixed> $members
     * @return \stdClass|array<string, mixed>
     */
    public static function object(array $members): \stdClass|array
    {
        return $members === [] || array_is_list($members) ? (object)$members : $members;
    }

    /** RFC 6901: the pointer to a member of the value at `$base`. */
    public static function pointer(string $base, string|int $segment): string
    {
        return $base . '/' . str_replace(['~', '/'], ['~0', '~1'], (string)$segment);
    }

    public static function encode(mixed $value): string
    {
        return (string)json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }
}
