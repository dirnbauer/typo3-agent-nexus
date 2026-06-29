<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Ap2\Service;

use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\SingletonInterface;

/**
 * Stores a SIMULATED authorization a visitor issued on the Trusted Surface — the
 * cart and the Intent/Cart mandate ids. No payment is taken; this records the
 * authorization, not a transaction.
 */
final class AuthorizationStore implements SingletonInterface
{
    private const TABLE = 'tx_agentnexus_ap2_authorization';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    /**
     * @param array<int, array<string, mixed>> $cart
     */
    public function store(int $pageUid, string $url, string $intentJti, string $cartJti, bool $authorized, int $totalCents, array $cart): void
    {
        $this->connectionPool->getConnectionForTable(self::TABLE)->insert(self::TABLE, [
            'pid' => $pageUid,
            'crdate' => time(),
            'page_uid' => $pageUid,
            'source_url' => mb_substr($url, 0, 2048),
            'intent_jti' => mb_substr($intentJti, 0, 64),
            'cart_jti' => mb_substr($cartJti, 0, 64),
            'authorized' => $authorized ? 1 : 0,
            'total_cents' => $totalCents,
            'cart' => mb_substr((string)json_encode($cart, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0, 8000),
        ]);
    }
}
