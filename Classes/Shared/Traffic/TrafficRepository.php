<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Shared\Traffic;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\SingletonInterface;

/**
 * Reads and writes tx_agentnexus_traffic.
 *
 * The table has no TCA: it is a log, not content. Nothing here is versioned,
 * translated or restricted by enable fields, so plain queries are correct.
 */
final class TrafficRepository implements SingletonInterface
{
    public const string TABLE = 'tx_agentnexus_traffic';

    /** Columns a list needs; the bodies stay out until the detail view. */
    private const array LIST_COLUMNS = [
        'uid', 'crdate', 'protocol', 'channel', 'method', 'endpoint', 'operation',
        'correlation_id', 'status_code', 'is_error', 'is_stream', 'duration_ms', 'event_count',
    ];

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    /**
     * @param array<string, int|string> $row
     */
    public function add(array $row): int
    {
        $row['pid'] = 0;
        $row['crdate'] = time();
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $connection->insert(self::TABLE, $row);
        return (int)$connection->lastInsertId();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findPage(TrafficFilter $filter, int $limit, int $offset): array
    {
        $queryBuilder = $this->listQuery($filter)
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        return array_values($queryBuilder->executeQuery()->fetchAllAssociative());
    }

    /**
     * The list query, newest first, for core's QueryBuilderPaginator.
     */
    public function listQuery(TrafficFilter $filter): QueryBuilder
    {
        return $this->query($filter)
            ->select(...self::LIST_COLUMNS)
            ->orderBy('uid', 'DESC');
    }

    public function count(TrafficFilter $filter): int
    {
        $queryBuilder = $this->query($filter);
        return (int)$queryBuilder->count('uid')->executeQuery()->fetchOne();
    }

    /**
     * Entries newer than the one the live view saw last, oldest first.
     *
     * @return list<array<string, mixed>>
     */
    public function findNewerThan(int $uid, TrafficFilter $filter, int $limit = 50): array
    {
        $queryBuilder = $this->query($filter);
        $queryBuilder
            ->select(...self::LIST_COLUMNS)
            ->andWhere($queryBuilder->expr()->gt('uid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)))
            ->orderBy('uid', 'ASC')
            ->setMaxResults($limit);

        return array_values($queryBuilder->executeQuery()->fetchAllAssociative());
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByUid(int $uid): ?array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $row = $queryBuilder
            ->select('*')
            ->from(self::TABLE)
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchAssociative();

        return is_array($row) ? $row : null;
    }

    /**
     * Every exchange that touched one protocol object, oldest first.
     *
     * @return list<array<string, mixed>>
     */
    public function findByCorrelation(string $correlationId, int $limit = 100): array
    {
        if ($correlationId === '') {
            return [];
        }
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder
            ->select(...self::LIST_COLUMNS)
            ->from(self::TABLE)
            ->where($queryBuilder->expr()->eq('correlation_id', $queryBuilder->createNamedParameter($correlationId)))
            ->orderBy('uid', 'ASC')
            ->setMaxResults($limit);

        return array_values($queryBuilder->executeQuery()->fetchAllAssociative());
    }

    public function latestUid(): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        return (int)$queryBuilder
            ->selectLiteral('MAX(uid)')
            ->from(self::TABLE)
            ->executeQuery()
            ->fetchOne();
    }

    /**
     * Exchanges per protocol since a timestamp — the overview's activity figures.
     *
     * @return array<string, int>
     */
    public function countByProtocolSince(int $since): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $rows = $queryBuilder
            ->select('protocol')
            ->addSelectLiteral('COUNT(*) AS ' . $queryBuilder->quoteIdentifier('exchanges'))
            ->from(self::TABLE)
            ->where($queryBuilder->expr()->gte('crdate', $queryBuilder->createNamedParameter($since, Connection::PARAM_INT)))
            ->groupBy('protocol')
            ->executeQuery()
            ->fetchAllAssociative();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(string)$row['protocol']] = (int)$row['exchanges'];
        }
        return $counts;
    }

    /** Delete entries created before the timestamp; returns how many went. */
    public function deleteOlderThan(int $timestamp): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        return (int)$queryBuilder
            ->delete(self::TABLE)
            ->where($queryBuilder->expr()->lt('crdate', $queryBuilder->createNamedParameter($timestamp, Connection::PARAM_INT)))
            ->executeStatement();
    }

    public function countOlderThan(int $timestamp): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        return (int)$queryBuilder
            ->count('uid')
            ->from(self::TABLE)
            ->where($queryBuilder->expr()->lt('crdate', $queryBuilder->createNamedParameter($timestamp, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchOne();
    }

    private function query(TrafficFilter $filter): QueryBuilder
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->from(self::TABLE);
        $expr = $queryBuilder->expr();

        if ($filter->protocol !== null) {
            $queryBuilder->andWhere($expr->eq('protocol', $queryBuilder->createNamedParameter($filter->protocol->value)));
        }
        if ($filter->channel !== null) {
            $queryBuilder->andWhere($expr->eq('channel', $queryBuilder->createNamedParameter($filter->channel->value)));
        }
        if ($filter->outcome === TrafficFilter::OUTCOME_ERROR) {
            $queryBuilder->andWhere($expr->eq('is_error', 1));
        } elseif ($filter->outcome === TrafficFilter::OUTCOME_OK) {
            $queryBuilder->andWhere($expr->eq('is_error', 0));
        }
        if ($filter->since > 0) {
            $queryBuilder->andWhere($expr->gte('crdate', $queryBuilder->createNamedParameter($filter->since, Connection::PARAM_INT)));
        }
        if ($filter->search !== '') {
            $like = '%' . $queryBuilder->escapeLikeWildcards($filter->search) . '%';
            $queryBuilder->andWhere($expr->or(
                $expr->like('operation', $queryBuilder->createNamedParameter($like)),
                $expr->like('correlation_id', $queryBuilder->createNamedParameter($like)),
                $expr->like('endpoint', $queryBuilder->createNamedParameter($like)),
            ));
        }
        return $queryBuilder;
    }
}
