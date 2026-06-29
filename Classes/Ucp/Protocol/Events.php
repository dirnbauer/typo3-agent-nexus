<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Ucp\Protocol;

/**
 * Factory for UCP commerce events.
 *
 * An agent-driven checkout streams a small, typed state machine over SSE. Each
 * event is a plain associative array with a `type` plus its payload — exactly
 * what gets JSON-encoded into one `data:` frame. The states walk a purchase from
 * discovery to a human-authorized (simulated) confirmation:
 *
 *   checkout.started → cart.updated → checkout.review →
 *   authorization.required → (authorization.approved → order.confirmed | order.declined)
 */
final class Events
{
    public static function started(string $orderId, string $intent): array
    {
        return ['type' => 'checkout.started', 'orderId' => $orderId, 'intent' => $intent];
    }

    public static function reasoning(string $text): array
    {
        return ['type' => 'agent.reasoning', 'text' => $text];
    }

    /**
     * @param array<int, array<string, mixed>> $items
     */
    public static function cart(string $orderId, array $items, int $totalCents, string $currency): array
    {
        return ['type' => 'cart.updated', 'orderId' => $orderId, 'items' => $items, 'totalCents' => $totalCents, 'currency' => $currency];
    }

    public static function step(string $state, string $note = ''): array
    {
        return ['type' => 'checkout.step', 'state' => $state, 'note' => $note];
    }

    /**
     * The human-in-the-loop gate: the order is assembled but NOT placed. The agent
     * asks the human to authorize the purchase.
     *
     * @param array<string, mixed> $order
     */
    public static function authorizationRequired(string $orderId, array $order): array
    {
        return ['type' => 'authorization.required', 'orderId' => $orderId, 'order' => $order];
    }

    public static function authorized(string $orderId, string $mandate): array
    {
        return ['type' => 'authorization.approved', 'orderId' => $orderId, 'mandate' => $mandate];
    }

    /**
     * @param array<string, mixed> $order
     */
    public static function confirmed(string $orderId, array $order, bool $simulated): array
    {
        return ['type' => 'order.confirmed', 'orderId' => $orderId, 'order' => $order, 'simulated' => $simulated];
    }

    public static function declined(string $orderId): array
    {
        return ['type' => 'order.declined', 'orderId' => $orderId];
    }

    public static function error(string $message, string $code = ''): array
    {
        return ['type' => 'checkout.error', 'message' => $message, 'code' => $code];
    }
}
