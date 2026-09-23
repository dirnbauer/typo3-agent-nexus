<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Shared\Store;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\SingletonInterface;
use Webconsulting\AgentNexus\Shared\Protocol;

/**
 * Keeps protocol objects in tx_agentnexus_object, one row per object.
 *
 * This is protocol state, not just a log: A2A answers `GetTask`, `ListTasks`
 * and `CancelTask` from it and resumes a task that paused for input, UCP serves
 * `GET /checkout-sessions/{id}` from it, AG-UI checks that an approval answers
 * an interrupt it actually raised. The inspector module reads the same rows.
 *
 * The table has no TCA — protocol objects are not content — so plain queries
 * are correct: nothing here is versioned, translated or hidden.
 */
final class ObjectStore implements SingletonInterface
{
    public const string TABLE = 'tx_agentnexus_object';

    /** Payloads above this size are stored as a stub, so one object cannot fill the table. */
    private const int MAX_PAYLOAD_BYTES = 1048576;

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    /**
     * Insert or update by kind and object id; returns the stored object.
     */
    public function save(ProtocolObject $object): ProtocolObject
    {
        $now = time();
        $row = [
            'tstamp' => $now,
            'protocol' => $object->kind->protocol()->value,
            'kind' => $object->kind->value,
            'object_id' => mb_substr($object->objectId, 0, 128),
            'context_id' => mb_substr($object->contextId, 0, 128),
            'state' => mb_substr($object->state, 0, 32),
            'source' => mb_substr($object->source, 0, 16),
            'label' => mb_substr($object->label, 0, 255),
            'payload' => $this->encode($object->payload),
            'history' => $this->encode($object->history),
            'be_user' => $object->beUser,
        ];

        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $existing = $this->uidOf($object->kind, $object->objectId);
        if ($existing === null) {
            try {
                $connection->insert(self::TABLE, $row + ['pid' => $object->pid, 'crdate' => $now]);
                return $this->find($object->kind, $object->objectId) ?? $object;
            } catch (UniqueConstraintViolationException) {
                // Another request created it a moment ago; fall through to update.
                $existing = $this->uidOf($object->kind, $object->objectId);
            }
        }
        if ($existing !== null) {
            $connection->update(self::TABLE, $row, ['uid' => $existing]);
        }
        return $this->find($object->kind, $object->objectId) ?? $object;
    }

    public function find(ObjectKind $kind, string $objectId): ?ProtocolObject
    {
        if ($objectId === '') {
            return null;
        }
        $queryBuilder = $this->queryBuilder();
        $row = $queryBuilder
            ->select('*')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('kind', $queryBuilder->createNamedParameter($kind->value)),
                $queryBuilder->expr()->eq('object_id', $queryBuilder->createNamedParameter($objectId)),
            )
            ->executeQuery()
            ->fetchAssociative();

        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function findByUid(int $uid): ?ProtocolObject
    {
        $queryBuilder = $this->queryBuilder();
        $row = $queryBuilder
            ->select('*')
            ->from(self::TABLE)
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchAssociative();

        return is_array($row) ? $this->hydrate($row) : null;
    }

    /**
     * Newest first (by last change).
     *
     * @return list<ProtocolObject>
     */
    public function list(ObjectFilter $filter, int $limit = 50, int $offset = 0): array
    {
        $queryBuilder = $this->filtered($filter);
        $rows = $queryBuilder
            ->select('*')
            ->orderBy('tstamp', 'DESC')
            ->addOrderBy('uid', 'DESC')
            ->setMaxResults(max(1, $limit))
            ->setFirstResult(max(0, $offset))
            ->executeQuery()
            ->fetchAllAssociative();

        return array_values(array_map($this->hydrate(...), $rows));
    }

    public function count(ObjectFilter $filter): int
    {
        return (int)$this->filtered($filter)->count('uid')->executeQuery()->fetchOne();
    }

    /**
     * The list query, newest change first, for core's QueryBuilderPaginator;
     * turn its rows into objects with {@see hydrateRows()}.
     */
    public function listQuery(ObjectFilter $filter): QueryBuilder
    {
        return $this->filtered($filter)
            ->select('*')
            ->orderBy('tstamp', 'DESC')
            ->addOrderBy('uid', 'DESC');
    }

    /**
     * @param iterable<array<string, mixed>> $rows
     * @return list<ProtocolObject>
     */
    public function hydrateRows(iterable $rows): array
    {
        $objects = [];
        foreach ($rows as $row) {
            $objects[] = $this->hydrate($row);
        }
        return $objects;
    }

    /**
     * Distinct states of one kind, for the inspector's state filter.
     *
     * @return list<string>
     */
    public function states(ObjectKind $kind): array
    {
        $queryBuilder = $this->queryBuilder();
        $states = $queryBuilder
            ->select('state')
            ->from(self::TABLE)
            ->where($queryBuilder->expr()->eq('kind', $queryBuilder->createNamedParameter($kind->value)))
            ->groupBy('state')
            ->orderBy('state')
            ->executeQuery()
            ->fetchFirstColumn();

        return array_values(array_filter(array_map(strval(...), $states), static fn(string $state): bool => $state !== ''));
    }

    public function countSince(Protocol $protocol, int $since): int
    {
        $queryBuilder = $this->queryBuilder();
        return (int)$queryBuilder
            ->count('uid')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('protocol', $queryBuilder->createNamedParameter($protocol->value)),
                $queryBuilder->expr()->gte('tstamp', $queryBuilder->createNamedParameter($since, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchOne();
    }

    /** When the protocol last touched an object, or null when it never did. */
    public function lastActivity(Protocol $protocol): ?int
    {
        $queryBuilder = $this->queryBuilder();
        $last = $queryBuilder
            ->selectLiteral('MAX(' . $queryBuilder->quoteIdentifier('tstamp') . ')')
            ->from(self::TABLE)
            ->where($queryBuilder->expr()->eq('protocol', $queryBuilder->createNamedParameter($protocol->value)))
            ->executeQuery()
            ->fetchOne();

        return is_numeric($last) && (int)$last > 0 ? (int)$last : null;
    }

    /**
     * The most recently changed objects across all protocols.
     *
     * @return list<ProtocolObject>
     */
    public function recent(int $limit = 10): array
    {
        $rows = $this->queryBuilder()
            ->select('*')
            ->from(self::TABLE)
            ->orderBy('tstamp', 'DESC')
            ->addOrderBy('uid', 'DESC')
            ->setMaxResults($limit)
            ->executeQuery()
            ->fetchAllAssociative();

        return array_values(array_map($this->hydrate(...), $rows));
    }

    public function deleteOlderThan(int $timestamp): int
    {
        $queryBuilder = $this->queryBuilder();
        return (int)$queryBuilder
            ->delete(self::TABLE)
            ->where($queryBuilder->expr()->lt('tstamp', $queryBuilder->createNamedParameter($timestamp, Connection::PARAM_INT)))
            ->executeStatement();
    }

    public function countOlderThan(int $timestamp): int
    {
        $queryBuilder = $this->queryBuilder();
        return (int)$queryBuilder
            ->count('uid')
            ->from(self::TABLE)
            ->where($queryBuilder->expr()->lt('tstamp', $queryBuilder->createNamedParameter($timestamp, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchOne();
    }

    private function uidOf(ObjectKind $kind, string $objectId): ?int
    {
        $queryBuilder = $this->queryBuilder();
        $uid = $queryBuilder
            ->select('uid')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('kind', $queryBuilder->createNamedParameter($kind->value)),
                $queryBuilder->expr()->eq('object_id', $queryBuilder->createNamedParameter(mb_substr($objectId, 0, 128))),
            )
            ->executeQuery()
            ->fetchOne();

        return is_numeric($uid) ? (int)$uid : null;
    }

    private function filtered(ObjectFilter $filter): QueryBuilder
    {
        $queryBuilder = $this->queryBuilder();
        $expr = $queryBuilder->expr();
        $queryBuilder
            ->from(self::TABLE)
            ->where($expr->eq('kind', $queryBuilder->createNamedParameter($filter->kind->value)));

        if ($filter->state !== '') {
            $queryBuilder->andWhere($expr->eq('state', $queryBuilder->createNamedParameter($filter->state)));
        }
        if ($filter->source !== null) {
            $queryBuilder->andWhere($expr->eq('source', $queryBuilder->createNamedParameter($filter->source->value)));
        }
        if ($filter->contextId !== '') {
            $queryBuilder->andWhere($expr->eq('context_id', $queryBuilder->createNamedParameter($filter->contextId)));
        }
        if ($filter->updatedAfter > 0) {
            $queryBuilder->andWhere($expr->gt('tstamp', $queryBuilder->createNamedParameter($filter->updatedAfter, Connection::PARAM_INT)));
        }
        if ($filter->search !== '') {
            $like = '%' . $queryBuilder->escapeLikeWildcards($filter->search) . '%';
            $queryBuilder->andWhere($expr->or(
                $expr->like('object_id', $queryBuilder->createNamedParameter($like)),
                $expr->like('context_id', $queryBuilder->createNamedParameter($like)),
                $expr->like('label', $queryBuilder->createNamedParameter($like)),
            ));
        }
        return $queryBuilder;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): ProtocolObject
    {
        $kind = ObjectKind::tryFrom((string)($row['kind'] ?? '')) ?? ObjectKind::Task;

        return new ProtocolObject(
            $kind,
            (string)($row['object_id'] ?? ''),
            (string)($row['context_id'] ?? ''),
            (string)($row['state'] ?? ''),
            (string)($row['source'] ?? ''),
            (string)($row['label'] ?? ''),
            $this->decodeObject((string)($row['payload'] ?? '')),
            $this->decodeHistory((string)($row['history'] ?? '')),
            (int)($row['pid'] ?? 0),
            (int)($row['be_user'] ?? 0),
            (int)($row['uid'] ?? 0),
            (int)($row['crdate'] ?? 0),
            (int)($row['tstamp'] ?? 0),
        );
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private function encode(array $data): string
    {
        $json = (string)json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if (strlen($json) > self::MAX_PAYLOAD_BYTES) {
            return (string)json_encode(['_truncated' => true, 'bytes' => strlen($json)]);
        }
        return $json;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeObject(string $json): array
    {
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return [];
        }
        $object = [];
        foreach ($decoded as $key => $value) {
            $object[(string)$key] = $value;
        }
        return $object;
    }

    /**
     * @return list<array{state: string, at: int, note?: string}>
     */
    private function decodeHistory(string $json): array
    {
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return [];
        }
        $history = [];
        foreach ($decoded as $entry) {
            if (!is_array($entry) || !isset($entry['state'])) {
                continue;
            }
            $item = ['state' => (string)$entry['state'], 'at' => (int)($entry['at'] ?? 0)];
            if (isset($entry['note']) && is_string($entry['note'])) {
                $item['note'] = $entry['note'];
            }
            $history[] = $item;
        }
        return $history;
    }

    private function queryBuilder(): QueryBuilder
    {
        return $this->connectionPool->getQueryBuilderForTable(self::TABLE);
    }
}
