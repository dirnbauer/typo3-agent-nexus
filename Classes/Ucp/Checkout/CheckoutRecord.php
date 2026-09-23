<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Ucp\Checkout;

use Webconsulting\AgentNexus\Shared\Store\ProtocolObject;

/**
 * The two halves of a stored checkout: the checkout JSON the API returns, and
 * a private member for what only this installation needs — the shopping
 * agent's pending approval, for one. The private member is stripped from
 * every response.
 */
final class CheckoutRecord
{
    public const string PRIVATE_KEY = '_agentNexus';

    /**
     * The checkout exactly as the API returns it.
     *
     * @return array<string, mixed>
     */
    public static function checkout(ProtocolObject $object): array
    {
        $checkout = $object->payload;
        unset($checkout[self::PRIVATE_KEY]);
        return $checkout;
    }

    /**
     * @return array<string, mixed>
     */
    public static function meta(ProtocolObject $object): array
    {
        $meta = $object->payload[self::PRIVATE_KEY] ?? null;
        if (!is_array($meta)) {
            return [];
        }
        $result = [];
        foreach ($meta as $key => $value) {
            $result[(string)$key] = $value;
        }
        return $result;
    }

    /**
     * @param array<string, mixed> $checkout
     * @param array<string, mixed> $meta
     * @return array<string, mixed>
     */
    public static function payload(array $checkout, array $meta): array
    {
        unset($checkout[self::PRIVATE_KEY]);
        return $meta === [] ? $checkout : $checkout + [self::PRIVATE_KEY => $meta];
    }
}
