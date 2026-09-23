<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\A2a\Server;

/**
 * Makes sure one task is worked on by one request at a time: two answers to
 * the same question, or two clients polling a queued task, must not run the
 * skill twice.
 */
interface TaskLock
{
    /**
     * Take the task's lock without waiting.
     *
     * @return (\Closure(): void)|null the release function, or null when
     *                                 another request holds the lock
     */
    public function acquire(string $taskId): ?\Closure;
}
