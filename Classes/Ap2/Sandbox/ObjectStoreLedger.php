<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Ap2\Sandbox;

use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Webconsulting\AgentNexus\Ap2\Mandate\MandateLedger;
use Webconsulting\AgentNexus\Ap2\Mandate\MandateUsage;
use Webconsulting\AgentNexus\Shared\Store\ObjectKind;
use Webconsulting\AgentNexus\Shared\Store\ObjectStore;

/**
 * The verifiers' memory, read from the mandates the sandbox recorded:
 * the checkout JWTs the merchant issued (by checkout hash), the closed
 * mandates a verifier accepted (state `verified`), and how often an open
 * mandate authorised a purchase (its `usage`).
 */
#[AsAlias(MandateLedger::class)]
final readonly class ObjectStoreLedger implements MandateLedger
{
    public function __construct(
        private ObjectStore $objects,
    ) {}

    public function issuedCheckout(string $checkoutHash): ?string
    {
        $object = $this->objects->find(ObjectKind::Mandate, $checkoutHash);
        if ($object === null || ($object->payload['type'] ?? null) !== Artefact::CHECKOUT_JWT) {
            return null;
        }
        return is_string($object->payload['token'] ?? null) ? $object->payload['token'] : null;
    }

    public function usage(string $openReference): MandateUsage
    {
        $usage = $this->objects->find(ObjectKind::Mandate, $openReference)?->payload['usage'] ?? null;
        if (!is_array($usage)) {
            return new MandateUsage();
        }
        return new MandateUsage(
            is_int($usage['uses'] ?? null) ? $usage['uses'] : 0,
            is_int($usage['amount'] ?? null) ? $usage['amount'] : 0,
            is_int($usage['lastUse'] ?? null) ? $usage['lastUse'] : null,
        );
    }

    public function wasAccepted(string $closedReference): bool
    {
        return $this->objects->find(ObjectKind::Mandate, $closedReference)?->state === MandateRecorder::STATE_VERIFIED;
    }
}
