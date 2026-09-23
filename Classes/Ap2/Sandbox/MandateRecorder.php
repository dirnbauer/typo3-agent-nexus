<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Ap2\Sandbox;

use Webconsulting\AgentNexus\Ap2\Crypto\DelegateChain;
use Webconsulting\AgentNexus\Ap2\Crypto\SdJwt;
use Webconsulting\AgentNexus\Ap2\Mandate\Verdict;
use Webconsulting\AgentNexus\Shared\Store\ObjectKind;
use Webconsulting\AgentNexus\Shared\Store\ObjectStore;
use Webconsulting\AgentNexus\Shared\Store\ProtocolObject;

/**
 * Writes what the sandbox signs into the object store, where the inspector
 * shows it: one object per mandate, checkout JWT and receipt, grouped by the
 * chain they belong to.
 *
 * States: `issued` when signed, `verified` once a verifier accepted it,
 * `rejected` when one refused it. An open mandate that authorised a purchase
 * also counts its uses — the single-use rule reads that.
 */
final readonly class MandateRecorder
{
    public const string STATE_ISSUED = 'issued';
    public const string STATE_VERIFIED = 'verified';
    public const string STATE_REJECTED = 'rejected';

    public function __construct(
        private ObjectStore $objects,
    ) {}

    public function record(Artefact $artefact, string $chainId, RecordingContext $context, string $state = self::STATE_ISSUED, string $note = ''): ProtocolObject
    {
        $existing = $this->objects->find(ObjectKind::Mandate, $artefact->reference);
        $payload = $artefact->payload($chainId, $existing !== null && is_array($existing->payload['usage'] ?? null) ? ['usage' => $existing->payload['usage']] : []);
        $object = $existing?->withPayload($payload)->withLabel($artefact->label)
            ?? new ProtocolObject(
                ObjectKind::Mandate,
                $artefact->reference,
                $chainId,
                '',
                $context->source->value,
                $artefact->label,
                $payload,
                [],
                $context->pid,
                $context->beUser,
            );
        return $this->objects->save($object->withState($state, $note !== '' ? $note : 'Signed by the ' . strtolower($artefact->role->label())));
    }

    /**
     * Record a verifier's decision on a mandate it was shown.
     */
    public function decide(string $reference, Verdict $verdict, string $verifier): void
    {
        $object = $this->objects->find(ObjectKind::Mandate, $reference);
        if ($object === null) {
            return;
        }
        $state = $verdict->valid() ? self::STATE_VERIFIED : self::STATE_REJECTED;
        $note = $verdict->valid()
            ? 'Accepted by the ' . $verifier
            : 'Refused by the ' . $verifier . ': ' . ($verdict->error()->value ?? 'invalid');
        $this->objects->save(
            $object
                ->withPayload(['verification' => $verdict->toArray()] + $object->payload)
                ->withState($state, $note),
        );
    }

    /**
     * An open mandate authorised a purchase: count the use, so it cannot
     * authorise another (unless it allows repeat use).
     */
    public function consume(SdJwt $open, string $chainId, int $amount, int $at, RecordingContext $context): void
    {
        $reference = $open->reference();
        $object = $this->objects->find(ObjectKind::Mandate, $reference)
            ?? new ProtocolObject(
                ObjectKind::Mandate,
                $reference,
                $chainId,
                self::STATE_ISSUED,
                $context->source->value,
                'Open mandate presented by an agent',
                Artefact::mandate(DelegateChain::of($open), Role::TrustedSurface, 'Open mandate')->payload($chainId),
                [],
                $context->pid,
                $context->beUser,
            );
        $usage = is_array($object->payload['usage'] ?? null) ? $object->payload['usage'] : [];
        $uses = (is_int($usage['uses'] ?? null) ? $usage['uses'] : 0) + 1;
        $spent = (is_int($usage['amount'] ?? null) ? $usage['amount'] : 0) + $amount;
        $this->objects->save(
            $object
                ->withPayload(['usage' => ['uses' => $uses, 'amount' => $spent, 'lastUse' => $at]] + $object->payload)
                ->withState(self::STATE_VERIFIED, sprintf('Authorised purchase %d', $uses)),
        );
    }
}
