<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Ucp\Service;

use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\SingletonInterface;

/**
 * Stores a SIMULATED order the frontend Agent Checkout produced — what the
 * visitor's agent assembled and the human authorized. Useful as a lead / audit
 * trail. No payment is taken; this records intent, not a transaction.
 */
final class OrderStore implements SingletonInterface
{
    private const TABLE = 'tx_agentnexus_ucp_order';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    /**
     * @param array<int, array<string, mixed>> $cart
     * @param array<string, mixed> $contact
     */
    public function store(int $pageUid, string $url, string $orderId, string $intent, int $totalCents, array $cart, array $contact = []): void
    {
        $this->connectionPool->getConnectionForTable(self::TABLE)->insert(self::TABLE, [
            'pid' => $pageUid,
            'crdate' => time(),
            'page_uid' => $pageUid,
            'source_url' => mb_substr($url, 0, 2048),
            'order_id' => mb_substr($orderId, 0, 64),
            'intent' => mb_substr($intent, 0, 64),
            'total_cents' => $totalCents,
            'cart' => mb_substr((string)json_encode($cart, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0, 8000),
            'contact' => mb_substr((string)json_encode($contact, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0, 4000),
        ]);
    }
}
