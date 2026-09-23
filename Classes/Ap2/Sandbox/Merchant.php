<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Ap2\Sandbox;

use Webconsulting\AgentNexus\Ap2\Crypto\DelegateChain;
use Webconsulting\AgentNexus\Ap2\Crypto\Digest;
use Webconsulting\AgentNexus\Ap2\Crypto\EcKey;
use Webconsulting\AgentNexus\Ap2\Crypto\Jws;
use Webconsulting\AgentNexus\Ap2\Mandate\CheckoutVerifier;
use Webconsulting\AgentNexus\Ap2\Mandate\MandateLedger;
use Webconsulting\AgentNexus\Ap2\Mandate\MandateType;
use Webconsulting\AgentNexus\Ap2\Mandate\Receipt;
use Webconsulting\AgentNexus\Ap2\Mandate\Verdict;
use Webconsulting\AgentNexus\Ap2\Mandate\VerificationContext;

/**
 * The merchant role: signs checkouts, verifies checkout mandates and answers
 * with a checkout receipt.
 *
 * The checkout JWT is the UCP checkout object with `iat`, `exp` and a random
 * `jti`, signed with ES256 — a randomised signature and a unique id, so its
 * hash cannot be guessed from the cart (AP2's rainbow-table rule). The
 * `merchant` member is AP2's addition for binding mandates to a merchant.
 */
final readonly class Merchant
{
    public const int CHECKOUT_LIFETIME = 900;

    public function __construct(
        private KeyRing $keys,
        private Challenges $challenges,
        private MandateLedger $ledger,
        private CheckoutVerifier $verifier,
    ) {}

    /**
     * @param list<array{id: string, title: string, price: int, quantity: int}> $lines
     */
    public function checkout(array $lines, int $now, string $currency = Parties::CURRENCY): SignedCheckout
    {
        return self::signCheckout($lines, $this->keys->signer(Role::Merchant), $now, $currency);
    }

    /**
     * @param list<array{id: string, title: string, price: int, quantity: int}> $lines
     */
    public static function signCheckout(array $lines, EcKey $key, int $now, string $currency = Parties::CURRENCY): SignedCheckout
    {
        $lineItems = [];
        $total = 0;
        foreach ($lines as $index => $line) {
            $amount = $line['price'] * $line['quantity'];
            $total += $amount;
            $lineItems[] = [
                'id' => 'li_' . ($index + 1),
                'item' => ['id' => $line['id'], 'title' => $line['title'], 'price' => $line['price']],
                'quantity' => $line['quantity'],
                'totals' => [['type' => 'subtotal', 'amount' => $amount], ['type' => 'total', 'amount' => $amount]],
            ];
        }
        $checkout = [
            'id' => 'chk_' . bin2hex(random_bytes(8)),
            'merchant' => Parties::MERCHANT,
            'line_items' => $lineItems,
            'status' => 'ready_for_complete',
            'currency' => $currency,
            'totals' => [['type' => 'subtotal', 'amount' => $total], ['type' => 'total', 'amount' => $total]],
            'links' => [],
        ];
        $jwt = Jws::sign(
            ['typ' => 'JWT', 'kid' => $key->kid],
            $checkout + ['iat' => $now, 'exp' => $now + self::CHECKOUT_LIFETIME, 'jti' => bin2hex(random_bytes(12))],
            $key,
        );
        return new SignedCheckout($checkout, $jwt, Digest::of($jwt), $total, $currency);
    }

    public function challenge(): string
    {
        return $this->challenges->issue(MandateType::Checkout->audience());
    }

    /**
     * @param string|null $nonce the challenge this checkout asked for; null accepts any nonce this merchant issued
     * @param string|null $sessionCheckout the checkout JWT of the session being completed; null accepts any it issued
     */
    public function verify(DelegateChain $chain, ?string $nonce = null, ?string $sessionCheckout = null, bool $singleUse = true, ?int $now = null): Verdict
    {
        $audience = MandateType::Checkout->audience();
        return $this->verifier->verify($chain, new VerificationContext(
            rootKey: $this->keys->trustedRoots(),
            ledger: $this->ledger,
            audience: $audience,
            nonce: $nonce ?? fn(string $candidate): bool => $this->challenges->issued($audience, $candidate),
            merchantKey: $this->keys->publicKey(Role::Merchant),
            checkoutJwt: $sessionCheckout,
            singleUse: $singleUse,
            now: $now,
        ));
    }

    public function receipt(Verdict $verdict, string $reference, int $now): Receipt
    {
        return Receipt::checkout(
            $verdict,
            $reference,
            Parties::MERCHANT['website'],
            'ord_' . bin2hex(random_bytes(8)),
            $this->keys->signer(Role::Merchant),
            $now,
        );
    }
}
