<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Ap2\Service;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\SingletonInterface;

/**
 * Persists one row per mint / verify action — the AP2 activity / audit record.
 * Every mandate is sandbox-signed; this is bookkeeping, not a payment ledger.
 */
final class MandateLog implements SingletonInterface
{
    public const SOURCE_BACKEND = 'backend';
    public const SOURCE_FRONTEND = 'frontend';

    private const TABLE = 'tx_agentnexus_ap2_mandate_log';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    public function log(string $source, string $action, string $mandateType, bool $authorized, int $totalCents, int $beUser = 0): void
    {
        $this->connectionPool->getConnectionForTable(self::TABLE)->insert(self::TABLE, [
            'pid' => 0,
            'crdate' => time(),
            'request_date' => (int)strtotime('today'),
            'source' => $source,
            'be_user' => $beUser,
            'action' => mb_substr($action, 0, 24),
            'mandate_type' => mb_substr($mandateType, 0, 24),
            'authorized' => $authorized ? 1 : 0,
            'total_cents' => $totalCents,
        ]);
    }

    /**
     * @return array{minted: int, verified: int, authorized: int}
     */
    public function getTodayTotals(): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $row = $qb
            ->addSelectLiteral("COALESCE(SUM(CASE WHEN action = 'mint' THEN 1 ELSE 0 END), 0) AS minted")
            ->addSelectLiteral("COALESCE(SUM(CASE WHEN action = 'verify' THEN 1 ELSE 0 END), 0) AS verified")
            ->addSelectLiteral('COALESCE(SUM(authorized), 0) AS authorized')
            ->from(self::TABLE)
            ->where($qb->expr()->gte('request_date', $qb->createNamedParameter((int)strtotime('today'), Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchAssociative();
        $row = is_array($row) ? $row : [];
        return [
            'minted' => (int)($row['minted'] ?? 0),
            'verified' => (int)($row['verified'] ?? 0),
            'authorized' => (int)($row['authorized'] ?? 0),
        ];
    }
}
