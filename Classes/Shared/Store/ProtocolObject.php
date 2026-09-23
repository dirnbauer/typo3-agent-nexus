<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Shared\Store;

/**
 * One protocol object as the protocol itself describes it — an A2A Task, a UCP
 * checkout session, an AP2 mandate — plus the handful of indexed fields the
 * inspector lists and filters by.
 *
 * `payload` holds the object in its specification's own JSON shape, so what
 * the inspector shows is exactly what went over the wire. `history` records
 * every state the object passed through, oldest first.
 */
final readonly class ProtocolObject
{
    /**
     * @param string $objectId  the protocol's own id (task id, run id, checkout id, mandate id)
     * @param string $contextId what groups objects: an A2A context, an AG-UI thread, an AP2 chain
     * @param string $source    a {@see \Webconsulting\AgentNexus\Shared\Traffic\Channel} value
     * @param string $label     one line for lists: a skill, an intent, a cart total
     * @param array<string, mixed> $payload
     * @param list<array{state: string, at: int, note?: string}> $history
     */
    public function __construct(
        public ObjectKind $kind,
        public string $objectId,
        public string $contextId = '',
        public string $state = '',
        public string $source = '',
        public string $label = '',
        public array $payload = [],
        public array $history = [],
        public int $pid = 0,
        public int $beUser = 0,
        public int $uid = 0,
        public int $crdate = 0,
        public int $tstamp = 0,
    ) {}

    /**
     * The same object in a new state; the transition is appended to the history
     * unless the state did not change.
     */
    public function withState(string $state, string $note = '', ?int $at = null): self
    {
        $history = $this->history;
        $last = $history === [] ? null : $history[array_key_last($history)];
        if ($last === null || $last['state'] !== $state) {
            $entry = ['state' => $state, 'at' => $at ?? time()];
            if ($note !== '') {
                $entry['note'] = mb_substr($note, 0, 500);
            }
            $history[] = $entry;
        }
        return $this->with(state: $state, history: $history);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function withPayload(array $payload): self
    {
        return $this->with(payload: $payload);
    }

    public function withLabel(string $label): self
    {
        return $this->with(label: $label);
    }

    /**
     * @param array<string, mixed>|null $payload
     * @param list<array{state: string, at: int, note?: string}>|null $history
     */
    private function with(
        ?string $state = null,
        ?string $label = null,
        ?array $payload = null,
        ?array $history = null,
    ): self {
        return new self(
            $this->kind,
            $this->objectId,
            $this->contextId,
            $state ?? $this->state,
            $this->source,
            $label ?? $this->label,
            $payload ?? $this->payload,
            $history ?? $this->history,
            $this->pid,
            $this->beUser,
            $this->uid,
            $this->crdate,
            $this->tstamp,
        );
    }
}
