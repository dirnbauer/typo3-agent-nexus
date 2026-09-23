<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Ucp\Service;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\SingletonInterface;

/**
 * The site as a UCP **merchant**: it publishes a machine-readable manifest (what
 * the store is, which currency, which capabilities, where to check out) and a
 * **catalog** a shopping agent can browse. Discovery via a manifest is the entry
 * point of agentic commerce — the agent reads it before it ever builds a cart.
 *
 * Everything here is a demo store. No checkout takes real payment.
 */
final class Merchant implements SingletonInterface
{
    public const CURRENCY = 'EUR';

    /**
     * The product catalog. Prices are in minor units (cents) to avoid float math.
     *
     * @return array<int, array<string, mixed>>
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
     * @return array<string, mixed>
     */
    public function product(string $id): array
    {
        foreach ($this->catalog() as $p) {
            if ($p['id'] === $id) {
                return $p;
            }
        }
        return [];
    }

    /**
     * The UCP manifest a shopping agent fetches first.
     *
     * @return array<string, mixed>
     */
    public function manifest(ServerRequestInterface $request): array
    {
        $base = rtrim((string)$request->getUri()->withQuery('')->withFragment('')->withPath(''), '/');

        return [
            'ucpVersion' => '0.1',
            'merchant' => [
                'name' => 'Desiderio Store',
                'description' => 'A demo store for Desiderio licences, bundles and services.',
                'url' => 'https://webconsulting.at',
                'currency' => self::CURRENCY,
            ],
            'capabilities' => [
                'agentCheckout' => true,
                'streaming' => true,
                'humanAuthorization' => true,
                'paymentProtocols' => ['ap2-simulated'],
            ],
            'endpoints' => [
                'checkout' => $base . '/index.php?eID=ucp_checkout',
            ],
            'catalog' => $this->catalog(),
            // Demo merchant — every checkout is simulated; no real payment is taken.
            'sandbox' => true,
        ];
    }
}
