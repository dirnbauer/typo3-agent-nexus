<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Ap2\Mandate;

/**
 * The four AP2 v0.2 mandate types, keyed by their exact `vct`.
 *
 * AP2 matches the `vct` string exactly, version suffix included
 * ("Implementations MUST match the exact vct string"); `.2` would be a new,
 * incompatible type. An open mandate carries constraints and the agent's key
 * (`cnf`); the closed one names one concrete checkout or payment.
 */
enum MandateType: string
{
    case OpenCheckout = 'mandate.checkout.open.1';
    case Checkout = 'mandate.checkout.1';
    case OpenPayment = 'mandate.payment.open.1';
    case Payment = 'mandate.payment.1';

    public function isOpen(): bool
    {
        return $this === self::OpenCheckout || $this === self::OpenPayment;
    }

    public function isCheckout(): bool
    {
        return $this === self::OpenCheckout || $this === self::Checkout;
    }

    public function open(): self
    {
        return $this->isCheckout() ? self::OpenCheckout : self::OpenPayment;
    }

    public function closed(): self
    {
        return $this->isCheckout() ? self::Checkout : self::Payment;
    }

    /** Short key for labels and form values: open_checkout, checkout, open_payment, payment. */
    public function key(): string
    {
        return match ($this) {
            self::OpenCheckout => 'open_checkout',
            self::Checkout => 'checkout',
            self::OpenPayment => 'open_payment',
            self::Payment => 'payment',
        };
    }

    public static function fromKey(string $key): ?self
    {
        return array_find(self::cases(), static fn(self $type): bool => $type->key() === $key);
    }

    /** English name, for API responses and the widget. */
    public function title(): string
    {
        return match ($this) {
            self::OpenCheckout => 'Open checkout mandate',
            self::Checkout => 'Checkout mandate',
            self::OpenPayment => 'Open payment mandate',
            self::Payment => 'Payment mandate',
        };
    }

    /**
     * The `$id` of the schema in code/sdk/schemas/ap2 of AP2 v0.2.0. Two of
     * them do not follow the file names; they are copied as published.
     */
    public function schemaId(): string
    {
        return match ($this) {
            self::OpenCheckout => 'https://ap2-protocol.org/schemas/open_checkout_mandate',
            self::Checkout => 'https://ap2-protocol.org/schemas/checkout_mandate.json',
            self::OpenPayment => 'https://ap2-protocol.org/schemas/payment_mandate_open.json',
            self::Payment => 'https://ap2-protocol.org/schemas/payment_mandate.json',
        };
    }

    /**
     * The audience of the closing key-binding token, as the AP2 examples name
     * the verifiers: the merchant checks checkout mandates, the credential
     * provider payment mandates.
     */
    public function audience(): string
    {
        return $this->isCheckout() ? 'merchant' : 'credential-provider';
    }
}
