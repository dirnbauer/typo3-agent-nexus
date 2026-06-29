<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\A2a\Service;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\SingletonInterface;

/**
 * Persists one row per delegated task — the A2A activity / audit record: which
 * skill, how the task ended, how many frames streamed and how many artifacts it
 * produced.
 */
final class TaskLogger implements SingletonInterface
{
    public const SOURCE_BACKEND = 'backend';
    public const SOURCE_FRONTEND = 'frontend';
    public const SOURCE_RPC = 'rpc';

    private const TABLE = 'tx_agentnexus_a2a_task_log';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    public function log(string $source, string $taskId, string $contextId, string $skill, string $finalState, int $eventCount, int $artifactCount, int $beUser = 0): void
    {
        $this->connectionPool->getConnectionForTable(self::TABLE)->insert(self::TABLE, [
            'pid' => 0,
            'crdate' => time(),
            'request_date' => (int)strtotime('today'),
            'source' => $source,
            'be_user' => $beUser,
            'task_id' => mb_substr($taskId, 0, 64),
            'context_id' => mb_substr($contextId, 0, 64),
            'skill' => mb_substr($skill, 0, 64),
            'final_state' => mb_substr($finalState, 0, 32),
            'event_count' => $eventCount,
            'artifact_count' => $artifactCount,
        ]);
    }

    /**
     * @return array{tasks: int, completed: int, artifacts: int}
     */
    public function getTodayTotals(): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $row = $qb
            ->addSelectLiteral('COUNT(*) AS tasks')
            ->addSelectLiteral("COALESCE(SUM(CASE WHEN final_state = 'completed' THEN 1 ELSE 0 END), 0) AS completed")
            ->addSelectLiteral('COALESCE(SUM(artifact_count), 0) AS artifacts')
            ->from(self::TABLE)
            ->where($qb->expr()->gte('request_date', $qb->createNamedParameter((int)strtotime('today'), Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchAssociative();
        $row = is_array($row) ? $row : [];
        return [
            'tasks' => (int)($row['tasks'] ?? 0),
            'completed' => (int)($row['completed'] ?? 0),
            'artifacts' => (int)($row['artifacts'] ?? 0),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getRecent(int $limit = 8): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        return $qb
            ->select('crdate', 'source', 'skill', 'final_state', 'event_count', 'artifact_count')
            ->from(self::TABLE)
            ->orderBy('uid', 'DESC')
            ->setMaxResults($limit)
            ->executeQuery()
            ->fetchAllAssociative();
    }
}
