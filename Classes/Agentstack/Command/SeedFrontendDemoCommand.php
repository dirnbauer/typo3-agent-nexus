<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Agentstack\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Seed a coherent Agent Nexus frontend section below the Desiderio site root.
 *
 * The protocol modules also expose individual seed commands. This command
 * creates one navigable frontend section, with a theory intro and one plugin
 * page per protocol.
 */
#[AsCommand(
    name: 'agent-nexus:seed:frontend',
    description: 'Create or refresh the Desiderio Agent Nexus frontend demo page tree.',
)]
final class SeedFrontendDemoCommand extends Command
{
    private const ROOT_PAGE = [
        'title' => 'Agent Nexus',
        'slug' => '/agent-nexus',
        'sorting' => 360,
        'header' => 'Agent Nexus protocol lab',
        'bodytext' => '<p>Explore the protocol family behind modern agent experiences. Each subpage shows one visitor-facing TYPO3 plugin styled with the Desiderio shadcn theme: A2UI for safe generated interfaces, AG-UI for live streams, A2A for delegation, UCP for simulated commerce, and AP2 for signed authorization.</p>',
    ];

    private const PROTOCOL_PAGES = [
        [
            'title' => 'A2UI Smart Project Inquiry',
            'slug' => '/agent-nexus/a2ui',
            'sorting' => 100,
            'ctype' => 'agentnexus_inquiry',
            'header' => 'Generate the right intake form',
            'intro' => '<p>A2UI lets an agent describe a user interface as declarative JSON. The trusted TYPO3 client renders only known components, binds inputs to a data model, and sends structured actions back.</p>',
        ],
        [
            'title' => 'AG-UI Live Assistant',
            'slug' => '/agent-nexus/ag-ui',
            'sorting' => 200,
            'ctype' => 'agentnexus_assistant',
            'header' => 'Stream an agent run with approval',
            'intro' => '<p>AG-UI turns long-running agent work into typed events: text deltas, tool calls, state patches, and human approval gates. This page shows the visitor-facing live assistant.</p>',
        ],
        [
            'title' => 'A2A Expert Router',
            'slug' => '/agent-nexus/a2a',
            'sorting' => 300,
            'ctype' => 'agentnexus_concierge',
            'header' => 'Delegate a task to a specialist agent',
            'intro' => '<p>A2A lets independent agents discover each other through Agent Cards and delegate work over JSON-RPC. The frontend concierge demonstrates the task lifecycle and artifact handoff.</p>',
        ],
        [
            'title' => 'UCP Package & Quote Builder',
            'slug' => '/agent-nexus/ucp',
            'sorting' => 400,
            'ctype' => 'agentnexus_checkout',
            'header' => 'Let a shopping agent build a quote',
            'intro' => '<p>UCP gives a shopping agent and a merchant a shared manifest, catalog, and checkout state machine. This simulated checkout builds a cart and pauses before confirmation.</p>',
        ],
        [
            'title' => 'AP2 Signed Quote Authorization',
            'slug' => '/agent-nexus/ap2',
            'sorting' => 500,
            'ctype' => 'agentnexus_trustedsurface',
            'header' => 'Authorize an agent purchase with mandates',
            'intro' => '<p>AP2 proves an agent is allowed to act. A human-signed Intent Mandate sets the scope; a Cart Mandate locks the order; verification checks the signed chain before authorization.</p>',
        ],
    ];

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('parent', 'p', InputOption::VALUE_REQUIRED, 'Desiderio root page UID', '505')
            ->addOption('hidden', null, InputOption::VALUE_NONE, 'Create or update seeded pages as hidden.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $parent = (int)$input->getOption('parent');
        $hidden = (bool)$input->getOption('hidden');

        if ($parent <= 0) {
            $io->error('A positive --parent page UID is required.');
            return Command::FAILURE;
        }

        $rootUid = $this->upsertPage($parent, self::ROOT_PAGE['title'], self::ROOT_PAGE['slug'], self::ROOT_PAGE['sorting'], $hidden);
        $this->upsertText($rootUid, self::ROOT_PAGE['header'], self::ROOT_PAGE['bodytext'], 100);

        $io->writeln(sprintf('Agent Nexus root page: %d (%s)', $rootUid, self::ROOT_PAGE['slug']));

        foreach (self::PROTOCOL_PAGES as $page) {
            $pageUid = $this->upsertPage($rootUid, $page['title'], $page['slug'], $page['sorting'], $hidden);
            $this->upsertText($pageUid, $page['title'], $page['intro'], 100);
            $contentUid = $this->upsertPlugin($pageUid, $page['ctype'], $page['header'], 200);
            $io->writeln(sprintf('  %s: page %d, content %d', $page['title'], $pageUid, $contentUid));
        }

        $this->flushCaches($parent, $rootUid);
        $io->success('Seeded the Desiderio Agent Nexus frontend section.');

        return Command::SUCCESS;
    }

    private function upsertPage(int $pid, string $title, string $slug, int $sorting, bool $hidden): int
    {
        $connection = $this->connectionPool->getConnectionForTable('pages');
        $existing = $connection->select(['uid'], 'pages', [
            'pid' => $pid,
            'slug' => $slug,
            'deleted' => 0,
        ])->fetchOne();

        $fields = [
            'pid' => $pid,
            'doktype' => 1,
            'title' => $title,
            'slug' => $slug,
            'hidden' => $hidden ? 1 : 0,
            'nav_hide' => 0,
            'sorting' => $sorting,
            'tstamp' => time(),
        ];

        if ($existing !== false) {
            $connection->update('pages', $fields, ['uid' => (int)$existing]);
            return (int)$existing;
        }

        $fields['crdate'] = time();
        $connection->insert('pages', $fields);
        return (int)$connection->lastInsertId();
    }

    private function upsertText(int $pid, string $header, string $bodytext, int $sorting): int
    {
        return $this->upsertContent($pid, [
            'CType' => 'textmedia',
            'header' => $header,
            'bodytext' => $bodytext,
            'sorting' => $sorting,
        ], ['pid' => $pid, 'CType' => 'textmedia', 'header' => $header, 'deleted' => 0]);
    }

    private function upsertPlugin(int $pid, string $ctype, string $header, int $sorting): int
    {
        return $this->upsertContent($pid, [
            'CType' => $ctype,
            'header' => $header,
            'sorting' => $sorting,
        ], ['pid' => $pid, 'CType' => $ctype, 'deleted' => 0]);
    }

    /**
     * @param array<string, mixed> $fields
     * @param array<string, mixed> $match
     */
    private function upsertContent(int $pid, array $fields, array $match): int
    {
        $connection = $this->connectionPool->getConnectionForTable('tt_content');
        $existing = $connection->select(['uid'], 'tt_content', $match)->fetchOne();

        $fields = array_replace([
            'pid' => $pid,
            'colPos' => 0,
            'header_layout' => '0',
            'hidden' => 0,
            'tstamp' => time(),
        ], $fields);

        if ($existing !== false) {
            $connection->update('tt_content', $fields, ['uid' => (int)$existing]);
            return (int)$existing;
        }

        $fields['crdate'] = time();
        $connection->insert('tt_content', $fields);
        return (int)$connection->lastInsertId();
    }

    private function flushCaches(int ...$pages): void
    {
        try {
            $cache = GeneralUtility::makeInstance(CacheManager::class)->getCache('pages');
            foreach ($pages as $page) {
                $cache->flushByTag('pageId_' . $page);
            }
        } catch (\Throwable) {
            // Best effort for local demo seeding.
        }
    }
}
