<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\A2a\Server;

use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use TYPO3\CMS\Core\Database\Connection;
use Webconsulting\AgentNexus\A2a\Protocol\A2aException;
use Webconsulting\AgentNexus\A2a\Protocol\Task;
use Webconsulting\AgentNexus\Shared\Store\ObjectFilter;
use Webconsulting\AgentNexus\Shared\Store\ObjectKind;
use Webconsulting\AgentNexus\Shared\Store\ObjectStore;
use Webconsulting\AgentNexus\Shared\Store\ProtocolObject;
use Webconsulting\AgentNexus\Shared\Traffic\Channel;

/**
 * Tasks in the shared object store: the payload is the A2A 1.0 Task JSON, the
 * state column the TaskState, the history column every state the task passed
 * through, the label the skill's name.
 *
 * Listing uses keyset pagination on (last change, uid) — a page token names
 * the last task of the previous page — so tasks that change while a client
 * pages through the list do not shift the pages under it. The last change is
 * the time the task's status was last recorded, to the second: the server
 * saves a task whenever its status changes, and finishes every turn with a
 * status change.
 */
#[AsAlias(TaskStore::class)]
final readonly class ObjectStoreTaskStore implements TaskStore
{
    public function __construct(
        private ObjectStore $objects,
    ) {}

    #[\Override]
    public function find(string $taskId): ?StoredTask
    {
        $object = $this->objects->find(ObjectKind::Task, $taskId);
        if ($object === null) {
            return null;
        }
        $task = $this->taskOf($object);
        if ($task === null) {
            return null;
        }
        return new StoredTask($task, Channel::tryFrom($object->source) ?? Channel::Api, $object->pid, $object->label);
    }

    #[\Override]
    public function save(StoredTask $task, string $note = ''): StoredTask
    {
        $object = $this->objects->find(ObjectKind::Task, $task->task->id)
            ?? new ProtocolObject(
                ObjectKind::Task,
                $task->task->id,
                $task->task->contextId,
                '',
                $task->source->value,
                $task->label,
                [],
                [],
                $task->pid,
            );
        $this->objects->save(
            $object
                ->withState($task->task->state()->value, $note)
                ->withPayload($task->task->toArray())
                ->withLabel($task->label),
        );
        return $task;
    }

    #[\Override]
    public function list(TaskListQuery $query): TaskPage
    {
        $after = $query->statusTimestampAfter?->getTimestamp();
        $queryBuilder = $this->objects->listQuery(new ObjectFilter(
            ObjectKind::Task,
            $query->state->value ?? '',
            null,
            $query->contextId,
            '',
            // "at or after" at second precision; the filter compares with ">".
            $after !== null ? $after - 1 : 0,
        ));
        $expr = $queryBuilder->expr();
        if (!$query->includesWidgetTasks()) {
            $queryBuilder->andWhere($expr->neq('source', $queryBuilder->createNamedParameter(Channel::Widget->value)));
        }

        $totalSize = (int)(clone $queryBuilder)->resetOrderBy()->count('uid')->executeQuery()->fetchOne();

        if ($query->pageToken !== '') {
            [$tstamp, $uid] = $this->decodeToken($query->pageToken);
            $queryBuilder->andWhere($expr->or(
                $expr->lt('tstamp', $queryBuilder->createNamedParameter($tstamp, Connection::PARAM_INT)),
                $expr->and(
                    $expr->eq('tstamp', $queryBuilder->createNamedParameter($tstamp, Connection::PARAM_INT)),
                    $expr->lt('uid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)),
                ),
            ));
        }

        $rows = $queryBuilder->setMaxResults($query->pageSize + 1)->executeQuery()->fetchAllAssociative();
        $hasMore = count($rows) > $query->pageSize;
        $rows = array_slice($rows, 0, $query->pageSize);

        $tasks = [];
        $last = null;
        foreach ($this->objects->hydrateRows($rows) as $object) {
            $last = $object;
            $task = $this->taskOf($object);
            if ($task !== null) {
                $tasks[] = $task;
            }
        }

        return new TaskPage(
            $tasks,
            $totalSize,
            $hasMore && $last !== null ? $this->encodeToken($last->tstamp, $last->uid) : '',
        );
    }

    private function taskOf(ProtocolObject $object): ?Task
    {
        try {
            return Task::fromArray($object->payload);
        } catch (A2aException) {
            // A payload that no longer reads as a task (cut short by the
            // store's size cap, edited by hand) is treated as gone.
            return null;
        }
    }

    private function encodeToken(int $tstamp, int $uid): string
    {
        return rtrim(strtr(base64_encode('t:' . $tstamp . ':' . $uid), '+/', '-_'), '=');
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function decodeToken(string $token): array
    {
        $decoded = base64_decode(strtr($token, '-_', '+/'), true);
        if (!is_string($decoded) || preg_match('/^t:(\d{1,10}):(\d{1,10})$/', $decoded, $matches) !== 1) {
            throw A2aException::invalidParams('pageToken', 'is not a page token this agent issued. Start again without one.');
        }
        return [(int)$matches[1], (int)$matches[2]];
    }
}
