<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Agui\Service;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\SingletonInterface;

/**
 * Persists one row per agent run — the activity / compliance record: how many
 * events streamed, whether the human approved, and the outcome.
 */
final class RunLogger implements SingletonInterface
{
    public const SOURCE_BACKEND = 'backend';
    public const SOURCE_FRONTEND = 'frontend';

    private const TABLE = 'tx_agentnexus_agui_run_log';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    public function log(string $source, string $threadId, string $runId, string $preset, int $eventCount, bool $approved, string $outcome, int $beUser = 0): void
    {
        $this->connectionPool->getConnectionForTable(self::TABLE)->insert(self::TABLE, [
            'pid' => 0,
            'crdate' => time(),
            'request_date' => (int)strtotime('today'),
            'source' => $source,
            'be_user' => $beUser,
            'thread_id' => mb_substr($threadId, 0, 64),
            'run_id' => mb_substr($runId, 0, 64),
            'preset' => mb_substr($preset, 0, 64),
            'event_count' => $eventCount,
            'approved' => $approved ? 1 : 0,
            'outcome' => mb_substr($outcome, 0, 32),
        ]);
    }

    /**
     * @return array{runs: int, approvals: int, events: int}
     */
    public function getTodayTotals(): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $row = $qb
            ->addSelectLiteral('COUNT(*) AS runs')
            ->addSelectLiteral('COALESCE(SUM(approved), 0) AS approvals')
            ->addSelectLiteral('COALESCE(SUM(event_count), 0) AS events')
            ->from(self::TABLE)
            ->where($qb->expr()->gte('request_date', $qb->createNamedParameter((int)strtotime('today'), Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchAssociative();
        $row = is_array($row) ? $row : [];
        return [
            'runs' => (int)($row['runs'] ?? 0),
            'approvals' => (int)($row['approvals'] ?? 0),
            'events' => (int)($row['events'] ?? 0),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getRecent(int $limit = 8): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        return $qb
            ->select('crdate', 'source', 'preset', 'event_count', 'approved', 'outcome')
            ->from(self::TABLE)
            ->orderBy('uid', 'DESC')
            ->setMaxResults($limit)
            ->executeQuery()
            ->fetchAllAssociative();
    }
}
