<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Ap2\Crypto;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * Converts ECDSA signatures between the two encodings that meet here.
 *
 * OpenSSL signs and verifies DER: `SEQUENCE { INTEGER r, INTEGER s }`, each
 * integer minimal and sign-padded. JWS (RFC 7518 section 3.4) carries the raw
 * concatenation `R || S`, each left-padded to the curve size — 64 bytes for
 * ES256. Mixing them up is the classic reason a PHP ES256 token fails to verify
 * everywhere else.
 */
#[Exclude]
final class EcSignature
{
    /** Bytes per component for P-256. */
    public const int COMPONENT_BYTES = 32;

    public static function derToRaw(string $der): string
    {
        $length = strlen($der);
        if ($length < 8 || ord($der[0]) !== 0x30) {
            throw new CryptoException('The signature is not a DER sequence.', 1758700141);
        }
        [$sequenceLength, $offset] = self::readLength($der, 1);
        if ($offset + $sequenceLength !== $length) {
            throw new CryptoException('The DER signature has a wrong length.', 1758700142);
        }
        [$r, $offset] = self::readInteger($der, $offset);
        [$s, $offset] = self::readInteger($der, $offset);
        if ($offset !== $length) {
            throw new CryptoException('The DER signature has trailing bytes.', 1758700143);
        }
        return str_pad($r, self::COMPONENT_BYTES, "\x00", STR_PAD_LEFT)
            . str_pad($s, self::COMPONENT_BYTES, "\x00", STR_PAD_LEFT);
    }

    public static function rawToDer(string $raw): string
    {
        if (strlen($raw) !== 2 * self::COMPONENT_BYTES) {
            throw new CryptoException('An ES256 signature is 64 bytes long.', 1758700144);
        }
        $body = self::integer(substr($raw, 0, self::COMPONENT_BYTES)) . self::integer(substr($raw, self::COMPONENT_BYTES));
        return "\x30" . self::length(strlen($body)) . $body;
    }

    private static function integer(string $bytes): string
    {
        $bytes = ltrim($bytes, "\x00");
        if ($bytes === '' || (ord($bytes[0]) & 0x80) !== 0) {
            $bytes = "\x00" . $bytes;
        }
        return "\x02" . self::length(strlen($bytes)) . $bytes;
    }

    private static function length(int $length): string
    {
        return $length < 0x80 ? chr($length) : "\x81" . chr($length);
    }

    /**
     * @return array{0: int, 1: int} the length and the offset after it
     */
    private static function readLength(string $der, int $offset): array
    {
        if (!isset($der[$offset])) {
            throw new CryptoException('The DER signature is truncated.', 1758700145);
        }
        $first = ord($der[$offset]);
        if ($first < 0x80) {
            return [$first, $offset + 1];
        }
        if ($first !== 0x81 || !isset($der[$offset + 1]) || ord($der[$offset + 1]) < 0x80) {
            throw new CryptoException('The DER signature uses a length form P-256 never needs.', 1758700146);
        }
        return [ord($der[$offset + 1]), $offset + 2];
    }

    /**
     * @return array{0: string, 1: int} the unsigned big-endian value and the offset after it
     */
    private static function readInteger(string $der, int $offset): array
    {
        if (!isset($der[$offset]) || ord($der[$offset]) !== 0x02) {
            throw new CryptoException('The DER signature does not hold two integers.', 1758700147);
        }
        [$length, $offset] = self::readLength($der, $offset + 1);
        if ($length === 0 || $offset + $length > strlen($der)) {
            throw new CryptoException('A DER integer is truncated.', 1758700148);
        }
        $value = substr($der, $offset, $length);
        if ((ord($value[0]) & 0x80) !== 0) {
            throw new CryptoException('A DER integer of an ECDSA signature is negative.', 1758700149);
        }
        $value = ltrim($value, "\x00");
        if (strlen($value) > self::COMPONENT_BYTES) {
            throw new CryptoException('A DER integer is too large for P-256.', 1758700150);
        }
        return [$value, $offset + $length];
    }
}
