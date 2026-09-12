<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Agentstack\Service;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\SingletonInterface;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Finds the seeded demo site, so the hub can link straight to a running page.
 *
 * Pages created by `agentnexus:seed-site` carry a logical key in
 * tx_agentnexus_seed_key ("page:a2ui", "data", "root"), which survives renaming
 * and moving. Looking the pages up by that key and asking SiteFinder for the
 * owning site gives a real, routed URL instead of a guess assembled from a
 * hard-coded base — which is what the old overview module did.
 *
 * Everything here degrades to null: a hub must not break because nobody has
 * seeded a demo site yet.
 */
final class SiteLocator implements SingletonInterface
{
    /** @var array<string, int>|null memoised seed key -> page uid */
    private ?array $seededPages = null;

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly SiteFinder $siteFinder,
    ) {}

    /**
     * The site the demo lives in, or null when nothing has been seeded.
     *
     * Usually that is the site root the seed command created. When it seeded
     * below an existing page instead (`--root`), there is no root of our own —
     * so any seeded page will do, since they all belong to the same site.
     */
    public function site(): ?Site
    {
        foreach ($this->seeded() as $pageUid) {
            try {
                return $this->siteFinder->getSiteByPageId($pageUid);
            } catch (SiteNotFoundException) {
                continue;
            }
        }

        return null;
    }

    /** Absolute URL of a seeded page, or null when it or its site is missing. */
    public function url(string $seedKey): ?string
    {
        $pageUid = $this->pageUid($seedKey);
        $site = $this->site();
        if ($pageUid === null || $site === null) {
            return null;
        }
        try {
            return (string)$site->getRouter()->generateUri($pageUid);
        } catch (\Throwable) {
            return null;
        }
    }

    /** Absolute URL of the demo page for one protocol. */
    public function protocolUrl(string $protocol): ?string
    {
        return $this->url('page:' . $protocol);
    }

    /**
     * The storage folder the seeded site uses, read from the site setting the
     * seed command writes. 0 when unknown.
     */
    public function storagePid(): int
    {
        $site = $this->site();
        if ($site === null) {
            return 0;
        }
        $settings = $site->getSettings()->getAll();
        $nested = $settings['agentNexus'] ?? null;
        if (is_array($nested) && isset($nested['storagePid'])) {
            return (int)$nested['storagePid'];
        }
        return (int)($settings['agentNexus.storagePid'] ?? 0);
    }

    /** True when the configured storage folder exists and is a sysfolder. */
    public function storageReady(): bool
    {
        $pid = $this->storagePid();
        if ($pid <= 0) {
            return false;
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->removeAll();

        return $queryBuilder
            ->count('uid')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($pid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('deleted', 0),
            )
            ->executeQuery()
            ->fetchOne() > 0;
    }

    public function pageUid(string $seedKey): ?int
    {
        return $this->seeded()[$seedKey] ?? null;
    }

    /**
     * @return array<string, int>
     */
    private function seeded(): array
    {
        if ($this->seededPages !== null) {
            return $this->seededPages;
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->removeAll();

        /** @var list<array{uid: int, tx_agentnexus_seed_key: string}> $rows */
        $rows = $queryBuilder
            ->select('uid', 'tx_agentnexus_seed_key')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->neq('tx_agentnexus_seed_key', $queryBuilder->createNamedParameter('')),
                $queryBuilder->expr()->eq('deleted', 0),
            )
            ->executeQuery()
            ->fetchAllAssociative();

        $map = [];
        foreach ($rows as $row) {
            $map[(string)$row['tx_agentnexus_seed_key']] = (int)$row['uid'];
        }
        // The site root first: it is the common case and resolves in one hop.
        if (isset($map['root'])) {
            $map = ['root' => $map['root']] + $map;
        }

        return $this->seededPages = $map;
    }
}
