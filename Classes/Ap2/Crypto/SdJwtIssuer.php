<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Ap2\Crypto;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * Issues SD-JWTs: every {@see Disclosable} in the claims becomes a digest in
 * the signed payload and a disclosure beside it (RFC 9901 section 4.2).
 *
 * Object properties go into the object's `_sd` array, sorted so the order does
 * not reveal the original one; array elements become `{"...": digest}`. Nested
 * disclosables are concealed first, so the disclosures come out inner before
 * outer — the order the AP2 SDK (python sd-jwt) produces.
 */
#[Exclude]
final class SdJwtIssuer
{
    private const int MAX_DEPTH = 32;

    /**
     * @param array<string, mixed> $claims
     * @param array<string, mixed> $header e.g. `['typ' => 'dc+sd-jwt', 'kid' => …]`
     */
    public static function issue(array $claims, array $header, EcKey $key, string $algorithm = Digest::DEFAULT_ALGORITHM): SdJwt
    {
        [$payload, $disclosures] = self::conceal($claims, $algorithm);
        $payload['_sd_alg'] = $algorithm;
        return SdJwt::fromParts(Jws::sign($header, $payload, $key), $disclosures);
    }

    /**
     * The payload with digests in place of every disclosable, and the
     * disclosures.
     *
     * @param array<string, mixed> $claims
     * @return array{0: array<string, mixed>, 1: list<Disclosure>}
     */
    public static function conceal(array $claims, string $algorithm = Digest::DEFAULT_ALGORITHM): array
    {
        $disclosures = [];
        $payload = self::object($claims, $algorithm, $disclosures, 0);
        return [$payload, $disclosures];
    }

    /**
     * @param array<array-key, mixed> $object
     * @param list<Disclosure> $disclosures
     * @return array<string, mixed>
     */
    private static function object(array $object, string $algorithm, array &$disclosures, int $depth): array
    {
        self::guard($depth);
        $result = [];
        $digests = [];
        foreach ($object as $name => $value) {
            $name = (string)$name;
            if ($name === '_sd' || $name === '...') {
                throw new CryptoException('"_sd" and "..." are reserved claim names.', 1758700221);
            }
            if ($value instanceof Disclosable) {
                $disclosure = Disclosure::create(self::value($value->value, $algorithm, $disclosures, $depth + 1), $name, $algorithm);
                $disclosures[] = $disclosure;
                $digests[] = $disclosure->digest;
                continue;
            }
            $result[$name] = self::value($value, $algorithm, $disclosures, $depth + 1);
        }
        if ($digests !== []) {
            sort($digests, SORT_STRING);
            $result['_sd'] = $digests;
        }
        return $result;
    }

    /**
     * @param list<mixed> $list
     * @param list<Disclosure> $disclosures
     * @return list<mixed>
     */
    private static function list(array $list, string $algorithm, array &$disclosures, int $depth): array
    {
        self::guard($depth);
        $result = [];
        foreach ($list as $item) {
            if ($item instanceof Disclosable) {
                $disclosure = Disclosure::create(self::value($item->value, $algorithm, $disclosures, $depth + 1), null, $algorithm);
                $disclosures[] = $disclosure;
                $result[] = ['...' => $disclosure->digest];
                continue;
            }
            $result[] = self::value($item, $algorithm, $disclosures, $depth + 1);
        }
        return $result;
    }

    /**
     * @param list<Disclosure> $disclosures
     */
    private static function value(mixed $value, string $algorithm, array &$disclosures, int $depth): mixed
    {
        if ($value instanceof Disclosable) {
            throw new CryptoException('A disclosable value must sit in an object or an array.', 1758700222);
        }
        if (!is_array($value)) {
            return $value;
        }
        return array_is_list($value)
            ? self::list($value, $algorithm, $disclosures, $depth)
            : self::object($value, $algorithm, $disclosures, $depth);
    }

    private static function guard(int $depth): void
    {
        if ($depth > self::MAX_DEPTH) {
            throw new CryptoException('The claims are nested too deeply.', 1758700223);
        }
    }
}
