<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Shared\Llm;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\SingletonInterface;

/**
 * Records and aggregates Agent Nexus' own LLM spend across all protocols.
 *
 * Every LLM call — backend playground or frontend plugin, streamed or not —
 * writes one row with token counts and cost. Streamed calls bypass nr-llm's
 * usage middleware entirely, so this ledger is the *only* record of their
 * spend and the source the daily frontend budget guard counts against.
 */
final class LlmUsageTracker implements SingletonInterface
{
    public const SOURCE_BACKEND = 'backend';
    public const SOURCE_FRONTEND = 'frontend';

    private const TABLE = 'tx_agentnexus_llm_usage';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    public function record(
        string $protocol,
        string $source,
        string $model,
        int $promptTokens,
        int $completionTokens,
        ?float $cost,
        int $beUser = 0,
    ): void {
        $now = time();
        $this->connectionPool->getConnectionForTable(self::TABLE)->insert(self::TABLE, [
            'pid' => 0,
            'crdate' => $now,
            'request_date' => (int)strtotime('today'),
            'protocol' => mb_substr($protocol, 0, 12),
            'source' => $source,
            'be_user' => $beUser,
            'model' => $model,
            'prompt_tokens' => $promptTokens,
            'completion_tokens' => $completionTokens,
            'total_tokens' => $promptTokens + $completionTokens,
            'cost' => $cost ?? 0.0,
        ]);
    }

    /**
     * @return array{cost: float, requests: int, tokens: int}
     */
    public function getTotals(int $from, int $to, ?string $protocol = null): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $qb
            ->addSelectLiteral('COALESCE(SUM(cost), 0) AS cost')
            ->addSelectLiteral('COUNT(*) AS requests')
            ->addSelectLiteral('COALESCE(SUM(total_tokens), 0) AS tokens')
            ->from(self::TABLE)
            ->where(
                $qb->expr()->gte('request_date', $qb->createNamedParameter($from, Connection::PARAM_INT)),
                $qb->expr()->lte('request_date', $qb->createNamedParameter($to, Connection::PARAM_INT)),
            );
        if ($protocol !== null && $protocol !== '') {
            $qb->andWhere($qb->expr()->eq('protocol', $qb->createNamedParameter($protocol)));
        }
        $row = $qb->executeQuery()->fetchAssociative();

        $row = is_array($row) ? $row : [];
        return [
            'cost' => (float)($row['cost'] ?? 0),
            'requests' => (int)($row['requests'] ?? 0),
            'tokens' => (int)($row['tokens'] ?? 0),
        ];
    }

    public function getCostToday(?string $protocol = null): float
    {
        return $this->getTotals((int)strtotime('today'), time(), $protocol)['cost'];
    }

    /**
     * Per-month cost for the last N months (current month first).
     *
     * @return array<int, array{label: string, year: int, month: int, cost: float, requests: int}>
     */
    public function getMonthlyCosts(int $months = 3, ?string $protocol = null): array
    {
        $result = [];
        $cursor = new \DateTimeImmutable('first day of this month 00:00:00');
        for ($i = 0; $i < $months; $i++) {
            $start = $cursor->modify('-' . $i . ' months');
            $end = $start->modify('+1 month')->modify('-1 second');
            $totals = $this->getTotals($start->getTimestamp(), $end->getTimestamp(), $protocol);
            $result[] = [
                'label' => $start->format('M Y'),
                'year' => (int)$start->format('Y'),
                'month' => (int)$start->format('n'),
                'cost' => $totals['cost'],
                'requests' => $totals['requests'],
            ];
        }
        return $result;
    }
}
