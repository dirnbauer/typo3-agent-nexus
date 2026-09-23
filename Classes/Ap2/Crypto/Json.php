<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Ap2\Crypto;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * JSON as the tokens carry it: compact, slashes and Unicode unescaped, and
 * decoding that insists on the shape it was asked for.
 *
 * The narrowing helpers turn decoded `mixed` into the array shapes the AP2
 * code works with, so a malformed claim becomes an empty value the checks then
 * reject instead of a type error halfway through a verification.
 */
#[Exclude]
final class Json
{
    public const int ENCODE_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;

    /** Tokens and bodies above this size are refused before they are parsed. */
    public const int MAX_BYTES = 262144;

    public static function encode(mixed $value): string
    {
        try {
            return json_encode($value, self::ENCODE_FLAGS);
        } catch (\JsonException $e) {
            throw new CryptoException('The value cannot be encoded as JSON: ' . $e->getMessage(), 1758700111, $e);
        }
    }

    /**
     * @return array<string, mixed>
     * @throws CryptoException when the text is not a JSON object
     */
    public static function decodeObject(string $json): array
    {
        $decoded = self::decode($json);
        if (!is_array($decoded) || ltrim($json)[0] !== '{') {
            throw new CryptoException('The JSON value is not an object.', 1758700112);
        }
        return self::map($decoded);
    }

    /**
     * @return list<mixed>
     * @throws CryptoException when the text is not a JSON array
     */
    public static function decodeArray(string $json): array
    {
        $decoded = self::decode($json);
        if (!is_array($decoded) || !array_is_list($decoded) || ltrim($json)[0] !== '[') {
            throw new CryptoException('The JSON value is not an array.', 1758700113);
        }
        return $decoded;
    }

    /**
     * A JSON object as a string-keyed array; anything else becomes [].
     *
     * @return array<string, mixed>
     */
    public static function map(mixed $value): array
    {
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            return [];
        }
        $map = [];
        foreach ($value as $key => $item) {
            $map[(string)$key] = $item;
        }
        return $map;
    }

    /**
     * A JSON array as a list; anything else becomes [].
     *
     * @return list<mixed>
     */
    public static function list(mixed $value): array
    {
        return is_array($value) && array_is_list($value) ? $value : [];
    }

    /**
     * The objects of a JSON array, skipping every other element.
     *
     * @return list<array<string, mixed>>
     */
    public static function objects(mixed $value): array
    {
        $objects = [];
        foreach (self::list($value) as $item) {
            if (is_array($item) && ($item === [] || !array_is_list($item))) {
                $objects[] = self::map($item);
            }
        }
        return $objects;
    }

    public static function string(mixed $value, string $default = ''): string
    {
        return is_string($value) ? $value : $default;
    }

    public static function int(mixed $value): ?int
    {
        return is_int($value) ? $value : null;
    }

    public static function isObject(mixed $value): bool
    {
        return is_array($value) && ($value === [] || !array_is_list($value));
    }

    private static function decode(string $json): mixed
    {
        if (strlen($json) > self::MAX_BYTES) {
            throw new CryptoException('The JSON value is too large.', 1758700114);
        }
        try {
            return json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new CryptoException('The value is not valid JSON: ' . $e->getMessage(), 1758700115, $e);
        }
    }
}
