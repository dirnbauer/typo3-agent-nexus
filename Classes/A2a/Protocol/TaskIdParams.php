<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\A2a\Protocol;

/**
 * The parameters of the operations that address one task: GetTask (with an
 * optional `historyLength`), CancelTask (with optional `metadata`) and
 * SubscribeToTask.
 */
final readonly class TaskIdParams
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $id,
        public ?int $historyLength = null,
        public array $metadata = [],
    ) {}

    /**
     * @param array<string, mixed> $params
     */
    public static function fromArray(array $params): self
    {
        $historyLength = Json::int($params, 'historyLength', '');
        if ($historyLength !== null && $historyLength < 0) {
            throw A2aException::invalidParams('historyLength', 'must be 0 or more.');
        }

        return new self(
            Json::requiredString($params, 'id', ''),
            $historyLength,
            Json::struct($params, 'metadata', ''),
        );
    }
}
