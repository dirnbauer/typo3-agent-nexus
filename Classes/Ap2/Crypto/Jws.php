<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Ap2\Crypto;

/**
 * ES256 JSON Web Signatures, compact (RFC 7515 section 7.1) and with detached
 * content (appendix F: `<header>..<signature>`, the form UCP's
 * `ap2.merchant_authorization` uses).
 *
 * ES256 is the only algorithm: AP2 v0.2 keys are P-256 and its examples all
 * sign with ES256. `none`, the HMAC family and everything else are refused
 * before any key is touched, and so are header parameters this implementation
 * does not understand (a `crit`, a `jku` pointing somewhere else).
 */
final class Jws
{
    public const string ALGORITHM = 'ES256';

    /** The protected header parameters a token may carry. */
    public const array HEADER_PARAMETERS = ['alg', 'typ', 'kid'];

    /**
     * @param array<string, mixed> $header without `alg`, which is always ES256
     * @param array<string, mixed>|string $payload a claim set, or the exact bytes to sign
     */
    public static function sign(array $header, array|string $payload, EcKey $key): string
    {
        $encodedHeader = Base64Url::encode(Json::encode(self::header($header)));
        $encodedPayload = Base64Url::encode(is_string($payload) ? $payload : Json::encode($payload));
        $signature = $key->sign($encodedHeader . '.' . $encodedPayload);
        return $encodedHeader . '.' . $encodedPayload . '.' . Base64Url::encode($signature);
    }

    /**
     * A JWS whose payload travels separately: `<header>..<signature>`.
     *
     * @param array<string, mixed> $header
     */
    public static function signDetached(array $header, string $payload, EcKey $key): string
    {
        $encodedHeader = Base64Url::encode(Json::encode(self::header($header)));
        $signature = $key->sign($encodedHeader . '.' . Base64Url::encode($payload));
        return $encodedHeader . '..' . Base64Url::encode($signature);
    }

    /**
     * @throws CryptoException for anything that is not an ES256 compact JWS
     */
    public static function decode(string $compact): DecodedJws
    {
        if (strlen($compact) > Json::MAX_BYTES) {
            throw new CryptoException('The token is too large.', 1758700181);
        }
        $segments = explode('.', $compact);
        if (count($segments) !== 3 || $segments[0] === '' || $segments[1] === '' || $segments[2] === '') {
            throw new CryptoException('A compact JWS has three non-empty parts separated by dots.', 1758700182);
        }
        $header = Json::decodeObject(Base64Url::decode($segments[0]));
        self::assertHeader($header);
        // Validate the payload encoding now, so a decoded token is always readable.
        Base64Url::decode($segments[1]);
        $signature = Base64Url::decode($segments[2]);
        if (strlen($signature) !== 2 * EcSignature::COMPONENT_BYTES) {
            throw new CryptoException('An ES256 signature is 64 bytes long.', 1758700183);
        }
        return new DecodedJws($compact, $header, $segments[1], $signature);
    }

    /**
     * Verify a compact JWS and return its claims.
     *
     * @return array<string, mixed>
     * @throws CryptoException when the token is malformed or the signature does not verify
     */
    public static function verify(string $compact, EcKey $key): array
    {
        $jws = self::decode($compact);
        if (!$jws->verifiesWith($key)) {
            throw new CryptoException('The signature does not verify.', 1758700184);
        }
        return $jws->payload();
    }

    /**
     * Verify a detached JWS over the given payload bytes; returns its header.
     *
     * @return array<string, mixed>
     * @throws CryptoException when it is malformed or does not verify
     */
    public static function verifyDetached(string $detached, string $payload, EcKey $key): array
    {
        $segments = explode('.', $detached);
        if (count($segments) !== 3 || $segments[0] === '' || $segments[1] !== '' || $segments[2] === '') {
            throw new CryptoException('A detached JWS has the form <header>..<signature>.', 1758700185);
        }
        $header = Json::decodeObject(Base64Url::decode($segments[0]));
        self::assertHeader($header);
        $signature = Base64Url::decode($segments[2]);
        if (!$key->verify($segments[0] . '.' . Base64Url::encode($payload), $signature)) {
            throw new CryptoException('The signature does not verify.', 1758700186);
        }
        return $header;
    }

    /**
     * @param array<string, mixed> $header
     * @return array<string, mixed>
     */
    private static function header(array $header): array
    {
        if (isset($header['alg']) && $header['alg'] !== self::ALGORITHM) {
            throw new CryptoException('Only ES256 is signed here.', 1758700187);
        }
        unset($header['alg']);
        $header = ['alg' => self::ALGORITHM] + $header;
        self::assertHeader($header);
        return $header;
    }

    /**
     * @param array<string, mixed> $header
     */
    private static function assertHeader(array $header): void
    {
        $algorithm = $header['alg'] ?? null;
        if ($algorithm !== self::ALGORITHM) {
            throw new CryptoException(sprintf(
                'The algorithm "%s" is not accepted; AP2 mandates are signed with ES256.',
                is_string($algorithm) ? $algorithm : 'missing',
            ), 1758700188);
        }
        foreach ($header as $name => $value) {
            if (!in_array($name, self::HEADER_PARAMETERS, true)) {
                throw new CryptoException(sprintf('The header parameter "%s" is not understood.', $name), 1758700189);
            }
            if (!is_string($value)) {
                throw new CryptoException(sprintf('The header parameter "%s" is not a string.', $name), 1758700190);
            }
        }
    }
}
