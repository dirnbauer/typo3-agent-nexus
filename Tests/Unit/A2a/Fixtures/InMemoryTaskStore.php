<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Tests\Unit\A2a\Fixtures;

use Webconsulting\AgentNexus\A2a\Protocol\A2aException;
use Webconsulting\AgentNexus\A2a\Protocol\Timestamp;
use Webconsulting\AgentNexus\A2a\Server\StoredTask;
use Webconsulting\AgentNexus\A2a\Server\TaskListQuery;
use Webconsulting\AgentNexus\A2a\Server\TaskPage;
use Webconsulting\AgentNexus\A2a\Server\TaskStore;
use Webconsulting\AgentNexus\Shared\Traffic\Channel;

/**
 * The task store in memory, with the same listing rules as the object store:
 * newest change first, keyset pages, widget tasks only by context.
 */
final class InMemoryTaskStore implements TaskStore
{
    /** @var array<string, StoredTask> */
    public array $tasks = [];

    /** @var list<array{taskId: string, state: string, note: string}> */
    public array $saves = [];

    /**
     * Called before every find(), so a test can play another request that
     * changes the task while a subscriber watches it.
     *
     * @var (\Closure(string): void)|null
     */
    public ?\Closure $beforeFind = null;

    /** @var array<string, int> task id => sequence number of its last save */
    private array $changed = [];

    private int $sequence = 0;

    #[\Override]
    public function find(string $taskId): ?StoredTask
    {
        if ($this->beforeFind !== null) {
            ($this->beforeFind)($taskId);
        }
        return $this->tasks[$taskId] ?? null;
    }

    #[\Override]
    public function save(StoredTask $task, string $note = ''): StoredTask
    {
        $this->tasks[$task->task->id] = $task;
        $this->changed[$task->task->id] = ++$this->sequence;
        $this->saves[] = ['taskId' => $task->task->id, 'state' => $task->task->state()->value, 'note' => $note];
        return $task;
    }

    #[\Override]
    public function list(TaskListQuery $query): TaskPage
    {
        $matching = array_filter($this->tasks, static function (StoredTask $stored) use ($query): bool {
            if ($query->contextId !== '' && $stored->task->contextId !== $query->contextId) {
                return false;
            }
            if (!$query->includesWidgetTasks() && $stored->source === Channel::Widget) {
                return false;
            }
            if ($query->state !== null && $stored->task->state() !== $query->state) {
                return false;
            }
            if ($query->statusTimestampAfter !== null) {
                $recorded = Timestamp::parse($stored->task->status->timestamp);
                return $recorded !== null && $recorded->getTimestamp() >= $query->statusTimestampAfter->getTimestamp();
            }
            return true;
        });
        uksort($matching, fn(string $a, string $b): int => $this->changed[$b] <=> $this->changed[$a]);

        $after = PHP_INT_MAX;
        if ($query->pageToken !== '') {
            if (preg_match('/^s(\d+)$/', $query->pageToken, $matches) !== 1) {
                throw A2aException::invalidParams('pageToken', 'is not a page token this agent issued.');
            }
            $after = (int)$matches[1];
        }
        $page = array_values(array_filter($matching, fn(StoredTask $stored): bool => $this->changed[$stored->task->id] < $after));
        $hasMore = count($page) > $query->pageSize;
        $page = array_slice($page, 0, $query->pageSize);
        $last = $page === [] ? null : $page[array_key_last($page)];

        return new TaskPage(
            array_map(static fn(StoredTask $stored) => $stored->task, $page),
            count($matching),
            $hasMore && $last !== null ? 's' . $this->changed[$last->task->id] : '',
        );
    }
}
