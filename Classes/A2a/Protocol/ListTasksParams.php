<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\A2a\Protocol;

/**
 * A ListTasksRequest, read and checked: filters, the page and how much of each
 * task to return.
 */
final readonly class ListTasksParams
{
    public const int DEFAULT_PAGE_SIZE = 50;
    public const int MAX_PAGE_SIZE = 100;

    public function __construct(
        public string $contextId = '',
        public ?TaskState $status = null,
        public int $pageSize = self::DEFAULT_PAGE_SIZE,
        public string $pageToken = '',
        public ?int $historyLength = null,
        public ?\DateTimeImmutable $statusTimestampAfter = null,
        public bool $includeArtifacts = false,
    ) {}

    /**
     * @param array<string, mixed> $params the JSON-RPC params or the HTTP+JSON query
     */
    public static function fromArray(array $params): self
    {
        $statusValue = Json::string($params, 'status', '');
        $status = null;
        if ($statusValue !== '' && $statusValue !== 'TASK_STATE_UNSPECIFIED') {
            $status = TaskState::tryFrom($statusValue) ?? throw A2aException::invalidParams(
                'status',
                'must be one of ' . implode(', ', array_map(static fn(TaskState $state): string => $state->value, TaskState::cases())) . '.',
            );
        }

        $pageSize = Json::int($params, 'pageSize', '') ?? self::DEFAULT_PAGE_SIZE;
        if ($pageSize < 1 || $pageSize > self::MAX_PAGE_SIZE) {
            throw A2aException::invalidParams('pageSize', sprintf('must be between 1 and %d.', self::MAX_PAGE_SIZE));
        }

        $historyLength = Json::int($params, 'historyLength', '');
        if ($historyLength !== null && $historyLength < 0) {
            throw A2aException::invalidParams('historyLength', 'must be 0 or more.');
        }

        $after = null;
        $afterValue = Json::string($params, 'statusTimestampAfter', '');
        if ($afterValue !== '') {
            $after = Timestamp::parse($afterValue)
                ?? throw A2aException::invalidParams('statusTimestampAfter', 'must be an ISO 8601 timestamp such as 2026-09-23T10:00:00.000Z.');
        }

        return new self(
            Json::string($params, 'contextId', ''),
            $status,
            $pageSize,
            Json::string($params, 'pageToken', ''),
            $historyLength,
            $after,
            Json::bool($params, 'includeArtifacts', '') ?? false,
        );
    }
}
