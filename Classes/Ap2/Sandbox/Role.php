<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Ap2\Sandbox;

/**
 * The five AP2 roles, all played on this server by deterministic code, each
 * with its own sandbox key.
 */
enum Role: string
{
    /** Shows the mandate to the person and signs it. MUST be non-agentic. */
    case TrustedSurface = 'trusted-surface';
    /** Shops and closes open mandates with its own key. Agentic in AP2; scripted here. */
    case ShoppingAgent = 'shopping-agent';
    /** Signs the checkout, verifies the checkout mandate, issues the checkout receipt. */
    case Merchant = 'merchant';
    /** Verifies the payment mandate and releases a payment credential. */
    case CredentialProvider = 'credential-provider';
    /** Verifies the payment mandate in the credential, settles, issues the payment receipt. */
    case PaymentProcessor = 'payment-processor';

    public function label(): string
    {
        return match ($this) {
            self::TrustedSurface => 'Trusted Surface',
            self::ShoppingAgent => 'Shopping agent',
            self::Merchant => 'Merchant',
            self::CredentialProvider => 'Credential provider',
            self::PaymentProcessor => 'Payment processor',
        };
    }

    public static function fromLabel(string $label): ?self
    {
        return array_find(self::cases(), static fn(self $role): bool => $role->label() === $label || $role->value === $label);
    }
}
