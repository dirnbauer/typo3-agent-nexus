<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Tests\Unit\Ap2\Fixtures;

use Webconsulting\AgentNexus\Ap2\Crypto\DelegateChain;
use Webconsulting\AgentNexus\Ap2\Mandate\CheckoutVerifier;
use Webconsulting\AgentNexus\Ap2\Mandate\MandateContent;
use Webconsulting\AgentNexus\Ap2\Mandate\MandateType;
use Webconsulting\AgentNexus\Ap2\Mandate\PaymentVerifier;
use Webconsulting\AgentNexus\Ap2\Sandbox\CredentialProvider;
use Webconsulting\AgentNexus\Ap2\Sandbox\KeyRing;
use Webconsulting\AgentNexus\Ap2\Sandbox\MemoryKeyStore;
use Webconsulting\AgentNexus\Ap2\Sandbox\Merchant;
use Webconsulting\AgentNexus\Ap2\Sandbox\Parties;
use Webconsulting\AgentNexus\Ap2\Sandbox\PaymentProcessor;
use Webconsulting\AgentNexus\Ap2\Sandbox\ShoppingAgent;
use Webconsulting\AgentNexus\Ap2\Sandbox\SignedCheckout;
use Webconsulting\AgentNexus\Ap2\Sandbox\TrustedSurface;

/**
 * Every sandbox role wired together without a database: keys in memory,
 * nonces and the verifier ledger in memory. One purchase at a time.
 */
final class Sandbox
{
    /** The demo shopping list: a licence (either of two), onboarding, support. */
    public const array SHOPPING_LIST = [
        ['id' => 'licence', 'title' => 'Licence', 'quantity' => 1, 'options' => [
            ['id' => 'pro-license', 'title' => 'Desiderio Pro Licence', 'price' => 4900],
            ['id' => 'agency-bundle', 'title' => 'Agency Bundle', 'price' => 14900],
        ]],
        ['id' => 'onboarding', 'title' => 'Onboarding', 'quantity' => 1, 'options' => [
            ['id' => 'onboarding-addon', 'title' => 'Onboarding Add-on', 'price' => 29900],
        ]],
        ['id' => 'support', 'title' => 'Support', 'quantity' => 1, 'options' => [
            ['id' => 'support-pack', 'title' => 'Priority Support Pack', 'price' => 9900],
        ]],
    ];

    public readonly KeyRing $keys;
    public readonly InMemoryLedger $ledger;
    public readonly InMemoryChallenges $challenges;
    public readonly TrustedSurface $trustedSurface;
    public readonly ShoppingAgent $agent;
    public readonly Merchant $merchant;
    public readonly CredentialProvider $credentialProvider;
    public readonly PaymentProcessor $processor;

    public function __construct()
    {
        $this->keys = new KeyRing(new MemoryKeyStore());
        $this->ledger = new InMemoryLedger();
        $this->challenges = new InMemoryChallenges();
        $this->trustedSurface = new TrustedSurface($this->keys);
        $this->agent = new ShoppingAgent($this->keys);
        $this->merchant = new Merchant($this->keys, $this->challenges, $this->ledger, new CheckoutVerifier());
        $this->credentialProvider = new CredentialProvider($this->keys, $this->challenges, $this->ledger, new PaymentVerifier());
        $this->processor = new PaymentProcessor($this->keys, $this->ledger, new PaymentVerifier());
    }

    /**
     * @param list<array<string, string>>|null $merchants
     */
    public function openCheckout(?array $merchants = null, int $lifetime = 900): DelegateChain
    {
        $now = time();
        $lines = array_values(array_map(static fn(array $line): array => [
            'id' => $line['id'],
            'acceptable' => array_values(array_map(static fn(array $option): array => ['id' => $option['id'], 'title' => $option['title']], $line['options'])),
            'quantity' => $line['quantity'],
        ], self::SHOPPING_LIST));
        return $this->trustedSurface->sign(MandateContent::openCheckout($lines, $merchants ?? [Parties::MERCHANT], $this->agent->publicJwk(), $now, $now + $lifetime));
    }

    public function openPayment(DelegateChain $openCheckout, int $cap = 50000, int $lifetime = 900): DelegateChain
    {
        $now = time();
        return $this->trustedSurface->sign(MandateContent::openPayment(
            $cap,
            'EUR',
            [Parties::MERCHANT],
            [Parties::INSTRUMENT],
            $openCheckout->root()->sdHash(),
            $this->agent->publicJwk(),
            $now,
            $now + $lifetime,
        ));
    }

    /**
     * A checkout for one option per line (by index), issued by the merchant.
     *
     * @param list<int> $choice option index per shopping-list line
     */
    public function checkout(array $choice = [0, 0, 0]): SignedCheckout
    {
        $lines = [];
        foreach (self::SHOPPING_LIST as $index => $line) {
            $option = $line['options'][$choice[$index] ?? 0];
            $lines[] = $option + ['quantity' => $line['quantity']];
        }
        $checkout = $this->merchant->checkout($lines, time());
        $this->ledger->issue($checkout->jwt);
        return $checkout;
    }

    /**
     * @return array{chain: DelegateChain, nonce: string}
     */
    public function closeCheckout(DelegateChain $open, SignedCheckout $checkout): array
    {
        $nonce = $this->merchant->challenge();
        $now = time();
        return [
            'chain' => $this->agent->close($open, MandateContent::closedCheckout($checkout->jwt, $checkout->hash, $now, $now + 900), MandateType::Checkout->audience(), $nonce, $now),
            'nonce' => $nonce,
        ];
    }

    /**
     * @return array{chain: DelegateChain, nonce: string}
     */
    public function closePayment(DelegateChain $open, SignedCheckout $checkout, ?int $amount = null): array
    {
        $nonce = $this->credentialProvider->challenge();
        $now = time();
        $content = MandateContent::closedPayment($checkout->hash, Parties::MERCHANT, $amount ?? $checkout->total, 'EUR', Parties::INSTRUMENT, $now, $now + 900);
        return [
            'chain' => $this->agent->close($open, $content, MandateType::Payment->audience(), $nonce, $now),
            'nonce' => $nonce,
        ];
    }
}
