<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Ap2\Crypto;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * One SD-JWT in compact form (RFC 9901 section 4): an issuer-signed JWT, the
 * disclosures the holder chose to present, and optionally a key-binding JWT.
 *
 * `<issuer-signed JWT>~<disclosure 1>~…~<disclosure n>~[<KB-JWT>]`
 *
 * AP2 closes a mandate with a KB-SD-JWT — itself an SD-JWT — instead of a
 * plain KB-JWT, so {@see DelegateChain} strings several of these together.
 */
#[Exclude]
final readonly class SdJwt
{
    /**
     * @param list<Disclosure> $disclosures
     */
    private function __construct(
        public DecodedJws $jws,
        public array $disclosures,
        public ?string $keyBindingJwt = null,
    ) {}

    /**
     * @throws CryptoException when the string is not an ES256 SD-JWT
     */
    public static function parse(string $serialized): self
    {
        if ($serialized === '' || $serialized[0] === '~') {
            throw new CryptoException('The SD-JWT has no issuer-signed JWT.', 1758700211);
        }
        if (!str_contains($serialized, '~')) {
            throw new CryptoException('An SD-JWT ends with a tilde; this is a plain JWT.', 1758700212);
        }
        $parts = explode('~', $serialized);
        $jws = Jws::decode($parts[0]);
        $keyBinding = array_pop($parts);
        $encoded = array_slice($parts, 1);
        foreach ($encoded as $disclosure) {
            if ($disclosure === '') {
                throw new CryptoException('The SD-JWT has an empty disclosure.', 1758700213);
            }
        }
        if ($keyBinding !== '') {
            Jws::decode($keyBinding);
        }

        $algorithm = self::algorithmOf($jws);
        $disclosures = array_map(
            static fn(string $disclosure): Disclosure => Disclosure::decode($disclosure, $algorithm),
            $encoded,
        );
        return new self($jws, array_values($disclosures), $keyBinding === '' ? null : $keyBinding);
    }

    /**
     * @param list<Disclosure> $disclosures
     */
    public static function fromParts(string $issuerJwt, array $disclosures): self
    {
        return new self(Jws::decode($issuerJwt), $disclosures);
    }

    public function issuerJwt(): string
    {
        return $this->jws->compact;
    }

    /**
     * @return array<string, mixed>
     */
    public function header(): array
    {
        return $this->jws->header;
    }

    /**
     * The issuer-signed payload as signed: digests, not disclosed values.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return $this->jws->payload();
    }

    public function typ(): string
    {
        return $this->jws->typ();
    }

    /** `_sd_alg` of the payload, SHA-256 when it names none. */
    public function algorithm(): string
    {
        return self::algorithmOf($this->jws);
    }

    /** The SD-JWT without a key-binding JWT, ending in a tilde. */
    public function withoutKeyBinding(): string
    {
        $encoded = array_map(static fn(Disclosure $disclosure): string => $disclosure->encoded, $this->disclosures);
        return $this->issuerJwt() . '~' . ($encoded === [] ? '' : implode('~', $encoded) . '~');
    }

    public function serialize(): string
    {
        return $this->withoutKeyBinding() . ($this->keyBindingJwt ?? '');
    }

    /**
     * RFC 9901 section 4.3.1: the digest of the SD-JWT as presented,
     * disclosures included — what the next hop's `sd_hash` must equal.
     */
    public function sdHash(): string
    {
        return Digest::of($this->withoutKeyBinding(), $this->algorithm());
    }

    /** The digest of the issuer-signed JWT alone (`issuer_jwt_hash`). */
    public function issuerJwtHash(): string
    {
        return Digest::of($this->issuerJwt(), $this->algorithm());
    }

    /**
     * The receipt reference of this token as a closed mandate: SHA-256 over
     * its issuer-signed JWT, as the AP2 SDK computes it
     * (`compute_sha256_b64url(get_closed_mandate_jwt(chain))`). Stable however
     * many hops precede the token and whichever disclosures were shown.
     */
    public function reference(): string
    {
        return Digest::of($this->issuerJwt());
    }

    /**
     * @param list<Disclosure> $disclosures
     */
    public function withDisclosures(array $disclosures): self
    {
        return new self($this->jws, $disclosures);
    }

    private static function algorithmOf(DecodedJws $jws): string
    {
        $algorithm = $jws->payload()['_sd_alg'] ?? Digest::DEFAULT_ALGORITHM;
        return is_string($algorithm) ? $algorithm : '';
    }
}
