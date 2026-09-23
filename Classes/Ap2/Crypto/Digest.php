<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Ap2\Crypto;

/**
 * The digests AP2 takes over tokens: disclosure digests, `sd_hash`,
 * `issuer_jwt_hash`, `checkout_hash` and receipt references.
 *
 * Each is the base64url-encoded hash of the ASCII bytes of a compact
 * serialisation, with the algorithm the SD-JWT names in `_sd_alg` (SHA-256
 * when it names none). The AP2 SDK accepts the three SHA-2 lengths; so does
 * this class.
 */
final class Digest
{
    public const string DEFAULT_ALGORITHM = 'sha-256';

    /** `_sd_alg` names (IANA "Named Information Hash Algorithm") and their PHP names. */
    private const array ALGORITHMS = [
        'sha-256' => 'sha256',
        'sha-384' => 'sha384',
        'sha-512' => 'sha512',
    ];

    public static function of(string $ascii, string $algorithm = self::DEFAULT_ALGORITHM): string
    {
        $php = self::ALGORITHMS[$algorithm] ?? null;
        if ($php === null) {
            throw new CryptoException(sprintf('The hash algorithm "%s" is not supported.', $algorithm), 1758700121);
        }
        if (preg_match('/[^\x21-\x7e]/', $ascii) === 1) {
            throw new CryptoException('Only compact serialisations (printable ASCII) are hashed.', 1758700122);
        }
        return Base64Url::encode(hash($php, $ascii, true));
    }

    public static function supports(string $algorithm): bool
    {
        return isset(self::ALGORITHMS[$algorithm]);
    }
}
