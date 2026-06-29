<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Ucp\Service;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\SingletonInterface;

/**
 * Persists one row per agent-driven checkout — the activity / audit record. Every
 * order is SIMULATED; this is bookkeeping, not a real ledger.
 */
final class OrderLogger implements SingletonInterface
{
    public const SOURCE_BACKEND = 'backend';
    public const SOURCE_FRONTEND = 'frontend';

    private const TABLE = 'tx_agentnexus_ucp_order_log';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    public function log(string $source, string $orderId, string $intent, string $finalState, int $itemCount, int $totalCents, int $eventCount, int $beUser = 0): void
    {
        $this->connectionPool->getConnectionForTable(self::TABLE)->insert(self::TABLE, [
            'pid' => 0,
            'crdate' => time(),
            'request_date' => (int)strtotime('today'),
            'source' => $source,
            'be_user' => $beUser,
            'order_id' => mb_substr($orderId, 0, 64),
            'intent' => mb_substr($intent, 0, 64),
            'final_state' => mb_substr($finalState, 0, 32),
            'item_count' => $itemCount,
            'total_cents' => $totalCents,
            'event_count' => $eventCount,
        ]);
    }

    /**
     * @return array{orders: int, confirmed: int, volume: int}
     */
    public function getTodayTotals(): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $row = $qb
            ->addSelectLiteral('COUNT(*) AS orders')
            ->addSelectLiteral("COALESCE(SUM(CASE WHEN final_state = 'confirmed' THEN 1 ELSE 0 END), 0) AS confirmed")
            ->addSelectLiteral("COALESCE(SUM(CASE WHEN final_state = 'confirmed' THEN total_cents ELSE 0 END), 0) AS volume")
            ->from(self::TABLE)
            ->where($qb->expr()->gte('request_date', $qb->createNamedParameter((int)strtotime('today'), Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchAssociative();
        $row = is_array($row) ? $row : [];
        return [
            'orders' => (int)($row['orders'] ?? 0),
            'confirmed' => (int)($row['confirmed'] ?? 0),
            'volume' => (int)($row['volume'] ?? 0),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getRecent(int $limit = 8): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        return $qb
            ->select('crdate', 'source', 'intent', 'final_state', 'item_count', 'total_cents')
            ->from(self::TABLE)
            ->orderBy('uid', 'DESC')
            ->setMaxResults($limit)
            ->executeQuery()
            ->fetchAllAssociative();
    }
}
