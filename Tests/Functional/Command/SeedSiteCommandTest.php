<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Tests\Functional\Command;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Site\SiteFinder;
use Webconsulting\AgentNexus\Agentstack\Command\SeedSiteCommand;
use Webconsulting\AgentNexus\Agentstack\Service\SiteLocator;
use Webconsulting\AgentNexus\Tests\Functional\AbstractAgentNexusTestCase;

/**
 * The seed command's contract is "run it twice, get the same site" — which is
 * what makes it safe to put in a deployment. These tests check the shape it
 * produces, then run it again and assert that nothing moved.
 */
final class SeedSiteCommandTest extends AbstractAgentNexusTestCase
{
    private const PAGES = ['a2ui', 'ag-ui', 'a2a', 'ucp', 'ap2', 'playground', 'docs'];

    #[Test]
    public function itBuildsARootAStorageFolderAndAPageForEveryProtocol(): void
    {
        $this->seed();

        $pages = $this->pages();

        self::assertCount(9, $pages, 'One siteroot, seven pages and the data folder.');
        self::assertSame(1, $pages['root']['is_siteroot']);
        self::assertSame('Agent Nexus', $pages['root']['title']);
        self::assertSame('/', $pages['root']['slug']);
        self::assertSame(254, $pages['data']['doktype'], 'The storage folder is a sysfolder.');

        foreach (self::PAGES as $slug) {
            $key = 'page:' . ($slug === 'ag-ui' ? 'agui' : $slug);
            self::assertArrayHasKey($key, $pages, $slug . ' was not created');
            self::assertSame('/' . $slug, $pages[$key]['slug']);
            self::assertSame($pages['root']['uid'], $pages[$key]['pid']);
        }
    }

    #[Test]
    public function everyProtocolPageCarriesAnIntroADemoAndAnExplainer(): void
    {
        $this->seed();

        foreach (['a2ui', 'agui', 'a2a', 'ucp', 'ap2'] as $protocol) {
            $content = $this->contentOf('page:' . $protocol);
            $types = array_column($content, 'CType');

            self::assertContains('textmedia', $types, $protocol . ' has no intro');
            self::assertContains('agentnexus_protocolinfo', $types, $protocol . ' has no protocol info element');
            self::assertCount(3, $content, $protocol . ' should have exactly intro, demo and info');
        }
    }

    #[Test]
    public function theProtocolInfoElementIsConfiguredForItsOwnProtocol(): void
    {
        $this->seed();

        foreach (['a2ui', 'agui', 'a2a', 'ucp', 'ap2'] as $protocol) {
            $info = $this->seededContent('ce:' . $protocol . ':info');
            self::assertStringContainsString('<value index="vDEF">' . $protocol . '</value>', (string)$info['pi_flexform']);
        }
    }

    #[Test]
    public function itWritesASiteConfigurationWithTheStoragePid(): void
    {
        $this->seed(['--base' => ['https://agent-nexus.test/']]);

        $site = $this->get(SiteFinder::class)->getSiteByIdentifier('agent-nexus');
        $pages = $this->pages();

        self::assertSame($pages['root']['uid'], $site->getRootPageId());
        self::assertSame('https://agent-nexus.test/', (string)$site->getBase());
        self::assertContains('webconsulting/agent-nexus', $site->getConfiguration()['dependencies']);

        self::assertSame('agent-nexus', $this->get(SiteLocator::class)->site()?->getIdentifier());
        self::assertSame(
            $pages['data']['uid'],
            $this->get(SiteLocator::class)->storagePid(),
            'The hub reads the storage folder back out of the site settings.',
        );
    }

    #[Test]
    public function asecondBaseBecomesAProductionBaseVariant(): void
    {
        $this->seed(['--base' => ['https://agent-nexus.test/', 'https://live.example.org/']]);

        $variants = $this->get(SiteFinder::class)->getSiteByIdentifier('agent-nexus')->getConfiguration()['baseVariants'];

        self::assertCount(1, $variants);
        self::assertSame('https://live.example.org/', $variants[0]['base']);
        self::assertStringContainsString('Production', $variants[0]['condition']);
    }

    #[Test]
    public function runningItTwiceChangesNothing(): void
    {
        $this->seed();
        $first = $this->snapshot();

        $this->seed();

        self::assertSame($first, $this->snapshot(), 'A second run must update in place, never duplicate.');
    }

    #[Test]
    public function theMenuAndEveryPageReadInTheSeededOrder(): void
    {
        $this->seed();

        self::assertSame(
            ['data', 'page:a2ui', 'page:agui', 'page:a2a', 'page:ucp', 'page:ap2', 'page:playground', 'page:docs'],
            $this->orderedKeys('pages', $this->pages()['root']['uid']),
        );

        foreach (['a2ui', 'agui', 'a2a', 'ucp', 'ap2'] as $protocol) {
            self::assertSame(
                ['ce:' . $protocol . ':intro', 'ce:' . $protocol . ':demo', 'ce:' . $protocol . ':info'],
                $this->orderedKeys('tt_content', $this->pages()['page:' . $protocol]['uid']),
                $protocol . ' does not read intro, demo, explainer',
            );
        }
    }

    #[Test]
    public function aScrambledOrderIsRestoredByTheNextRun(): void
    {
        $this->seed();
        $pageUid = $this->pages()['page:a2ui']['uid'];

        // What an editor dragging elements around — or a seed run from before
        // the order was settled — leaves behind.
        $connection = $this->getConnectionPool()->getConnectionForTable('tt_content');
        foreach (array_reverse($this->orderedKeys('tt_content', $pageUid)) as $index => $key) {
            $connection->update('tt_content', ['sorting' => ($index + 1) * 256], ['tx_agentnexus_seed_key' => $key]);
        }
        self::assertSame(
            ['ce:a2ui:info', 'ce:a2ui:demo', 'ce:a2ui:intro'],
            $this->orderedKeys('tt_content', $pageUid),
            'precondition: the page now reads backwards',
        );

        $this->seed();

        self::assertSame(
            ['ce:a2ui:intro', 'ce:a2ui:demo', 'ce:a2ui:info'],
            $this->orderedKeys('tt_content', $pageUid),
        );
    }

    #[Test]
    public function itFindsItsRecordsAgainEvenAfterAnEditorRenamedThem(): void
    {
        $this->seed();
        $before = $this->pages();

        $this->getConnectionPool()->getConnectionForTable('pages')->update(
            'pages',
            ['title' => 'Renamed by an editor', 'slug' => '/somewhere-else'],
            ['uid' => $before['page:a2ui']['uid']],
        );

        $this->seed();
        $after = $this->pages();

        self::assertCount(9, $after, 'The renamed page was found by its seed key, not recreated.');
        self::assertSame($before['page:a2ui']['uid'], $after['page:a2ui']['uid']);
        self::assertSame('/a2ui', $after['page:a2ui']['slug'], 'and restored to the seeded slug.');
    }

    #[Test]
    public function aDryRunWritesNothing(): void
    {
        $tester = $this->seed(['--dry-run' => true]);

        self::assertSame([], $this->pages());
        self::assertFalse(is_file(Environment::getConfigPath() . '/sites/agent-nexus/config.yaml'));
        self::assertStringContainsString('Dry run', $tester->getDisplay());
    }

    #[Test]
    public function itRefusesToCreateASiteWithoutABase(): void
    {
        $tester = new CommandTester($this->get(SeedSiteCommand::class));
        $exitCode = $tester->execute([]);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('--base is required', $tester->getDisplay());
        self::assertSame([], $this->pages());
    }

    #[Test]
    public function purgePlacementsRemovesOnlyAgentNexusElements(): void
    {
        $this->createRootPage(900);
        $connection = $this->getConnectionPool()->getConnectionForTable('tt_content');
        $connection->insert('tt_content', ['uid' => 1, 'pid' => 900, 'CType' => 'agentnexus_inquiry', 'header' => 'demo', 'deleted' => 0]);
        $connection->insert('tt_content', ['uid' => 2, 'pid' => 900, 'CType' => 'textmedia', 'header' => 'an editor wrote this', 'deleted' => 0]);

        $this->seed(['--purge-placements' => '900']);

        self::assertSame(1, $this->deletedFlag('tt_content', 1), 'The Agent Nexus element is gone.');
        self::assertSame(0, $this->deletedFlag('tt_content', 2), 'Everything else is untouched.');
    }

    #[Test]
    public function purgeLegacySoftDeletesTheOldSection(): void
    {
        $this->createRootPage(800);

        $this->seed(['--purge-legacy' => '800']);

        self::assertSame(1, $this->deletedFlag('pages', 800));
    }

    /* ---- helpers ---------------------------------------------------------- */

    /**
     * @param array<string, mixed> $arguments
     */
    private function seed(array $arguments = []): CommandTester
    {
        $tester = new CommandTester($this->get(SeedSiteCommand::class));
        $tester->execute($arguments + ['--base' => ['https://agent-nexus.test/']]);
        $tester->assertCommandIsSuccessful();

        return $tester;
    }

    /**
     * Seeded pages by their logical key, numeric columns cast so assertions can
     * compare them with the values the command reports.
     *
     * @return array<string, array<string, mixed>>
     */
    private function pages(): array
    {
        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->removeAll();
        $rows = $queryBuilder
            ->select('uid', 'pid', 'slug', 'doktype', 'is_siteroot', 'title', 'sorting', 'tx_agentnexus_seed_key')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->neq('tx_agentnexus_seed_key', $queryBuilder->createNamedParameter('')),
                $queryBuilder->expr()->eq('deleted', 0),
            )
            ->executeQuery()
            ->fetchAllAssociative();

        $byKey = [];
        foreach ($rows as $row) {
            $key = (string)$row['tx_agentnexus_seed_key'];
            unset($row['tx_agentnexus_seed_key']);
            $byKey[$key] = array_map(
                static fn(mixed $value): mixed => is_numeric($value) ? (int)$value : $value,
                $row,
            );
        }
        ksort($byKey);

        return $byKey;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function contentOf(string $pageSeedKey): array
    {
        $pageUid = $this->pages()[$pageSeedKey]['uid'];
        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable('tt_content');
        $queryBuilder->getRestrictions()->removeAll();

        return $queryBuilder
            ->select('uid', 'CType', 'header', 'tx_agentnexus_seed_key')
            ->from('tt_content')
            ->where(
                $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($pageUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('deleted', 0),
            )
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * @return array<string, mixed>
     */
    private function seededContent(string $key): array
    {
        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable('tt_content');
        $queryBuilder->getRestrictions()->removeAll();
        $row = $queryBuilder
            ->select('uid', 'CType', 'pi_flexform')
            ->from('tt_content')
            ->where($queryBuilder->expr()->eq('tx_agentnexus_seed_key', $queryBuilder->createNamedParameter($key)))
            ->executeQuery()
            ->fetchAssociative();

        self::assertIsArray($row, 'No seeded content element for ' . $key);

        return $row;
    }

    /**
     * Everything the second run must leave alone.
     *
     * @return array<string, mixed>
     */
    private function snapshot(): array
    {
        $content = [];
        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable('tt_content');
        $queryBuilder->getRestrictions()->removeAll();
        foreach ($queryBuilder
            ->select('uid', 'pid', 'CType', 'header', 'sorting', 'tx_agentnexus_seed_key')
            ->from('tt_content')
            ->where($queryBuilder->expr()->eq('deleted', 0))
            ->orderBy('uid')
            ->executeQuery()
            ->fetchAllAssociative() as $row) {
            $content[] = $row;
        }

        return ['pages' => $this->pages(), 'content' => $content];
    }

    /**
     * The seed keys of one parent's seeded children, in the order the frontend
     * would render them.
     *
     * @return list<string>
     */
    private function orderedKeys(string $table, int $pid): array
    {
        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();

        return array_map(strval(...), $queryBuilder
            ->select('tx_agentnexus_seed_key')
            ->from($table)
            ->where(
                $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($pid, Connection::PARAM_INT)),
                $queryBuilder->expr()->neq('tx_agentnexus_seed_key', $queryBuilder->createNamedParameter('')),
                $queryBuilder->expr()->eq('deleted', 0),
            )
            ->orderBy('sorting')
            ->executeQuery()
            ->fetchFirstColumn());
    }

    private function deletedFlag(string $table, int $uid): int
    {
        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();
        $deleted = $queryBuilder
            ->select('deleted')
            ->from($table)
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchOne();

        self::assertNotFalse($deleted, 'No ' . $table . ' record with uid ' . $uid . '.');

        return (int)$deleted;
    }
}
