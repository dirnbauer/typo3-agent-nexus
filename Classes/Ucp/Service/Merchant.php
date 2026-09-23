<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Ucp\Service;

use TYPO3\CMS\Core\SingletonInterface;

/**
 * The store behind the UCP business: what it sells and at which price.
 *
 * The catalogue is the only source of money in the UCP demo. The checkout API
 * prices every line from here, and the shopping agent never writes a number
 * of its own — a model may explain a choice, it never sets a price.
 *
 * Everything here is a sandbox store. No checkout takes real payment.
 */
final class Merchant implements SingletonInterface
{
    public const string CURRENCY = 'EUR';

    /**
     * The product catalogue. Prices are in minor units (cents), so no float
     * arithmetic can creep into a total.
     *
     * @return list<array{id: string, name: string, price: int, unit: string, tags: list<string>, description: string}>
     */
    public function catalog(): array
    {
        return [
            ['id' => 'pro-license', 'name' => 'Desiderio Pro Licence', 'price' => 4900, 'unit' => '/mo', 'tags' => ['license'], 'description' => 'Email support, LTS compatibility updates and early access to new elements.'],
            ['id' => 'agency-bundle', 'name' => 'Agency Bundle', 'price' => 14900, 'unit' => '/mo', 'tags' => ['license', 'teams'], 'description' => 'Unlimited projects, an answer within 4 business hours and a quarterly editor onboarding.'],
            ['id' => 'onboarding-addon', 'name' => 'Onboarding Add-on', 'price' => 29900, 'unit' => 'one-time', 'tags' => ['service'], 'description' => 'A guided setup: we prepare the workspace, publish a first page with you and write a rollout plan.'],
            ['id' => 'support-pack', 'name' => 'Priority Support Pack', 'price' => 9900, 'unit' => '/mo', 'tags' => ['service'], 'description' => 'Direct contact with the maintainers and an answer within 2 business days.'],
        ];
    }

    /**
     * One product, or an empty array for an id the store does not sell.
     *
     * @return array{id: string, name: string, price: int, unit: string, tags: list<string>, description: string}|array{}
     */
    public function product(string $id): array
    {
        return array_find($this->catalog(), static fn(array $product): bool => $product['id'] === $id) ?? [];
    }

    public function sells(string $id): bool
    {
        return $this->product($id) !== [];
    }

    /** True for products billed every month rather than once. */
    public function isMonthly(string $id): bool
    {
        return ($this->product($id)['unit'] ?? '') === '/mo';
    }
}
