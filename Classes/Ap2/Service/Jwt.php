<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Ap2\Service;

use TYPO3\CMS\Core\SingletonInterface;

/**
 * A minimal, dependency-free JWS (signed JWT) helper — `HS256` only.
 *
 * AP2 mandates are verifiable credentials: signed tokens whose integrity anyone
 * holding the key can check. This class does the genuine mechanics (base64url,
 * an HMAC-SHA256 signature, constant-time verification) so the demo teaches the
 * real shape of a signed mandate.
 *
 * It is a SANDBOX: it signs with a fixed demo secret, NOT a real cryptographic
 * identity or PKI. It proves token integrity, not who the signer is. Never treat
 * a mandate minted here as a real payment authorization.
 */
final class Jwt implements SingletonInterface
{
    // Demo signing secret. A real AP2 deployment would use per-party keys / DIDs.
    private const DEMO_SECRET = 'ap2-sandbox-demo-key-not-a-real-identity';

    public function b64UrlEncode(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    public function b64UrlDecode(string $s): string
    {
        return (string)base64_decode(strtr($s, '-_', '+/'), true);
    }

    /**
     * Sign a claim set into a compact JWS string.
     *
     * @param array<string, mixed> $claims
     */
    public function sign(array $claims): string
    {
        $header = ['alg' => 'HS256', 'typ' => 'JWT'];
        $segments = [
            $this->b64UrlEncode((string)json_encode($header, JSON_UNESCAPED_SLASHES)),
            $this->b64UrlEncode((string)json_encode($claims, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
        ];
        $signingInput = implode('.', $segments);
        $sig = hash_hmac('sha256', $signingInput, self::DEMO_SECRET, true);
        $segments[] = $this->b64UrlEncode($sig);
        return implode('.', $segments);
    }

    /**
     * Verify a compact JWS and return its parts and validity.
     *
     * @return array{valid: bool, reason: string, header: array<string, mixed>, claims: array<string, mixed>}
     */
    public function verify(string $jwt): array
    {
        $out = ['valid' => false, 'reason' => '', 'header' => [], 'claims' => []];
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            $out['reason'] = 'Malformed token.';
            return $out;
        }
        [$h, $p, $s] = $parts;
        $header = json_decode($this->b64UrlDecode($h), true);
        $claims = json_decode($this->b64UrlDecode($p), true);
        $out['header'] = is_array($header) ? $header : [];
        $out['claims'] = is_array($claims) ? $claims : [];

        $expected = $this->b64UrlEncode(hash_hmac('sha256', $h . '.' . $p, self::DEMO_SECRET, true));
        if (!hash_equals($expected, $s)) {
            $out['reason'] = 'Signature does not verify.';
            return $out;
        }
        $now = time();
        if (isset($claims['exp']) && $now >= (int)$claims['exp']) {
            $out['reason'] = 'Token has expired.';
            return $out;
        }
        if (isset($claims['nbf']) && $now < (int)$claims['nbf']) {
            $out['reason'] = 'Token not yet valid.';
            return $out;
        }
        $out['valid'] = true;
        $out['reason'] = 'Signature valid.';
        return $out;
    }
}
