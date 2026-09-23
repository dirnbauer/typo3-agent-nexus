<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Ap2\Crypto;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * Base64url without padding (RFC 4648 section 5), as JOSE and SD-JWT use it.
 *
 * Decoding is strict: only the URL-safe alphabet, no padding, and only the
 * canonical encoding of the bytes. Hashes in SD-JWT are taken over the encoded
 * strings, so two spellings of the same bytes must never both be accepted.
 */
#[Exclude]
final class Base64Url
{
    public static function encode(string $binary): string
    {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }

    /**
     * @throws CryptoException when the input is not canonical base64url
     */
    public static function decode(string $encoded): string
    {
        if ($encoded === '') {
            return '';
        }
        if (preg_match('/^[A-Za-z0-9_-]+$/', $encoded) !== 1 || strlen($encoded) % 4 === 1) {
            throw new CryptoException('The value is not base64url.', 1758700101);
        }
        $padded = strtr($encoded, '-_', '+/') . str_repeat('=', (4 - strlen($encoded) % 4) % 4);
        $decoded = base64_decode($padded, true);
        if ($decoded === false || self::encode($decoded) !== $encoded) {
            throw new CryptoException('The value is not canonical base64url.', 1758700102);
        }
        return $decoded;
    }
}
