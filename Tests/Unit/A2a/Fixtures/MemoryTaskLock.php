<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Tests\Unit\A2a\Fixtures;

use Webconsulting\AgentNexus\A2a\Server\TaskLock;

/**
 * Task locks within one process, so a test can hold one and see the server
 * refuse to work on the same task twice.
 */
final class MemoryTaskLock implements TaskLock
{
    /** @var array<string, true> */
    public array $held = [];

    #[\Override]
    public function acquire(string $taskId): ?\Closure
    {
        if (isset($this->held[$taskId])) {
            return null;
        }
        $this->held[$taskId] = true;
        return function () use ($taskId): void {
            unset($this->held[$taskId]);
        };
    }
}
