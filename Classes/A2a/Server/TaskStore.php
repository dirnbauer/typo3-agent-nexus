<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\A2a\Server;

use Webconsulting\AgentNexus\A2a\Protocol\A2aException;

/**
 * Where A2A tasks live between requests.
 *
 * The one implementation keeps them in the shared object store
 * ({@see ObjectStoreTaskStore}); the interface lets the operations be tested
 * without a database.
 */
interface TaskStore
{
    public function find(string $taskId): ?StoredTask;

    /**
     * Insert or update; `$note` explains a state change in the task's state
     * history.
     */
    public function save(StoredTask $task, string $note = ''): StoredTask;

    /**
     * @throws A2aException InvalidParams when the page token is not one this store issued
     */
    public function list(TaskListQuery $query): TaskPage;
}
