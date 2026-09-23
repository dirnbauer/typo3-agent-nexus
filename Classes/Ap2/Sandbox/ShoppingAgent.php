<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Ap2\Sandbox;

use Webconsulting\AgentNexus\Ap2\Crypto\DelegateChain;
use Webconsulting\AgentNexus\Ap2\Crypto\Disclosable;
use Webconsulting\AgentNexus\Ap2\Crypto\SdJwtIssuer;

/**
 * The shopping agent. In AP2 it is the agentic role — the one every other
 * role must distrust. Here it is scripted, and it does two things: pick a
 * cart from the approved list, and close open mandates with its own key.
 */
final readonly class ShoppingAgent
{
    public const string TYP = 'kb+sd-jwt';

    public function __construct(
        private KeyRing $keys,
    ) {}

    /**
     * The key open mandates name in `cnf`, so only this agent can close them.
     *
     * @return array{kty: string, crv: string, x: string, y: string}
     */
    public function publicJwk(): array
    {
        return $this->keys->signer(Role::ShoppingAgent)->publicJwk();
    }

    /**
     * Close an open mandate: a key-binding SD-JWT that discloses the closed
     * mandate, names the verifier (`aud`) and its challenge (`nonce`), and
     * binds to the open mandate as presented (`sd_hash`) — signed with the key
     * the open mandate names.
     *
     * @param array<string, mixed> $content the closed mandate
     */
    public function close(DelegateChain $open, array $content, string $audience, string $nonce, int $now): DelegateChain
    {
        return $open->append(SdJwtIssuer::issue(
            [
                'delegate_payload' => [new Disclosable($content)],
                'iat' => $now,
                'aud' => $audience,
                'nonce' => $nonce,
                'sd_hash' => $open->leaf()->sdHash(),
            ],
            ['typ' => self::TYP],
            $this->keys->signer(Role::ShoppingAgent),
        ));
    }

    /**
     * Pick one product per line. Within the cap: the fullest cart that still
     * fits, or the cheapest one when none does. Over the cap: the cheapest
     * cart above it, or the dearest when every cart fits.
     *
     * @param list<array{id: string, title: string, quantity: int, options: list<array{id: string, title: string, price: int}>}> $list
     * @return array{lines: list<array{id: string, title: string, price: int, quantity: int}>, total: int, withinCap: bool}
     */
    public function chooseCart(array $list, int $cap, bool $overCap): array
    {
        $carts = [['lines' => [], 'total' => 0]];
        foreach ($list as $line) {
            $next = [];
            foreach ($carts as $cart) {
                foreach ($line['options'] as $option) {
                    $next[] = [
                        'lines' => [...$cart['lines'], ['id' => $option['id'], 'title' => $option['title'], 'price' => $option['price'], 'quantity' => $line['quantity']]],
                        'total' => $cart['total'] + $option['price'] * $line['quantity'],
                    ];
                }
            }
            $carts = $next;
        }
        usort($carts, static fn(array $a, array $b): int => $a['total'] <=> $b['total']);

        $fitting = array_values(array_filter($carts, static fn(array $cart): bool => $cart['total'] <= $cap));
        $exceeding = array_values(array_filter($carts, static fn(array $cart): bool => $cart['total'] > $cap));
        $chosen = $overCap
            ? ($exceeding[0] ?? $carts[count($carts) - 1])
            : ($fitting === [] ? $carts[0] : $fitting[count($fitting) - 1]);

        return ['lines' => $chosen['lines'], 'total' => $chosen['total'], 'withinCap' => $chosen['total'] <= $cap];
    }
}
