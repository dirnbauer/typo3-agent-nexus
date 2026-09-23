<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\A2a\Server;

use Webconsulting\AgentNexus\A2a\Protocol\TaskState;

/**
 * What a ListTasks call selects.
 *
 * Tasks a visitor started in the concierge widget hold what that visitor
 * typed; they are listed only to a client that names their context — the
 * widget itself — never in an unfiltered list.
 */
final readonly class TaskListQuery
{
    public function __construct(
        public string $contextId = '',
        public ?TaskState $state = null,
        public int $pageSize = 50,
        public string $pageToken = '',
        public ?\DateTimeImmutable $statusTimestampAfter = null,
    ) {}

    public function includesWidgetTasks(): bool
    {
        return $this->contextId !== '';
    }
}
