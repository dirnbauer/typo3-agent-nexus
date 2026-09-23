<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Ucp\Checkout;

use Webconsulting\AgentNexus\Shared\Store\ProtocolObject;

/**
 * Where checkout sessions live between requests.
 *
 * In an installation that is the protocol object store
 * ({@see ObjectStoreCheckoutStorage}); the tests keep sessions in memory. The
 * payload of every object is the checkout JSON as it was last returned, plus
 * the private member {@see CheckoutRecord::PRIVATE_KEY}, which never leaves
 * the server.
 */
interface CheckoutStorage
{
    public function find(string $checkoutId): ?ProtocolObject;

    /** Insert or update by checkout id; returns what was stored. */
    public function save(ProtocolObject $checkout): ProtocolObject;

    /**
     * The sessions a shopping agent's conversation (AG-UI thread) created,
     * newest first.
     *
     * @return list<ProtocolObject>
     */
    public function forContext(string $contextId, int $limit = 20): array;
}
