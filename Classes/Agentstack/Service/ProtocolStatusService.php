<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Agentstack\Service;

use TYPO3\CMS\Backend\Routing\UriBuilder as BackendUriBuilder;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\SingletonInterface;
use Webconsulting\AgentNexus\Agentstack\Dto\ProtocolStatus;
use Webconsulting\AgentNexus\Shared\Llm\LlmGuard;

/**
 * Answers the hub's only real question: is each protocol actually working here?
 *
 * "Working" is three concrete facts, not a feeling — the eID endpoints the
 * protocol needs are registered, a storage folder exists for the records it
 * writes, and a model is either available or deliberately off. On top of that
 * comes evidence that it ran: the newest entry in the protocol's own log table
 * and how many entries it collected in the last 24 hours.
 *
 * Every lookup is defensive. A hub that throws because a log table has not been
 * created yet would be worse than one that says "no activity".
 */
final class ProtocolStatusService implements SingletonInterface
{
    private const DAY = 86400;

    /**
     * Where each protocol records that it ran, and which column holds the time.
     *
     * @var array<string, array{table: string, time: string, label: string}>
     */
    private const ACTIVITY = [
        'a2ui' => ['table' => 'tx_agentnexus_a2ui_inquiry', 'time' => 'crdate', 'label' => 'Inquiry submitted'],
        'agui' => ['table' => 'tx_agentnexus_agui_run_log', 'time' => 'request_date', 'label' => 'Assistant run'],
        'a2a' => ['table' => 'tx_agentnexus_a2a_task_log', 'time' => 'request_date', 'label' => 'Task delegated'],
        'ucp' => ['table' => 'tx_agentnexus_ucp_order_log', 'time' => 'request_date', 'label' => 'Checkout run'],
        'ap2' => ['table' => 'tx_agentnexus_ap2_mandate_log', 'time' => 'request_date', 'label' => 'Mandate issued'],
    ];

    /** Backend module to open from a protocol card. */
    private const MODULES = [
        'a2ui' => 'agentstack_a2ui',
        'agui' => 'agentstack_agui',
        'a2a' => 'agentstack_a2a',
        'ucp' => 'agentstack_ucp',
        'ap2' => 'agentstack_ap2',
    ];

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly ProtocolCatalog $protocolCatalog,
        private readonly SiteLocator $siteLocator,
        private readonly LlmGuard $llmGuard,
        private readonly BackendUriBuilder $backendUriBuilder,
    ) {}

    /**
     * @return list<ProtocolStatus>
     */
    public function all(): array
    {
        $storageReady = $this->siteLocator->storageReady();

        return array_map(
            fn(string $protocol): ProtocolStatus => $this->build($protocol, $storageReady),
            $this->protocolCatalog->protocols(),
        );
    }

    private function build(string $protocol, bool $storageReady): ProtocolStatus
    {
        $meta = $this->protocolCatalog->get($protocol);
        $endpoints = $this->protocolCatalog->endpointIds($protocol);
        $llm = $this->llmGuard->allows($protocol);
        $activity = self::ACTIVITY[$protocol];

        return new ProtocolStatus(
            key: $protocol,
            label: $meta['label'],
            name: $meta['name'],
            tagline: $meta['tagline'],
            icon: 'agentnexus-module-' . $protocol,
            endpointsRegistered: $this->endpointsRegistered($endpoints),
            endpointCount: count($endpoints),
            llmEnabled: (bool)$llm['allowed'],
            llmReason: (string)$llm['reason'],
            storageReady: $storageReady,
            lastRun: $this->lastEntry($activity['table'], $activity['time']),
            runsLast24h: $this->countSince($activity['table'], $activity['time'], time() - self::DAY),
            moduleIdentifier: self::MODULES[$protocol],
            playgroundUri: $this->moduleUri(self::MODULES[$protocol]),
            frontendUrl: $this->siteLocator->protocolUrl($protocol),
        );
    }

    /**
     * The card's "open the playground" link, resolved through the backend router
     * rather than assembled by hand, so a module path change cannot break it.
     */
    private function moduleUri(string $identifier): string
    {
        try {
            return (string)$this->backendUriBuilder->buildUriFromRoute($identifier);
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * The newest ten entries across every protocol log, for the hub's activity
     * feed.
     *
     * @return list<array{protocol: string, label: string, what: string, time: int}>
     */
    public function recentActivity(int $limit = 10): array
    {
        $entries = [];
        foreach (self::ACTIVITY as $protocol => $activity) {
            foreach ($this->latestRows($activity['table'], $activity['time'], $limit) as $time) {
                $entries[] = [
                    'protocol' => $protocol,
                    'label' => $this->protocolCatalog->get($protocol)['label'],
                    'what' => $activity['label'],
                    'time' => $time,
                ];
            }
        }

        usort($entries, static fn(array $a, array $b): int => $b['time'] <=> $a['time']);

        return array_slice($entries, 0, $limit);
    }

    /**
     * @param list<string> $endpointIds
     */
    private function endpointsRegistered(array $endpointIds): bool
    {
        $registered = $GLOBALS['TYPO3_CONF_VARS']['FE']['eID_include'] ?? [];
        if (!is_array($registered)) {
            return false;
        }
        foreach ($endpointIds as $id) {
            if (!isset($registered[$id])) {
                return false;
            }
        }
        return $endpointIds !== [];
    }

    private function lastEntry(string $table, string $timeColumn): ?int
    {
        $rows = $this->latestRows($table, $timeColumn, 1);
        return $rows[0] ?? null;
    }

    /**
     * @return list<int>
     */
    private function latestRows(string $table, string $timeColumn, int $limit): array
    {
        try {
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
            /** @var list<array{t: int|string}> $rows */
            $rows = $queryBuilder
                ->selectLiteral($queryBuilder->quoteIdentifier($timeColumn) . ' AS t')
                ->from($table)
                ->orderBy($timeColumn, 'DESC')
                ->setMaxResults($limit)
                ->executeQuery()
                ->fetchAllAssociative();
        } catch (\Throwable) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn(array $row): int => (int)$row['t'], $rows),
            static fn(int $time): bool => $time > 0,
        ));
    }

    private function countSince(string $table, string $timeColumn, int $since): int
    {
        try {
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
            return (int)$queryBuilder
                ->count('uid')
                ->from($table)
                ->where($queryBuilder->expr()->gte($timeColumn, $queryBuilder->createNamedParameter($since, Connection::PARAM_INT)))
                ->executeQuery()
                ->fetchOne();
        } catch (\Throwable) {
            return 0;
        }
    }
}
