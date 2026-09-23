<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Ap2\Crypto;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * Verifies SD-JWTs and applies their disclosures (RFC 9901 section 7.1).
 *
 * The result is the payload a verifier reasons about: every presented
 * disclosure put back in place, undisclosed digests dropped, `_sd` and
 * `_sd_alg` gone. The rules that stop a holder from smuggling claims in are
 * all enforced — every disclosure must be referenced exactly once, a digest
 * must not appear twice, a disclosed name must not overwrite a signed claim or
 * be `_sd`/`...`, and object-property and array-element disclosures cannot be
 * swapped.
 */
#[Exclude]
final class SdJwtProcessor
{
    private const int MAX_DEPTH = 32;

    /**
     * Check the issuer signature, then apply the disclosures.
     *
     * @return array<string, mixed>
     * @throws CryptoException when the signature or the disclosures are invalid
     */
    public static function verify(SdJwt $token, EcKey $issuerKey): array
    {
        if (!$token->jws->verifiesWith($issuerKey)) {
            throw new CryptoException('The signature does not verify.', 1758700231);
        }
        return self::process($token);
    }

    /**
     * Apply the disclosures without looking at the signature. Only for
     * displaying a token, or after the signature was checked separately.
     *
     * @return array<string, mixed>
     * @throws CryptoException when the disclosures are invalid
     */
    public static function process(SdJwt $token): array
    {
        $algorithm = $token->algorithm();
        if (!Digest::supports($algorithm)) {
            throw new CryptoException(sprintf('The SD-JWT uses the unsupported hash algorithm "%s".', $algorithm), 1758700232);
        }
        $byDigest = [];
        foreach ($token->disclosures as $disclosure) {
            if (isset($byDigest[$disclosure->digest])) {
                throw new CryptoException('The same disclosure is presented twice.', 1758700233);
            }
            $byDigest[$disclosure->digest] = $disclosure;
        }

        $state = new ProcessingState($byDigest);
        $payload = self::object($token->payload(), $state, 0);
        if (count($state->used) !== count($byDigest)) {
            throw new CryptoException('A disclosure is not referenced by the token it came with.', 1758700234);
        }
        unset($payload['_sd_alg']);
        return $payload;
    }

    /**
     * @param array<string, mixed> $object
     * @return array<string, mixed>
     */
    private static function object(array $object, ProcessingState $state, int $depth): array
    {
        self::guard($depth);
        $result = [];
        foreach ($object as $name => $value) {
            if ($name !== '_sd') {
                $result[$name] = self::value($value, $state, $depth + 1);
            }
        }
        if (!array_key_exists('_sd', $object)) {
            return $result;
        }
        $digests = $object['_sd'];
        if (!is_array($digests) || !array_is_list($digests)) {
            throw new CryptoException('"_sd" must be an array of digests.', 1758700235);
        }
        foreach ($digests as $digest) {
            if (!is_string($digest)) {
                throw new CryptoException('"_sd" must be an array of digests.', 1758700236);
            }
            $disclosure = $state->take($digest);
            if ($disclosure === null) {
                continue;
            }
            if ($disclosure->name === null) {
                throw new CryptoException('An array-element disclosure is referenced from "_sd".', 1758700237);
            }
            if ($disclosure->name === '_sd' || $disclosure->name === '...') {
                throw new CryptoException('A disclosure may not name "_sd" or "...".', 1758700238);
            }
            if (array_key_exists($disclosure->name, $result)) {
                throw new CryptoException(sprintf('The disclosed claim "%s" already exists.', $disclosure->name), 1758700239);
            }
            $result[$disclosure->name] = self::value($disclosure->value, $state, $depth + 1);
        }
        return $result;
    }

    /**
     * @param list<mixed> $list
     * @return list<mixed>
     */
    private static function list(array $list, ProcessingState $state, int $depth): array
    {
        self::guard($depth);
        $result = [];
        foreach ($list as $element) {
            if (is_array($element) && array_keys($element) === ['...']) {
                $digest = $element['...'];
                if (!is_string($digest)) {
                    throw new CryptoException('An array digest must be a string.', 1758700240);
                }
                $disclosure = $state->take($digest);
                if ($disclosure === null) {
                    continue;
                }
                if ($disclosure->name !== null) {
                    throw new CryptoException('An object-property disclosure is referenced as an array element.', 1758700241);
                }
                $result[] = self::value($disclosure->value, $state, $depth + 1);
                continue;
            }
            $result[] = self::value($element, $state, $depth + 1);
        }
        return $result;
    }

    private static function value(mixed $value, ProcessingState $state, int $depth): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        return array_is_list($value)
            ? self::list($value, $state, $depth)
            : self::object(Json::map($value), $state, $depth);
    }

    private static function guard(int $depth): void
    {
        if ($depth > self::MAX_DEPTH) {
            throw new CryptoException('The SD-JWT payload is nested too deeply.', 1758700242);
        }
    }
}
