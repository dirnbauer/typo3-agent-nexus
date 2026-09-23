<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Ap2\Sandbox;

use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Webconsulting\AgentNexus\Ap2\Crypto\CryptoException;
use Webconsulting\AgentNexus\Ap2\Crypto\DelegateChain;
use Webconsulting\AgentNexus\Ap2\Crypto\Digest;
use Webconsulting\AgentNexus\Ap2\Crypto\Disclosure;
use Webconsulting\AgentNexus\Ap2\Crypto\Json;
use Webconsulting\AgentNexus\Ap2\Crypto\Jws;
use Webconsulting\AgentNexus\Ap2\Crypto\SdJwt;
use Webconsulting\AgentNexus\Ap2\Crypto\SdJwtProcessor;
use Webconsulting\AgentNexus\Ap2\Mandate\MandateType;

/**
 * One thing a role signed — a mandate, the merchant's checkout JWT, a
 * receipt — taken apart for people: the compact token, its header, what it
 * says (disclosures applied) and each disclosure on its own.
 *
 * For a closed mandate the token is the whole chain; `hops` shows each of its
 * tokens as signed, digests and all.
 */
#[Exclude]
final readonly class Artefact
{
    public const string CHECKOUT_JWT = 'checkout_jwt';

    /**
     * @param array<string, mixed> $header
     * @param array<string, mixed> $claims
     * @param list<array<string, mixed>> $disclosures
     * @param list<array<string, mixed>> $hops
     */
    private function __construct(
        public string $kind,
        public Role $role,
        public string $token,
        public array $header,
        public array $claims,
        public array $disclosures,
        public array $hops,
        public string $reference,
        public string $label,
    ) {}

    public static function mandate(DelegateChain $chain, Role $role, string $label): self
    {
        $leaf = $chain->leaf();
        $mandate = self::mandateOf($leaf);
        return new self(
            Json::string($mandate['vct'] ?? null, 'mandate'),
            $role,
            $chain->serialize(),
            $leaf->header(),
            $mandate,
            self::disclosures($leaf),
            $chain->count() > 1 ? array_map(self::hop(...), $chain->tokens, array_keys($chain->tokens)) : [],
            $chain->reference(),
            $label,
        );
    }

    /**
     * A plain signed JWT: the merchant's checkout or a receipt.
     */
    public static function jwt(string $kind, string $token, Role $role, string $label, ?string $reference = null): self
    {
        $jws = Jws::decode($token);
        return new self($kind, $role, $token, $jws->header, $jws->payload(), [], [], $reference ?? Digest::of($token), $label);
    }

    public function isMandate(): bool
    {
        return MandateType::tryFrom($this->kind) !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $array = [
            'kind' => $this->kind,
            'title' => MandateType::tryFrom($this->kind)?->title() ?? match ($this->kind) {
                self::CHECKOUT_JWT => 'Signed checkout',
                'checkout_receipt' => 'Checkout receipt',
                'payment_receipt' => 'Payment receipt',
                default => $this->kind,
            },
            'role' => $this->role->value,
            'roleLabel' => $this->role->label(),
            'label' => $this->label,
            'reference' => $this->reference,
            'token' => $this->token,
            'header' => $this->header,
            'claims' => $this->claims,
            'disclosures' => $this->disclosures,
        ];
        if ($this->hops !== []) {
            $array['hops'] = $this->hops;
        }
        return $array;
    }

    /**
     * The object store payload: the contract the inspector reads.
     *
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    public function payload(string $chainId, array $extra = []): array
    {
        $payload = $this->isMandate() ? ['vct' => $this->kind] : ['type' => $this->kind];
        $payload += [
            'role' => $this->role->label(),
            'token' => $this->token,
            'header' => $this->header,
            'claims' => $this->claims,
            'disclosures' => $this->disclosures,
            'reference' => $this->reference,
            'chainId' => $chainId,
        ];
        if ($this->hops !== []) {
            $payload['hops'] = $this->hops;
        }
        return $payload + $extra;
    }

    /**
     * The mandate a token discloses, for display; [] when it cannot be read.
     *
     * @return array<string, mixed>
     */
    public static function mandateOf(SdJwt $token): array
    {
        try {
            return Json::objects(SdJwtProcessor::process($token)['delegate_payload'] ?? null)[0] ?? [];
        } catch (CryptoException) {
            return [];
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function disclosures(SdJwt $token): array
    {
        return array_map(static fn(Disclosure $disclosure): array => $disclosure->toArray(), $token->disclosures);
    }

    /**
     * @return array<string, mixed>
     */
    private static function hop(SdJwt $token, int $index): array
    {
        return [
            'position' => $index + 1,
            'typ' => $token->typ(),
            'kid' => $token->jws->kid(),
            'header' => $token->header(),
            'payload' => $token->payload(),
            'disclosures' => self::disclosures($token),
            'mandate' => self::mandateOf($token),
        ];
    }
}
