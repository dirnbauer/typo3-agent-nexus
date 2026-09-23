<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Ap2\Sandbox;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * Who takes part in the sandbox: the demo store as the merchant, a second
 * shop to show the merchant check failing, one sandbox card, and the issuer
 * names that go into receipts.
 *
 * None of it is a real business, account or card.
 */
#[Exclude]
final class Parties
{
    public const string CURRENCY = 'EUR';

    /** The demo store, as an AP2 Merchant object. */
    public const array MERCHANT = [
        'id' => 'desiderio-store',
        'name' => 'Desiderio Store',
        'website' => 'https://webconsulting.at',
    ];

    /** A shop a mandate may name instead, to make the merchant check fail. */
    public const array OTHER_MERCHANT = [
        'id' => 'other-shop',
        'name' => 'Another shop',
        'website' => 'https://shop.example',
    ];

    /** The only payment instrument the credential provider holds. */
    public const array INSTRUMENT = [
        'id' => 'sandbox-card-4242',
        'type' => 'card',
        'description' => 'Sandbox card ending 4242',
    ];

    public const string CREDENTIAL_PROVIDER_ISSUER = 'urn:agent-nexus:sandbox:credential-provider';
    public const string PAYMENT_PROCESSOR_ISSUER = 'urn:agent-nexus:sandbox:payment-processor';

    /**
     * @return array{id: string, name: string, website: string}|null
     */
    public static function merchant(string $id): ?array
    {
        return match ($id) {
            self::MERCHANT['id'] => self::MERCHANT,
            self::OTHER_MERCHANT['id'] => self::OTHER_MERCHANT,
            default => null,
        };
    }

    /**
     * @return list<array{id: string, name: string, website: string}>
     */
    public static function merchants(): array
    {
        return [self::MERCHANT, self::OTHER_MERCHANT];
    }
}
