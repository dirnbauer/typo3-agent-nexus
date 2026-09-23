<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Ap2\Mandate;

use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Webconsulting\AgentNexus\Ap2\Crypto\Disclosable;

/**
 * Builds the content of the four mandate types, with the parts the schemas
 * mark selectively disclosable wrapped in {@see Disclosable}:
 * `acceptable_items`, `allowed` merchants, payees and payment instruments
 * element by element, and the `checkout_jwt` claim.
 *
 * The order of the claims follows the schemas; the SDK's examples do the same.
 */
#[Exclude]
final class MandateContent
{
    /**
     * @param list<array{id: string, acceptable: list<array{id: string, title: string}>, quantity: int}> $lines
     * @param list<array<string, string>> $merchants
     * @param array<string, string> $agentJwk the key the agent will close the mandate with
     * @return array<string, mixed>
     */
    public static function openCheckout(array $lines, array $merchants, array $agentJwk, int $issuedAt, int $expiresAt): array
    {
        return [
            'vct' => MandateType::OpenCheckout->value,
            'constraints' => [
                [
                    'type' => ConstraintType::LineItems->value,
                    'items' => array_map(static fn(array $line): array => [
                        'id' => $line['id'],
                        'acceptable_items' => array_map(static fn(array $item): Disclosable => new Disclosable($item), $line['acceptable']),
                        'quantity' => $line['quantity'],
                    ], $lines),
                ],
                [
                    'type' => ConstraintType::AllowedMerchants->value,
                    'allowed' => array_map(static fn(array $merchant): Disclosable => new Disclosable($merchant), $merchants),
                ],
            ],
            'cnf' => ['jwk' => $agentJwk],
            'iat' => $issuedAt,
            'exp' => $expiresAt,
        ];
    }

    /**
     * @param list<array<string, string>> $payees
     * @param list<array<string, string>> $instruments
     * @param string $checkoutReference digest of the open checkout mandate, as presented
     * @param array<string, string> $agentJwk
     * @return array<string, mixed>
     */
    public static function openPayment(
        int $maxAmount,
        string $currency,
        array $payees,
        array $instruments,
        string $checkoutReference,
        array $agentJwk,
        int $issuedAt,
        int $expiresAt,
    ): array {
        return [
            'vct' => MandateType::OpenPayment->value,
            'constraints' => [
                ['type' => ConstraintType::AmountRange->value, 'currency' => $currency, 'max' => $maxAmount, 'min' => 0],
                [
                    'type' => ConstraintType::AllowedPayees->value,
                    'allowed' => array_map(static fn(array $payee): Disclosable => new Disclosable($payee), $payees),
                ],
                [
                    'type' => ConstraintType::AllowedPaymentInstruments->value,
                    'allowed' => array_map(static fn(array $instrument): Disclosable => new Disclosable($instrument), $instruments),
                ],
                ['type' => ConstraintType::Reference->value, 'conditional_transaction_id' => $checkoutReference],
            ],
            'cnf' => ['jwk' => $agentJwk],
            'iat' => $issuedAt,
            'exp' => $expiresAt,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function closedCheckout(string $checkoutJwt, string $checkoutHash, int $issuedAt, int $expiresAt): array
    {
        return [
            'vct' => MandateType::Checkout->value,
            'checkout_jwt' => new Disclosable($checkoutJwt),
            'checkout_hash' => $checkoutHash,
            'iat' => $issuedAt,
            'exp' => $expiresAt,
        ];
    }

    /**
     * @param array<string, string> $payee
     * @param array<string, string> $instrument
     * @return array<string, mixed>
     */
    public static function closedPayment(
        string $transactionId,
        array $payee,
        int $amount,
        string $currency,
        array $instrument,
        int $issuedAt,
        int $expiresAt,
    ): array {
        return [
            'vct' => MandateType::Payment->value,
            'transaction_id' => $transactionId,
            'payee' => $payee,
            'payment_amount' => ['amount' => $amount, 'currency' => $currency],
            'payment_instrument' => $instrument,
            'iat' => $issuedAt,
            'exp' => $expiresAt,
        ];
    }

    /**
     * The content as a verifier sees it with every disclosure presented.
     */
    public static function plain(mixed $content): mixed
    {
        if ($content instanceof Disclosable) {
            return self::plain($content->value);
        }
        if (!is_array($content)) {
            return $content;
        }
        return array_map(self::plain(...), $content);
    }
}
