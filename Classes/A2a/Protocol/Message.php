<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\A2a\Protocol;

/**
 * One unit of communication between client and agent.
 *
 * Required: `messageId`, `role` and at least one part. A client message may
 * name the task it continues (`taskId`) and its context; an agent message
 * always names its context, and its task once one exists.
 */
final readonly class Message
{
    /**
     * @param non-empty-list<Part> $parts
     * @param array<string, mixed> $metadata
     * @param list<string> $extensions
     * @param list<string> $referenceTaskIds
     */
    public function __construct(
        public string $messageId,
        public Role $role,
        public array $parts,
        public string $contextId = '',
        public string $taskId = '',
        public array $metadata = [],
        public array $extensions = [],
        public array $referenceTaskIds = [],
    ) {}

    /** A plain-text message from this agent. */
    public static function fromAgent(string $text, string $contextId, string $taskId): self
    {
        return new self(Ids::uuid(), Role::Agent, [Part::text($text)], $contextId, $taskId);
    }

    public static function fromArray(mixed $json, string $path = 'message'): self
    {
        $message = Json::object($json, $path);

        $roleValue = Json::requiredString($message, 'role', $path);
        $role = Role::tryFrom($roleValue);
        if ($role === null) {
            $hint = Role::fromLegacy($roleValue) !== null ? ' A2A 1.0 spells it ROLE_USER or ROLE_AGENT.' : '';
            throw A2aException::invalidParams($path . '.role', 'must be ROLE_USER or ROLE_AGENT.' . $hint);
        }

        $rawParts = $message['parts'] ?? null;
        if (!is_array($rawParts) || $rawParts === [] || !array_is_list($rawParts)) {
            throw A2aException::invalidParams($path . '.parts', 'must be a list with at least one part.');
        }
        $parts = [];
        foreach ($rawParts as $index => $rawPart) {
            $parts[] = Part::fromArray($rawPart, $path . '.parts[' . $index . ']');
        }

        return new self(
            Json::requiredString($message, 'messageId', $path),
            $role,
            $parts,
            Json::string($message, 'contextId', $path),
            Json::string($message, 'taskId', $path),
            Json::struct($message, 'metadata', $path),
            Json::stringList($message, 'extensions', $path),
            Json::stringList($message, 'referenceTaskIds', $path),
        );
    }

    /** The same message filed under a task and its context. */
    public function inTask(string $contextId, string $taskId): self
    {
        return new self(
            $this->messageId,
            $this->role,
            $this->parts,
            $contextId,
            $taskId,
            $this->metadata,
            $this->extensions,
            $this->referenceTaskIds,
        );
    }

    /** All text parts, joined by a space. */
    public function text(): string
    {
        $texts = array_filter(array_map(static fn(Part $part): string => trim($part->textContent()), $this->parts), static fn(string $text): bool => $text !== '');
        return implode(' ', $texts);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $json = [
            'messageId' => $this->messageId,
            'role' => $this->role->value,
            'parts' => array_map(static fn(Part $part): array => $part->toArray(), $this->parts),
        ];
        if ($this->contextId !== '') {
            $json['contextId'] = $this->contextId;
        }
        if ($this->taskId !== '') {
            $json['taskId'] = $this->taskId;
        }
        if ($this->metadata !== []) {
            $json['metadata'] = $this->metadata;
        }
        if ($this->extensions !== []) {
            $json['extensions'] = $this->extensions;
        }
        if ($this->referenceTaskIds !== []) {
            $json['referenceTaskIds'] = $this->referenceTaskIds;
        }
        return $json;
    }
}
