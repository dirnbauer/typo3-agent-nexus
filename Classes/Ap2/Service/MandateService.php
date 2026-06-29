<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Ap2\Service;

use TYPO3\CMS\Core\SingletonInterface;

/**
 * Mints and verifies AP2 **mandates** — the signed authorizations that let an
 * agent pay on a human's behalf.
 *
 *   - **Intent Mandate**: the human authorizes an agent to spend within limits
 *     (a cap, allowed merchants, an expiry) — typically created while the human
 *     is present, then used later when they are not.
 *   - **Cart Mandate**: for a specific, fully-priced cart, references the Intent
 *     Mandate and proves *this exact purchase* is authorized.
 *
 * Verification walks the chain: both signatures valid and unexpired, the cart
 * references the intent, the merchant is allowed, and the cart total is within the
 * intent's cap. Only then is a (simulated) payment authorized.
 *
 * SANDBOX: tokens are signed with a demo key (see {@see Jwt}); no real money,
 * identity or payment network is involved.
 */
final class MandateService implements SingletonInterface
{
    public function __construct(
        private readonly Jwt $jwt,
    ) {}

    /**
     * @param array<string, mixed> $constraints {maxAmountCents, currency, merchants[], humanPresent}
     * @return array{jwt: string, claims: array<string, mixed>}
     */
    public function mintIntentMandate(array $constraints, string $user = 'user:demo', string $agent = 'agent:shopping'): array
    {
        $now = time();
        $merchants = array_values(array_filter(array_map('strval', (array)($constraints['merchants'] ?? ['desiderio-store']))));
        $claims = [
            'typ' => 'IntentMandate',
            'iss' => $user,
            'sub' => $agent,
            'aud' => $merchants[0] ?? 'desiderio-store',
            'constraints' => [
                'maxAmountCents' => (int)($constraints['maxAmountCents'] ?? 50000),
                'currency' => (string)($constraints['currency'] ?? 'EUR'),
                'merchants' => $merchants,
                'humanPresent' => (bool)($constraints['humanPresent'] ?? true),
            ],
            'iat' => $now,
            'exp' => $now + 3600,
            'jti' => 'im-' . substr(md5($user . $agent . microtime(false)), 0, 12),
        ];
        return ['jwt' => $this->jwt->sign($claims), 'claims' => $claims];
    }

    /**
     * @param array<string, mixed> $cart {items[], totalCents, currency, merchant}
     * @return array{jwt: string, claims: array<string, mixed>}
     */
    public function mintCartMandate(array $cart, string $intentRef, string $user = 'user:demo', string $agent = 'agent:shopping'): array
    {
        $now = time();
        $claims = [
            'typ' => 'CartMandate',
            'iss' => $user,
            'sub' => $agent,
            'aud' => (string)($cart['merchant'] ?? 'desiderio-store'),
            'cart' => [
                'items' => array_values((array)($cart['items'] ?? [])),
                'totalCents' => (int)($cart['totalCents'] ?? 0),
                'currency' => (string)($cart['currency'] ?? 'EUR'),
            ],
            'intentRef' => $intentRef,
            'iat' => $now,
            'exp' => $now + 600,
            'jti' => 'cm-' . substr(md5($intentRef . microtime(false)), 0, 12),
        ];
        return ['jwt' => $this->jwt->sign($claims), 'claims' => $claims];
    }

    /**
     * Verify a single mandate token (signature + expiry).
     *
     * @return array{valid: bool, reason: string, header: array<string, mixed>, claims: array<string, mixed>}
     */
    public function inspect(string $jwt): array
    {
        return $this->jwt->verify($jwt);
    }

    /**
     * Walk the Intent → Cart authorization chain.
     *
     * @return array{authorized: bool, checks: array<int, array{label: string, pass: bool, detail: string}>, intent: array<string, mixed>, cart: array<string, mixed>}
     */
    public function verifyChain(string $intentJwt, string $cartJwt): array
    {
        $intent = $this->jwt->verify($intentJwt);
        $cart = $this->jwt->verify($cartJwt);
        $ic = $intent['claims'];
        $cc = $cart['claims'];
        $constraints = is_array($ic['constraints'] ?? null) ? $ic['constraints'] : [];
        $cartData = is_array($cc['cart'] ?? null) ? $cc['cart'] : [];

        $merchants = array_map('strval', (array)($constraints['merchants'] ?? []));
        $total = (int)($cartData['totalCents'] ?? 0);
        $cap = (int)($constraints['maxAmountCents'] ?? 0);

        $checks = [
            ['label' => 'Intent Mandate signature', 'pass' => (bool)$intent['valid'], 'detail' => (string)$intent['reason']],
            ['label' => 'Cart Mandate signature', 'pass' => (bool)$cart['valid'], 'detail' => (string)$cart['reason']],
            ['label' => 'Cart references the Intent', 'pass' => ($cc['intentRef'] ?? null) === ($ic['jti'] ?? '·'), 'detail' => (string)($cc['intentRef'] ?? '—') . ' = ' . (string)($ic['jti'] ?? '—')],
            ['label' => 'Same authorized merchant', 'pass' => ($cc['aud'] ?? '·') === ($ic['aud'] ?? '··') && in_array((string)($cc['aud'] ?? ''), $merchants, true), 'detail' => (string)($cc['aud'] ?? '—')],
            ['label' => 'Within the spending cap', 'pass' => $total > 0 && $total <= $cap, 'detail' => $this->money($total) . ' ≤ ' . $this->money($cap)],
        ];
        $authorized = array_reduce($checks, static fn(bool $c, array $x): bool => $c && $x['pass'], true);

        return [
            'authorized' => $authorized,
            'checks' => $checks,
            'intent' => $ic,
            'cart' => $cc,
        ];
    }

    private function money(int $cents): string
    {
        return '€' . number_format($cents / 100, 2);
    }
}
