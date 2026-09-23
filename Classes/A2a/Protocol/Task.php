<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\A2a\Protocol;

/**
 * The core unit of A2A work: an id, the context it belongs to, its current
 * status, the artifacts it produced and the messages exchanged along the way.
 *
 * {@see toArray()} renders the A2A 1.0 JSON shape and is the only place that
 * decides what a task looks like on the wire; the object store keeps exactly
 * that JSON as the task's payload.
 */
final readonly class Task
{
    /**
     * @param list<Artifact> $artifacts
     * @param list<Message> $history oldest first
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $id,
        public string $contextId,
        public TaskStatus $status,
        public array $artifacts = [],
        public array $history = [],
        public array $metadata = [],
    ) {}

    /**
     * Read a task back from its stored JSON.
     *
     * @param array<string, mixed> $json
     */
    public static function fromArray(array $json): self
    {
        $artifacts = [];
        foreach (is_array($json['artifacts'] ?? null) ? $json['artifacts'] : [] as $index => $artifact) {
            $artifacts[] = Artifact::fromArray($artifact, 'task.artifacts[' . $index . ']');
        }
        $history = [];
        foreach (is_array($json['history'] ?? null) ? $json['history'] : [] as $index => $message) {
            $history[] = Message::fromArray($message, 'task.history[' . $index . ']');
        }

        return new self(
            Json::requiredString($json, 'id', 'task'),
            Json::string($json, 'contextId', 'task'),
            TaskStatus::fromArray($json['status'] ?? null, 'task.status'),
            $artifacts,
            $history,
            Json::struct($json, 'metadata', 'task'),
        );
    }

    public function state(): TaskState
    {
        return $this->status->state;
    }

    public function withStatus(TaskStatus $status): self
    {
        return new self($this->id, $this->contextId, $status, $this->artifacts, $this->history, $this->metadata);
    }

    public function withMessage(Message $message): self
    {
        return new self($this->id, $this->contextId, $this->status, $this->artifacts, [...$this->history, $message], $this->metadata);
    }

    /** Add an artifact, or replace the one with the same id. */
    public function withArtifact(Artifact $artifact): self
    {
        $artifacts = array_values(array_filter(
            $this->artifacts,
            static fn(Artifact $existing): bool => $existing->artifactId !== $artifact->artifactId,
        ));
        $artifacts[] = $artifact;
        return new self($this->id, $this->contextId, $this->status, $artifacts, $this->history, $this->metadata);
    }

    /**
     * @param array<string, mixed> $metadata merged over the existing metadata
     */
    public function withMetadata(array $metadata): self
    {
        return new self($this->id, $this->contextId, $this->status, $this->artifacts, $this->history, array_replace($this->metadata, $metadata));
    }

    /** The newest message in the history, or null for an empty history. */
    public function lastMessage(): ?Message
    {
        return $this->history === [] ? null : $this->history[array_key_last($this->history)];
    }

    /** The text of the message that started the task. */
    public function request(): string
    {
        foreach ($this->history as $message) {
            if ($message->role === Role::User) {
                return $message->text();
            }
        }
        return '';
    }

    /** A string metadata value this server wrote, such as the skill id. */
    public function metadataString(string $key): string
    {
        $value = $this->metadata[$key] ?? null;
        return is_string($value) ? $value : '';
    }

    /**
     * The A2A 1.0 Task JSON.
     *
     * @param int|null $historyLength null = the whole history, 0 = none,
     *                                n = the n most recent messages
     * @param bool|null $artifacts    null = when there are any, true = always
     *                                (an empty list included), false = never
     * @return array<string, mixed>
     */
    public function toArray(?int $historyLength = null, ?bool $artifacts = null): array
    {
        $json = [
            'id' => $this->id,
            'contextId' => $this->contextId,
            'status' => $this->status->toArray(),
        ];
        if ($artifacts === true || ($artifacts === null && $this->artifacts !== [])) {
            $json['artifacts'] = array_map(static fn(Artifact $artifact): array => $artifact->toArray(), $this->artifacts);
        }
        $history = match (true) {
            $historyLength === null => $this->history,
            $historyLength <= 0 => [],
            default => array_slice($this->history, -$historyLength),
        };
        if ($history !== []) {
            $json['history'] = array_map(static fn(Message $message): array => $message->toArray(), $history);
        }
        if ($this->metadata !== []) {
            $json['metadata'] = $this->metadata;
        }
        return $json;
    }
}
