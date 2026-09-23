<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Ap2\Mandate;

use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Webconsulting\AgentNexus\Ap2\Crypto\EcKey;
use Webconsulting\AgentNexus\Ap2\Crypto\Jws;

/**
 * A signed AP2 receipt: the verifier's answer to a mandate, success or error,
 * bound to the closed mandate by `reference`.
 *
 * The merchant returns a Checkout Receipt, the payment processor (or, when
 * it refuses, the credential provider) a Payment Receipt. Both are ES256 JWTs
 * with the claims the v0.2 receipt schemas define.
 */
#[Exclude]
final readonly class Receipt
{
    public const string CHECKOUT = 'checkout_receipt';
    public const string PAYMENT = 'payment_receipt';

    /**
     * @param array<string, string> $header
     * @param array<string, int|string> $claims
     */
    private function __construct(
        public string $type,
        public string $token,
        public array $header,
        public array $claims,
    ) {}

    public static function checkout(Verdict $verdict, string $reference, string $issuer, string $orderId, EcKey $key, int $now): self
    {
        $claims = ['status' => $verdict->valid() ? 'Success' : 'Error', 'iss' => $issuer, 'iat' => $now, 'reference' => $reference];
        $claims += $verdict->valid()
            ? ['order_id' => $orderId]
            : self::error($verdict);
        return self::sign(self::CHECKOUT, $claims, $key);
    }

    public static function payment(Verdict $verdict, string $reference, string $issuer, string $paymentId, EcKey $key, int $now): self
    {
        $claims = ['status' => $verdict->valid() ? 'Success' : 'Error', 'iss' => $issuer, 'iat' => $now, 'reference' => $reference, 'payment_id' => $paymentId];
        $claims += $verdict->valid()
            ? [
                'psp_confirmation_id' => 'psp_sandbox_' . bin2hex(random_bytes(6)),
                'network_confirmation_id' => 'network_sandbox_' . bin2hex(random_bytes(6)),
            ]
            : self::error($verdict);
        return self::sign(self::PAYMENT, $claims, $key);
    }

    public function successful(): bool
    {
        return ($this->claims['status'] ?? null) === 'Success';
    }

    /**
     * @return array{type: string, token: string, header: array<string, string>, claims: array<string, int|string>}
     */
    public function toArray(): array
    {
        return ['type' => $this->type, 'token' => $this->token, 'header' => $this->header, 'claims' => $this->claims];
    }

    /**
     * @return array{error: string, error_description: string}
     */
    private static function error(Verdict $verdict): array
    {
        $error = $verdict->error() ?? ErrorCode::InvalidCredential;
        return [
            'error' => $error->value,
            'error_description' => $verdict->errorDescription() !== '' ? $verdict->errorDescription() : $error->description(),
        ];
    }

    /**
     * @param array<string, int|string> $claims
     */
    private static function sign(string $type, array $claims, EcKey $key): self
    {
        $header = ['alg' => Jws::ALGORITHM, 'typ' => 'JWT', 'kid' => $key->kid];
        return new self($type, Jws::sign($header, $claims, $key), $header, $claims);
    }
}
