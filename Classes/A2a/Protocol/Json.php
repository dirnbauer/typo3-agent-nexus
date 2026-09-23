<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\A2a\Protocol;

/**
 * Reads decoded request JSON field by field and says precisely what is wrong
 * when a field has the wrong type. Every failure is an InvalidParams error
 * naming the field, which is what A2A asks for (specification section 3.3.2).
 *
 * Query parameters of the HTTP+JSON binding arrive as strings, so the numeric
 * and boolean readers accept their string forms too (section 11.5).
 */
final class Json
{
    /**
     * A JSON object. `{}` decodes to an empty PHP array and is accepted.
     *
     * @return array<string, mixed>
     */
    public static function object(mixed $value, string $path): array
    {
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw A2aException::invalidParams($path, 'must be an object.');
        }
        $object = [];
        foreach ($value as $key => $item) {
            $object[(string)$key] = $item;
        }
        return $object;
    }

    /**
     * @param array<string, mixed> $object
     */
    public static function string(array $object, string $key, string $path): string
    {
        $value = $object[$key] ?? null;
        if ($value === null) {
            return '';
        }
        if (!is_string($value)) {
            throw A2aException::invalidParams(self::join($path, $key), 'must be a string.');
        }
        return $value;
    }

    /**
     * @param array<string, mixed> $object
     */
    public static function requiredString(array $object, string $key, string $path): string
    {
        $value = self::string($object, $key, $path);
        if (trim($value) === '') {
            throw A2aException::invalidParams(self::join($path, $key), 'is required.');
        }
        return $value;
    }

    /**
     * @param array<string, mixed> $object
     */
    public static function int(array $object, string $key, string $path): ?int
    {
        $value = $object[$key] ?? null;
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^-?\d{1,9}$/', $value) === 1) {
            return (int)$value;
        }
        throw A2aException::invalidParams(self::join($path, $key), 'must be an integer.');
    }

    /**
     * @param array<string, mixed> $object
     */
    public static function bool(array $object, string $key, string $path): ?bool
    {
        $value = $object[$key] ?? null;
        return match (true) {
            $value === null || $value === '' => null,
            is_bool($value) => $value,
            $value === 'true' => true,
            $value === 'false' => false,
            default => throw A2aException::invalidParams(self::join($path, $key), 'must be true or false.'),
        };
    }

    /**
     * A list of strings; a query parameter may also give it comma-separated.
     *
     * @param array<string, mixed> $object
     * @return list<string>
     */
    public static function stringList(array $object, string $key, string $path): array
    {
        $value = $object[$key] ?? null;
        if ($value === null) {
            return [];
        }
        if (is_string($value)) {
            return array_values(array_filter(array_map(trim(...), explode(',', $value)), static fn(string $item): bool => $item !== ''));
        }
        if (!is_array($value) || !array_is_list($value)) {
            throw A2aException::invalidParams(self::join($path, $key), 'must be a list of strings.');
        }
        $list = [];
        foreach ($value as $index => $item) {
            if (!is_string($item)) {
                throw A2aException::invalidParams(self::join($path, $key) . '[' . $index . ']', 'must be a string.');
            }
            $list[] = $item;
        }
        return $list;
    }

    /**
     * A google.protobuf.Struct such as `metadata`: an object, or absent.
     *
     * @param array<string, mixed> $object
     * @return array<string, mixed>
     */
    public static function struct(array $object, string $key, string $path): array
    {
        $value = $object[$key] ?? null;
        return $value === null ? [] : self::object($value, self::join($path, $key));
    }

    public static function join(string $path, string $key): string
    {
        return $path === '' ? $key : $path . '.' . $key;
    }

    /**
     * Encode for the wire: no escaped slashes or Unicode, invalid UTF-8
     * replaced rather than failing the whole response.
     *
     * @param array<array-key, mixed> $data
     */
    public static function encode(array $data, bool $pretty = false): string
    {
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION;
        if ($pretty) {
            $flags |= JSON_PRETTY_PRINT;
        }
        return (string)json_encode($data, $flags);
    }
}
