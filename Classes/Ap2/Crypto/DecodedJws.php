<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Ap2\Crypto;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * A compact JWS taken apart, not yet verified.
 *
 * Verifiers need the header before they can pick a key (`kid`), so decoding
 * and verifying are two steps. Decoding already refuses what no verifier here
 * would accept: another algorithm than ES256, header parameters it does not
 * understand, a signature of the wrong size.
 */
#[Exclude]
final readonly class DecodedJws
{
    /**
     * @param array<string, mixed> $header
     */
    public function __construct(
        public string $compact,
        public array $header,
        public string $encodedPayload,
        public string $signature,
    ) {}

    public function signingInput(): string
    {
        $segments = explode('.', $this->compact);
        return $segments[0] . '.' . $this->encodedPayload;
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return Json::decodeObject(Base64Url::decode($this->encodedPayload));
    }

    public function kid(): string
    {
        return is_string($this->header['kid'] ?? null) ? $this->header['kid'] : '';
    }

    public function typ(): string
    {
        return is_string($this->header['typ'] ?? null) ? $this->header['typ'] : '';
    }

    public function verifiesWith(EcKey $key): bool
    {
        return $key->verify($this->signingInput(), $this->signature);
    }
}
