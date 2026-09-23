<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Tests\Unit\Ucp\Fixtures;

use Webconsulting\AgentNexus\Shared\Store\ProtocolObject;
use Webconsulting\AgentNexus\Ucp\Checkout\CheckoutStorage;

/**
 * Checkout sessions in an array. Payloads take a JSON round trip on the way
 * in, exactly as they would through the object store's table.
 */
final class InMemoryCheckoutStorage implements CheckoutStorage
{
    /** @var array<string, ProtocolObject> */
    public array $objects = [];

    private int $lastUid = 0;

    public function find(string $checkoutId): ?ProtocolObject
    {
        return $this->objects[$checkoutId] ?? null;
    }

    public function save(ProtocolObject $checkout): ProtocolObject
    {
        $existing = $this->objects[$checkout->objectId] ?? null;
        $decoded = json_decode(json_encode($checkout->payload, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        $payload = [];
        foreach (is_array($decoded) ? $decoded : [] as $key => $value) {
            $payload[(string)$key] = $value;
        }
        $stored = new ProtocolObject(
            $checkout->kind,
            $checkout->objectId,
            $checkout->contextId,
            $checkout->state,
            $checkout->source,
            $checkout->label,
            $payload,
            $checkout->history,
            $checkout->pid,
            $checkout->beUser,
            $existing !== null ? $existing->uid : ++$this->lastUid,
            $existing !== null ? $existing->crdate : time(),
            time(),
        );
        // Newest change last, like ordering by tstamp.
        unset($this->objects[$checkout->objectId]);
        return $this->objects[$checkout->objectId] = $stored;
    }

    public function forContext(string $contextId, int $limit = 20): array
    {
        if ($contextId === '') {
            return [];
        }
        $matches = array_filter(
            array_reverse($this->objects),
            static fn(ProtocolObject $object): bool => $object->contextId === $contextId,
        );
        return array_values(array_slice($matches, 0, $limit));
    }
}
