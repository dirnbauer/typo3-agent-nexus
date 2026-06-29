<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Agui\Service;

use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\SingletonInterface;

/**
 * Stores a lead the frontend Live Assistant captured — only ever called after the
 * visitor approved the human-in-the-loop confirmation.
 */
final class LeadStore implements SingletonInterface
{
    private const TABLE = 'tx_agentnexus_agui_lead';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public function store(int $pageUid, string $url, string $intent, array $data): void
    {
        $this->connectionPool->getConnectionForTable(self::TABLE)->insert(self::TABLE, [
            'pid' => $pageUid,
            'crdate' => time(),
            'page_uid' => $pageUid,
            'source_url' => mb_substr($url, 0, 2048),
            'intent' => mb_substr($intent, 0, 2000),
            'data' => mb_substr((string)json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0, 50000),
        ]);
    }
}
