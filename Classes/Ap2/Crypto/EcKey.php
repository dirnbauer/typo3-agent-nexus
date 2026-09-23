<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Ap2\Crypto;

/**
 * A P-256 key: always the public point, and the private key when this side
 * signs.
 *
 * AP2 v0.2 allows exactly one key type (EC, P-256) and one algorithm (ES256)
 * in its JWKs, so this class knows no other. Keys are imported from a PEM
 * (the sandbox key ring) or from a public JWK (a `cnf` claim, a JWK Set).
 */
final class EcKey
{
    /** DER prefix of a P-256 SubjectPublicKeyInfo, up to the uncompressed point. */
    private const string SPKI_PREFIX = '3059301306072a8648ce3d020106082a8648ce3d030107034200';

    /** The members a public P-256 JWK may carry (AP2 types/jwk.json is closed). */
    public const array JWK_MEMBERS = ['kty', 'crv', 'x', 'y', 'use', 'key_ops', 'alg', 'kid', 'x5u', 'x5c', 'x5t', 'x5t#S256'];

    private ?\OpenSSLAsymmetricKey $publicHandle = null;
    private ?\OpenSSLAsymmetricKey $privateHandle = null;

    private function __construct(
        public readonly string $kid,
        private readonly string $x,
        private readonly string $y,
        private readonly ?string $privatePem,
    ) {}

    /**
     * A fresh key; the kid is `<prefix>-<first 8 characters of its thumbprint>`.
     */
    public static function generate(string $kidPrefix): self
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        if ($key === false || !openssl_pkey_export($key, $pem) || !is_string($pem)) {
            throw new CryptoException('OpenSSL could not create a P-256 key: ' . (string)openssl_error_string(), 1758700161);
        }
        $unnamed = self::fromPrivatePem($pem);
        return $unnamed->withKid($kidPrefix . '-' . substr($unnamed->thumbprint(), 0, 8));
    }

    public static function fromPrivatePem(string $pem, string $kid = ''): self
    {
        $key = openssl_pkey_get_private($pem);
        if ($key === false) {
            throw new CryptoException('The private key cannot be read.', 1758700162);
        }
        [$x, $y] = self::point($key);
        $instance = new self($kid, $x, $y, $pem);
        $instance->privateHandle = $key;
        return $instance;
    }

    /**
     * A public key from a JWK. Private members (`d`) and anything the AP2 JWK
     * schema does not list are refused, as are keys marked for another use or
     * algorithm.
     *
     * @param array<array-key, mixed> $jwk
     */
    public static function fromJwk(array $jwk): self
    {
        foreach (array_keys($jwk) as $member) {
            if (!in_array((string)$member, self::JWK_MEMBERS, true)) {
                throw new CryptoException(sprintf('The JWK carries a member this key type does not allow: "%s".', $member), 1758700163);
            }
        }
        if (($jwk['kty'] ?? null) !== 'EC' || ($jwk['crv'] ?? null) !== 'P-256') {
            throw new CryptoException('Only EC keys on the P-256 curve are accepted.', 1758700164);
        }
        if (isset($jwk['alg']) && $jwk['alg'] !== Jws::ALGORITHM) {
            throw new CryptoException('The key is not meant for ES256.', 1758700165);
        }
        if (isset($jwk['use']) && $jwk['use'] !== 'sig') {
            throw new CryptoException('The key is not meant for signatures.', 1758700166);
        }
        $x = is_string($jwk['x'] ?? null) ? Base64Url::decode($jwk['x']) : '';
        $y = is_string($jwk['y'] ?? null) ? Base64Url::decode($jwk['y']) : '';
        if (strlen($x) !== 32 || strlen($y) !== 32) {
            throw new CryptoException('The JWK coordinates are not 32 bytes long.', 1758700167);
        }
        $key = new self(is_string($jwk['kid'] ?? null) ? $jwk['kid'] : '', $x, $y, null);
        $key->publicHandle();
        return $key;
    }

    public function withKid(string $kid): self
    {
        $copy = new self($kid, $this->x, $this->y, $this->privatePem);
        $copy->privateHandle = $this->privateHandle;
        $copy->publicHandle = $this->publicHandle;
        return $copy;
    }

    /**
     * The public key as the RFC 7638 minimum: what goes into `cnf.jwk`.
     *
     * @return array{kty: string, crv: string, x: string, y: string}
     */
    public function publicJwk(): array
    {
        return [
            'kty' => 'EC',
            'crv' => 'P-256',
            'x' => Base64Url::encode($this->x),
            'y' => Base64Url::encode($this->y),
        ];
    }

    /**
     * The public key for a JWK Set, with its id, use and algorithm.
     *
     * @return array{kty: string, crv: string, x: string, y: string, kid: string, use: string, alg: string}
     */
    public function jwk(): array
    {
        return $this->publicJwk() + ['kid' => $this->kid, 'use' => 'sig', 'alg' => Jws::ALGORITHM];
    }

    /** RFC 7638 JWK thumbprint (SHA-256, base64url). */
    public function thumbprint(): string
    {
        $jwk = $this->publicJwk();
        $canonical = '{"crv":"' . $jwk['crv'] . '","kty":"' . $jwk['kty'] . '","x":"' . $jwk['x'] . '","y":"' . $jwk['y'] . '"}';
        return Base64Url::encode(hash('sha256', $canonical, true));
    }

    public function hasPrivateKey(): bool
    {
        return $this->privatePem !== null;
    }

    public function privatePem(): string
    {
        if ($this->privatePem === null) {
            throw new CryptoException('This is a public key.', 1758700168);
        }
        return $this->privatePem;
    }

    public function sameKeyAs(self $other): bool
    {
        return hash_equals($this->x . $this->y, $other->x . $other->y);
    }

    /**
     * An ES256 signature as JWS carries it: 64 bytes, R || S.
     */
    public function sign(string $data): string
    {
        if ($this->privatePem === null) {
            throw new CryptoException('A public key cannot sign.', 1758700169);
        }
        $this->privateHandle ??= openssl_pkey_get_private($this->privatePem) ?: null;
        if ($this->privateHandle === null || !openssl_sign($data, $der, $this->privateHandle, OPENSSL_ALGO_SHA256) || !is_string($der)) {
            throw new CryptoException('OpenSSL could not sign: ' . (string)openssl_error_string(), 1758700170);
        }
        return EcSignature::derToRaw($der);
    }

    public function verify(string $data, string $signature): bool
    {
        try {
            $der = EcSignature::rawToDer($signature);
        } catch (CryptoException) {
            return false;
        }
        return openssl_verify($data, $der, $this->publicHandle(), OPENSSL_ALGO_SHA256) === 1;
    }

    private function publicHandle(): \OpenSSLAsymmetricKey
    {
        if ($this->publicHandle !== null) {
            return $this->publicHandle;
        }
        $der = (string)hex2bin(self::SPKI_PREFIX) . "\x04" . $this->x . $this->y;
        $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
        $handle = openssl_pkey_get_public($pem);
        if ($handle === false) {
            throw new CryptoException('The public key is not a point on P-256.', 1758700171);
        }
        return $this->publicHandle = $handle;
    }

    /**
     * @return array{0: string, 1: string} the 32-byte x and y coordinates
     */
    private static function point(\OpenSSLAsymmetricKey $key): array
    {
        $details = openssl_pkey_get_details($key);
        $ec = is_array($details) && is_array($details['ec'] ?? null) ? $details['ec'] : [];
        if (($ec['curve_name'] ?? null) !== 'prime256v1' || !is_string($ec['x'] ?? null) || !is_string($ec['y'] ?? null)) {
            throw new CryptoException('The key is not a P-256 key.', 1758700172);
        }
        return [
            str_pad($ec['x'], 32, "\x00", STR_PAD_LEFT),
            str_pad($ec['y'], 32, "\x00", STR_PAD_LEFT),
        ];
    }
}
