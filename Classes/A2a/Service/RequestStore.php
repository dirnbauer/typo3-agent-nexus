<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\A2a\Service;

use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\SingletonInterface;

/**
 * Stores a request the frontend Concierge handled — the prompt a visitor gave,
 * the artifact the agent returned, and any structured data. Useful as a lead /
 * audit trail for the public-facing A2A agent.
 */
final class RequestStore implements SingletonInterface
{
    private const TABLE = 'tx_agentnexus_a2a_request';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public function store(int $pageUid, string $url, string $skill, string $prompt, string $answer, array $data = []): void
    {
        $this->connectionPool->getConnectionForTable(self::TABLE)->insert(self::TABLE, [
            'pid' => $pageUid,
            'crdate' => time(),
            'page_uid' => $pageUid,
            'source_url' => mb_substr($url, 0, 2048),
            'skill' => mb_substr($skill, 0, 64),
            'prompt' => mb_substr($prompt, 0, 2000),
            'answer' => mb_substr($answer, 0, 8000),
            'data' => mb_substr((string)json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0, 20000),
        ]);
    }
}
