<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Shared\Store;

use Webconsulting\AgentNexus\Shared\Traffic\Channel;

/**
 * What the inspector lists: one kind of object, optionally narrowed by state,
 * source, context and a search over ids and labels.
 */
final readonly class ObjectFilter
{
    public function __construct(
        public ObjectKind $kind,
        public string $state = '',
        public ?Channel $source = null,
        public string $contextId = '',
        public string $search = '',
        public int $updatedAfter = 0,
    ) {}

    /**
     * @param array<array-key, mixed> $parameters
     */
    public static function fromArray(ObjectKind $kind, array $parameters): self
    {
        $state = is_string($parameters['state'] ?? null) ? mb_substr(trim($parameters['state']), 0, 32) : '';
        $source = is_string($parameters['source'] ?? null) ? Channel::tryFrom($parameters['source']) : null;
        $context = is_string($parameters['context'] ?? null) ? mb_substr(trim($parameters['context']), 0, 128) : '';
        $search = is_string($parameters['search'] ?? null) ? mb_substr(trim($parameters['search']), 0, 100) : '';

        return new self($kind, $state, $source, $context, $search);
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return array_filter([
            'state' => $this->state,
            'source' => $this->source->value ?? '',
            'context' => $this->contextId,
            'search' => $this->search,
        ], static fn(string $value): bool => $value !== '');
    }
}
