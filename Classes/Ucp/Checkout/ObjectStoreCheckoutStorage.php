<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Ucp\Checkout;

use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Webconsulting\AgentNexus\Shared\Store\ObjectFilter;
use Webconsulting\AgentNexus\Shared\Store\ObjectKind;
use Webconsulting\AgentNexus\Shared\Store\ObjectStore;
use Webconsulting\AgentNexus\Shared\Store\ProtocolObject;

/**
 * Checkout sessions as protocol objects (kind `checkout`), which is also what
 * the inspector lists.
 */
#[AsAlias(CheckoutStorage::class)]
final readonly class ObjectStoreCheckoutStorage implements CheckoutStorage
{
    public function __construct(
        private ObjectStore $objectStore,
    ) {}

    public function find(string $checkoutId): ?ProtocolObject
    {
        return $this->objectStore->find(ObjectKind::Checkout, $checkoutId);
    }

    public function save(ProtocolObject $checkout): ProtocolObject
    {
        return $this->objectStore->save($checkout);
    }

    public function forContext(string $contextId, int $limit = 20): array
    {
        if ($contextId === '') {
            return [];
        }
        return $this->objectStore->list(new ObjectFilter(ObjectKind::Checkout, contextId: $contextId), $limit);
    }
}
