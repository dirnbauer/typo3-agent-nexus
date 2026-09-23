<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\A2a\Server;

use Webconsulting\AgentNexus\A2a\Protocol\Task;

/**
 * One page of a task listing, newest status first.
 */
final readonly class TaskPage
{
    /**
     * @param list<Task> $tasks
     * @param string $nextPageToken '' on the last page
     */
    public function __construct(
        public array $tasks,
        public int $totalSize,
        public string $nextPageToken = '',
    ) {}
}
