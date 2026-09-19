<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Agentstack\Command;

use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Authentication\CommandLineUserAuthentication;
use TYPO3\CMS\Core\Configuration\Event\SiteConfigurationChangedEvent;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Core\Bootstrap;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Site\SiteSettingsFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Build the whole Agent Nexus demo site in one command.
 *
 * The previous seeder wrote rows straight into the database below a hard-coded
 * Desiderio page uid, which meant no slug generation, no reference index, no
 * site configuration and a different result on every installation. This one
 * creates a complete, self-contained site instead:
 *
 *   Agent Nexus            (/)          siteroot, hero intro
 *     A2UI                 (/a2ui)      intro + Inquiry demo + protocol info
 *     AG-UI                (/ag-ui)     intro + Assistant demo + protocol info
 *     A2A                  (/a2a)       intro + Concierge demo + protocol info
 *     UCP                  (/ucp)       intro + Checkout demo + protocol info
 *     AP2                  (/ap2)       intro + Trusted surface demo + protocol info
 *     Playground           (/playground) every demo on one page
 *     Docs                 (/docs)      where to read more
 *     data                 sysfolder    storage pid for inquiries, leads, orders
 *
 * Everything goes through DataHandler, so slugs, sorting, the reference index
 * and history behave exactly as they would in the backend. Every seeded record
 * carries a logical key in tx_agentnexus_seed_key, so a second run updates the
 * same rows — even if an editor renamed or moved them — and never duplicates
 * anything. Records without a key were not seeded and are never touched.
 *
 * The site configuration is written through SiteWriter, including the storage
 * folder as the agentNexus.storagePid site setting.
 */
#[AsCommand(
    name: 'agentnexus:seed-site',
    description: 'Create or refresh the Agent Nexus demo site: root page, protocol pages, demo content and site configuration.',
)]
final class SeedSiteCommand extends Command
{
    private const SET_IDENTIFIER = 'webconsulting/agent-nexus';

    /**
     * The five protocol pages, each with its demo plugin and its explainer.
     *
     * @var array<string, array{title: string, slug: string, ctype: string, header: string, intro: string}>
     */
    private const PROTOCOL_PAGES = [
        'a2ui' => [
            'title' => 'A2UI',
            'slug' => 'a2ui',
            'ctype' => 'agentnexus_inquiry',
            'header' => 'The agent designs the form',
            'intro' => '<p>Describe what you need in one line. The agent answers with a surface — a flat list of components — and the site renders only components it already knows. Unknown components and unknown properties are dropped before anything reaches the page.</p>',
        ],
        'agui' => [
            'title' => 'AG-UI',
            'slug' => 'ag-ui',
            'ctype' => 'agentnexus_assistant',
            'header' => 'Watch the run, then approve it',
            'intro' => '<p>An agent run is a stream of typed events, so the interface can show reasoning and tool calls as they happen. Before the assistant writes anything it stops at a confirmation and waits for a human decision.</p>',
        ],
        'a2a' => [
            'title' => 'A2A',
            'slug' => 'a2a',
            'ctype' => 'agentnexus_concierge',
            'header' => 'Delegate a task to the site agent',
            'intro' => '<p>This site publishes an Agent Card, so another agent can discover it, delegate a task over JSON-RPC and collect the result as a named artifact. The concierge below does exactly that from the browser.</p>',
        ],
        'ucp' => [
            'title' => 'UCP',
            'slug' => 'ucp',
            'ctype' => 'agentnexus_checkout',
            'header' => 'Let a shopping agent build the cart',
            'intro' => '<p>The agent reads the merchant manifest, assembles a cart from the real catalogue and then stops. Prices are always deterministic and no order is ever placed — every checkout here is simulated.</p>',
        ],
        'ap2' => [
            'title' => 'AP2',
            'slug' => 'ap2',
            'ctype' => 'agentnexus_trustedsurface',
            'header' => 'Prove the purchase was authorized',
            'intro' => '<p>Two signed mandates — one for the intent, one for the exact cart — are verified as a chain: both signatures, the reference between them, the merchant and the spending cap. Mandates here are signed with a sandbox key.</p>',
        ],
    ];

    /**
     * The two pages that are not about a single protocol.
     *
     * The playground really does carry all five demos — it used to promise
     * "all five demos side by side" and ship an empty page — and Docs carries a
     * hub with the endpoints hidden, which makes it an index of the five
     * specifications rather than another paragraph about them.
     *
     * @var array<string, array{title: string, slug: string, header: string, intro: string, elements: list<array{suffix: string, ctype: string, header: string, settings: array<string, string>}>}>
     */
    private const EXTRA_PAGES = [
        'playground' => [
            'title' => 'Playground',
            'slug' => 'playground',
            'header' => 'Everything on one page',
            'intro' => '<p>All five demos side by side, so you can compare how the protocols behave without leaving the page. Nothing here is shared between them: each one talks to its own endpoint.</p>',
            'elements' => [
                ['suffix' => 'demo:a2ui', 'ctype' => 'agentnexus_inquiry', 'header' => 'A2UI — the agent designs the form', 'settings' => []],
                ['suffix' => 'demo:agui', 'ctype' => 'agentnexus_assistant', 'header' => 'AG-UI — watch the run, then approve it', 'settings' => []],
                ['suffix' => 'demo:a2a', 'ctype' => 'agentnexus_concierge', 'header' => 'A2A — delegate a task', 'settings' => []],
                ['suffix' => 'demo:ucp', 'ctype' => 'agentnexus_checkout', 'header' => 'UCP — let an agent build the cart', 'settings' => []],
                ['suffix' => 'demo:ap2', 'ctype' => 'agentnexus_trustedsurface', 'header' => 'AP2 — prove it was authorized', 'settings' => []],
            ],
        ],
        'docs' => [
            'title' => 'Docs',
            'slug' => 'docs',
            'header' => 'Where to read more',
            'intro' => '<p>Each protocol is defined by someone else; this site only implements it. The cards below link to the specification that defines each one and to the demo that runs it here. Installation, the site sets and the endpoints are covered by the extension documentation.</p>',
            'elements' => [
                ['suffix' => 'specs', 'ctype' => 'agentnexus_hub', 'header' => 'The five specifications', 'settings' => [
                    'settings.intro' => 'Every protocol below is an open specification. Agent Nexus implements the part of each one that a TYPO3 site can honestly demonstrate.',
                    'settings.show_health' => '0',
                    'settings.show_endpoints' => '0',
                ]],
            ],
        ],
    ];

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly SiteWriter $siteWriter,
        private readonly SiteFinder $siteFinder,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly SiteSettingsFactory $siteSettingsFactory,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('site-identifier', null, InputOption::VALUE_REQUIRED, 'Identifier of the site configuration to create or update.', 'agent-nexus')
            ->addOption('base', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Site base URL. Repeat the option to add base variants; the first one is the default base.')
            ->addOption('root', null, InputOption::VALUE_REQUIRED, 'Seed below this existing page instead of creating a new site root. The site owning that page is updated rather than replaced.')
            ->addOption('purge-legacy', null, InputOption::VALUE_REQUIRED, 'Soft-delete this page and everything below it (an older demo section) before seeding.')
            ->addOption('purge-placements', null, InputOption::VALUE_REQUIRED, 'Comma-separated page uids to remove every agentnexus_* content element from before seeding.')
            ->addOption('hidden', null, InputOption::VALUE_NONE, 'Create the seeded pages hidden, so the site can be reviewed before it goes live.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would change and write nothing.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $identifier = trim((string)$input->getOption('site-identifier'));
        /** @var list<string> $bases */
        $bases = array_values(array_filter(array_map(trim(...), (array)$input->getOption('base'))));
        $rootOption = (int)$input->getOption('root');
        $hidden = (bool)$input->getOption('hidden');
        $dryRun = (bool)$input->getOption('dry-run');

        if ($identifier === '' || !preg_match('/^[a-z0-9][a-z0-9_-]*$/i', $identifier)) {
            $io->error('--site-identifier must be a non-empty identifier (letters, digits, dash, underscore).');
            return Command::FAILURE;
        }
        if ($bases === [] && $rootOption <= 0) {
            $io->error('At least one --base is required when creating a new site root. Pass --root=<uid> to seed below an existing site instead.');
            return Command::FAILURE;
        }

        // DataHandler needs an authenticated backend user even on the CLI. The
        // console application creates the (not yet logged in) CLI user for us;
        // when the command is driven directly — a CommandTester, another
        // command — nobody has, so create it here before authenticating.
        if (!(($GLOBALS['BE_USER'] ?? null) instanceof BackendUserAuthentication)) {
            Bootstrap::initializeBackendUser(CommandLineUserAuthentication::class);
        }
        Bootstrap::initializeBackendAuthentication();

        $io->title('Agent Nexus — seed site');
        if ($dryRun) {
            $io->note('Dry run: nothing is written.');
        }

        $this->purge($io, $input, $dryRun);

        $rootUid = $this->seedTree($io, $rootOption, $hidden, $dryRun);
        if ($rootUid === 0) {
            return $dryRun ? Command::SUCCESS : Command::FAILURE;
        }

        $storageUid = $this->findSeeded('pages', 'data');
        $this->writeSiteConfiguration($io, $identifier, $rootUid, $storageUid, $bases, $rootOption > 0, $dryRun);

        $io->success($dryRun
            ? 'Dry run finished — re-run without --dry-run to apply.'
            : sprintf('Agent Nexus site seeded: root page %d, storage folder %d.', $rootUid, $storageUid));

        return Command::SUCCESS;
    }

    /**
     * Remove what a previous, differently shaped demo left behind: an entire old
     * section, and/or every Agent Nexus element placed on pages that belong to
     * another site.
     */
    private function purge(SymfonyStyle $io, InputInterface $input, bool $dryRun): void
    {
        $legacy = (int)$input->getOption('purge-legacy');
        if ($legacy > 0) {
            $io->section(sprintf('Purging legacy page tree %d', $legacy));
            if (!$dryRun) {
                $this->commands(['pages' => [$legacy => ['delete' => 1]]]);
            }
            $io->writeln(sprintf('  page %d and its subtree soft-deleted', $legacy));
        }

        $placements = array_values(array_filter(array_map(
            static fn(string $uid): int => (int)trim($uid),
            explode(',', (string)$input->getOption('purge-placements')),
        )));
        if ($placements === []) {
            return;
        }

        $io->section('Purging Agent Nexus elements from ' . implode(', ', array_map(strval(...), $placements)));
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tt_content');
        $queryBuilder->getRestrictions()->removeAll();
        /** @var list<array{uid: int, pid: int, CType: string}> $rows */
        $rows = $queryBuilder
            ->select('uid', 'pid', 'CType')
            ->from('tt_content')
            ->where(
                $queryBuilder->expr()->in('pid', $queryBuilder->createNamedParameter($placements, Connection::PARAM_INT_ARRAY)),
                $queryBuilder->expr()->eq('deleted', 0),
                $queryBuilder->expr()->like('CType', $queryBuilder->createNamedParameter('agentnexus\_%')),
            )
            ->executeQuery()
            ->fetchAllAssociative();

        if ($rows === []) {
            $io->writeln('  nothing to remove');
            return;
        }

        $commands = [];
        foreach ($rows as $row) {
            $commands['tt_content'][(int)$row['uid']] = ['delete' => 1];
            $io->writeln(sprintf('  tt_content %d (%s) on page %d', (int)$row['uid'], (string)$row['CType'], (int)$row['pid']));
        }
        if (!$dryRun) {
            $this->commands($commands);
        }
    }

    /**
     * Create or update the root, the storage folder, the protocol pages and
     * their content. Returns the root page uid (0 in a dry run that would have
     * created it).
     */
    private function seedTree(SymfonyStyle $io, int $rootOption, bool $hidden, bool $dryRun): int
    {
        $io->section('Page tree');

        $rootIsOwn = $rootOption <= 0;
        $rootUid = $rootOption;

        if ($rootIsOwn) {
            $rootUid = $this->upsertRecord('pages', 'root', 0, [
                'title' => 'Agent Nexus',
                'slug' => '/',
                'doktype' => 1,
                'is_siteroot' => 1,
                'hidden' => $hidden ? 1 : 0,
            ], $dryRun);
            $io->writeln($this->line('root page', $rootUid, '/'));
        } else {
            $io->writeln(sprintf('  reusing existing page %d as the section root', $rootUid));
        }

        if ($rootUid === 0) {
            if (!$dryRun) {
                $io->error('The root page could not be created.');
            }
            return 0;
        }

        $storageUid = $this->upsertRecord('pages', 'data', $rootUid, [
            'title' => 'data',
            'doktype' => 254,
            'hidden' => 0,
            'nav_hide' => 1,
        ], $dryRun);
        $io->writeln($this->line('storage folder', $storageUid, 'sysfolder'));

        if ($rootIsOwn) {
            // The site root used to be a headline and a paragraph over an empty
            // screen. The hub turns it into the index the five pages hang off.
            $this->reorder('tt_content', [
                $this->upsertText($rootUid, 'home:intro', 'Five agent protocols, running on this TYPO3', '<p>Agent Nexus is a working lab, not a slide deck: every page below runs a real implementation of one protocol against this installation&rsquo;s own content, catalogue and endpoints. Nothing is charged, nothing is sent — the demos are deliberately sandboxed.</p>', $dryRun),
                $this->upsertElement($io, $rootUid, 'home:hub', [
                    'suffix' => 'hub',
                    'ctype' => 'agentnexus_hub',
                    'header' => 'Pick a protocol',
                    'settings' => ['settings.show_health' => '1', 'settings.show_endpoints' => '1'],
                ], $dryRun),
            ]);
        }

        $pageOrder = [$storageUid];
        foreach ($this->pages() as $key => $page) {
            $pageUid = $this->upsertRecord('pages', 'page:' . $key, $rootUid, [
                'title' => $page['title'],
                'slug' => '/' . $page['slug'],
                'doktype' => 1,
                'hidden' => $hidden ? 1 : 0,
            ], $dryRun);
            $pageOrder[] = $pageUid;
            $io->writeln($this->line($page['title'], $pageUid, '/' . $page['slug']));

            if ($pageUid === 0) {
                continue;
            }

            // The intro first, then whatever the page carries — the order the
            // page has to read in, whatever order the records were written.
            $contentOrder = [
                $this->upsertText($pageUid, 'ce:' . $key . ':intro', $page['header'], $page['intro'], $dryRun),
            ];
            foreach ($page['elements'] as $element) {
                $contentOrder[] = $this->upsertElement($io, $pageUid, 'ce:' . $key . ':' . $element['suffix'], $element, $dryRun);
            }

            $this->reorder('tt_content', $contentOrder);
        }

        $this->reorder('pages', $pageOrder);

        return $rootUid;
    }

    /**
     * The page tree below the root, protocol pages first.
     *
     * A protocol page is the general case with its two elements filled in — the
     * demo plugin and its explainer — so both kinds of page can be seeded by one
     * loop instead of two branches inside it.
     *
     * @return array<string, array{title: string, slug: string, header: string, intro: string, elements: list<array{suffix: string, ctype: string, header: string, settings: array<string, string>}>}>
     */
    private function pages(): array
    {
        $pages = [];
        foreach (self::PROTOCOL_PAGES as $protocol => $page) {
            $pages[$protocol] = [
                'title' => $page['title'],
                'slug' => $page['slug'],
                'header' => $page['header'],
                'intro' => $page['intro'],
                'elements' => [
                    ['suffix' => 'demo', 'ctype' => $page['ctype'], 'header' => 'Try it', 'settings' => []],
                    ['suffix' => 'info', 'ctype' => 'agentnexus_protocolinfo', 'header' => 'How ' . $page['title'] . ' works', 'settings' => [
                        'settings.protocol' => $protocol,
                        'settings.sections' => 'diagram,endpoints,how-it-works',
                        'settings.show_facts' => '1',
                    ]],
                ],
            ];
        }

        return $pages + self::EXTRA_PAGES;
    }

    /**
     * One Agent Nexus plugin on a page, with its FlexForm settings.
     *
     * @param array{suffix: string, ctype: string, header: string, settings: array<string, string>} $element
     */
    private function upsertElement(SymfonyStyle $io, int $pid, string $key, array $element, bool $dryRun): int
    {
        $fields = [
            'CType' => $element['ctype'],
            'header' => $element['header'],
            // Every Agent Nexus partial renders {data.header} itself, so leaving
            // the content-element header visible printed it twice.
            'header_layout' => '100',
            'colPos' => 0,
        ];

        // What the element says here wins; everything else is what an editor
        // would have got from the data structure.
        $settings = $element['settings'] + $this->flexFormDefaults($element['ctype']);
        if ($settings !== []) {
            $fields['pi_flexform'] = ['data' => ['sDEF' => ['lDEF' => array_map(
                static fn(string $value): array => ['vDEF' => $value],
                $settings,
            )]]];
        }

        $uid = $this->upsertRecord('tt_content', $key, $pid, $fields, $dryRun);
        $io->writeln($this->line('    ' . $element['ctype'], $uid, ''));

        return $uid;
    }

    /**
     * The FlexForm defaults an editor would get for this content type.
     *
     * DataHandler does not apply them: the backend form writes a FlexForm's
     * defaults the first time an editor saves the record, so one written
     * programmatically keeps an empty FlexForm. Every seeded demo therefore
     * rendered with a blank input, no placeholder and no explanation — the
     * widget looked broken on a freshly seeded site. Reading the defaults back
     * out of the data structure TCA already points at keeps that copy in one
     * place instead of restating it here.
     *
     * @return array<string, string>
     */
    private function flexFormDefaults(string $cType): array
    {
        $dataStructure = $GLOBALS['TCA']['tt_content']['types'][$cType]['columnsOverrides']['pi_flexform']['config']['ds'] ?? null;
        if (!is_string($dataStructure) || !str_starts_with($dataStructure, 'FILE:')) {
            return [];
        }

        $file = GeneralUtility::getFileAbsFileName(substr($dataStructure, 5));
        if ($file === '' || !is_file($file)) {
            return [];
        }

        $parsed = GeneralUtility::xml2array((string)file_get_contents($file));
        $elements = is_array($parsed) ? ($parsed['sheets']['sDEF']['ROOT']['el'] ?? null) : null;
        if (!is_array($elements)) {
            return [];
        }

        $defaults = [];
        foreach ($elements as $name => $element) {
            $default = is_array($element) ? ($element['config']['default'] ?? null) : null;
            if (is_string($name) && (is_string($default) || is_int($default))) {
                $defaults[$name] = (string)$default;
            }
        }

        return $defaults;
    }

    private function upsertText(int $pid, string $key, string $header, string $bodytext, bool $dryRun): int
    {
        return $this->upsertRecord('tt_content', $key, $pid, [
            'CType' => 'textmedia',
            'header' => $header,
            'bodytext' => $bodytext,
            'colPos' => 0,
        ], $dryRun);
    }

    /**
     * Create or update one seeded record through DataHandler. Position is not
     * this method's business — {@see reorder()} settles the whole sibling list
     * once every record in it exists.
     *
     * @param array<string, mixed> $fields
     */
    private function upsertRecord(string $table, string $key, int $pid, array $fields, bool $dryRun): int
    {
        $existingUid = $this->findSeeded($table, $key);
        $fields['tx_agentnexus_seed_key'] = $key;

        if ($dryRun) {
            return $existingUid;
        }

        if ($existingUid > 0) {
            $this->data([$table => [$existingUid => $fields]]);
            return $existingUid;
        }

        $placeholder = 'NEW' . substr(md5($table . $key . microtime(false)), 0, 12);
        $fields['pid'] = $pid;
        $dataHandler = $this->data([$table => [$placeholder => $fields]]);

        return (int)($dataHandler->substNEWwithIDs[$placeholder] ?? 0);
    }

    /**
     * Put a list of siblings into the intended reading order.
     *
     * A record created with a plain pid lands at the TOP of its page, so writing
     * siblings in sequence produces them in reverse. Positioning them on create
     * (DataHandler's negative "insert after this uid" target) fixed only the
     * first run: records an earlier version had already created, or an editor
     * had dragged, stayed where they were — a seeder that claims to be
     * idempotent has to *converge* on the intended order, not merely produce it
     * once. So nothing is positioned on create and the whole sibling list is
     * settled here instead, with a single mechanism for both cases.
     *
     * Already in order means no command at all, which is what keeps a second run
     * a true no-op: every move would otherwise recompute `sorting`.
     *
     * @param list<int> $uids in the order they should read; 0 for records a dry run did not create
     */
    private function reorder(string $table, array $uids): void
    {
        $uids = array_values(array_filter($uids));
        if (count($uids) < 2 || $this->isOrdered($table, $uids)) {
            return;
        }

        // One command map per move: "move after X" asks DataHandler where X sits
        // right now, and a second move batched into the same map would still be
        // answered from the record it read before the first one ran.
        foreach (array_slice($uids, 1) as $index => $uid) {
            $this->commands([$table => [$uid => ['move' => -$uids[$index]]]]);
        }
    }

    /**
     * @param list<int> $uids
     */
    private function isOrdered(string $table, array $uids): bool
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();
        /** @var array<int, int> $sorting */
        $sorting = $queryBuilder
            ->select('uid', 'sorting')
            ->from($table)
            ->where($queryBuilder->expr()->in('uid', $queryBuilder->createNamedParameter($uids, Connection::PARAM_INT_ARRAY)))
            ->executeQuery()
            ->fetchAllKeyValue();

        $previous = null;
        foreach ($uids as $uid) {
            if (!isset($sorting[$uid])) {
                return false;
            }
            $current = (int)$sorting[$uid];
            if ($previous !== null && $current <= $previous) {
                return false;
            }
            $previous = $current;
        }

        return true;
    }

    /**
     * The configuration of an already-configured site, or null when the
     * identifier is free.
     *
     * @return array<string, mixed>|null
     */
    private function existingSiteConfiguration(string $identifier): ?array
    {
        try {
            return $this->siteFinder->getSiteByIdentifier($identifier)->getConfiguration();
        } catch (SiteNotFoundException) {
            return null;
        }
    }

    /**
     * The site the demo lives in. When --root points into an existing site we
     * only add our set and the storage pid to it; otherwise a complete site
     * configuration is written.
     *
     * @param list<string> $bases
     */
    private function writeSiteConfiguration(
        SymfonyStyle $io,
        string $identifier,
        int $rootUid,
        int $storageUid,
        array $bases,
        bool $reusingRoot,
        bool $dryRun,
    ): void {
        $io->section('Site configuration');

        if ($reusingRoot) {
            try {
                $site = $this->siteFinder->getSiteByPageId($rootUid);
            } catch (SiteNotFoundException) {
                $io->warning(sprintf('Page %d does not belong to a site — configure one and re-run to get the storage pid setting.', $rootUid));
                return;
            }
            $identifier = $site->getIdentifier();
            $configuration = $site->getConfiguration();
            $dependencies = array_values(array_unique([...(array)($configuration['dependencies'] ?? []), self::SET_IDENTIFIER]));
            $configuration['dependencies'] = $dependencies;
            $io->writeln(sprintf('  updating existing site "%s" (set + storage pid only)', $identifier));
        } elseif (($existing = $this->existingSiteConfiguration($identifier)) !== null) {
            // A site with this identifier is already configured — usually from
            // the repository, carrying base variants, extra sets and settings
            // that seeding knows nothing about. Writing a fresh configuration
            // over it silently drops all of that: it once replaced the two
            // Desiderio sets that supply "page = PAGE" with the bare plugin
            // set, and every page of the site answered "No page configured for
            // type=0" until the file was restored by hand. Only the parts
            // seeding owns are updated.
            $configuration = $existing;
            $configuration['rootPageId'] = $rootUid;
            $configuration['dependencies'] = array_values(array_unique([
                ...(array)($existing['dependencies'] ?? []),
                self::SET_IDENTIFIER,
            ]));
            $io->writeln(sprintf('  updating existing site "%s" -> page %d (base and sets kept)', $identifier, $rootUid));
            if ($bases !== [] && ($existing['base'] ?? null) !== $bases[0]) {
                $io->writeln(sprintf('    note: --base is ignored, the site keeps its configured base "%s"', (string)($existing['base'] ?? '')));
            }
        } else {
            $configuration = [
                'rootPageId' => $rootUid,
                'base' => $bases[0],
                'languages' => [[
                    'title' => 'English',
                    'enabled' => true,
                    'languageId' => 0,
                    'base' => '/',
                    'locale' => 'en_US.UTF-8',
                    'navigationTitle' => 'English',
                    'flag' => 'us',
                ]],
                'errorHandling' => [],
                'routes' => [],
                'dependencies' => [self::SET_IDENTIFIER],
            ];
            if (count($bases) > 1) {
                $configuration['baseVariants'] = array_map(
                    static fn(string $base): array => [
                        'base' => $base,
                        'condition' => 'applicationContext == "Production"',
                    ],
                    array_slice($bases, 1),
                );
            }
            $io->writeln(sprintf('  site "%s" -> page %d, base %s', $identifier, $rootUid, $bases[0]));
            foreach (array_slice($bases, 1) as $base) {
                $io->writeln('    base variant (Production): ' . $base);
            }
        }

        $io->writeln(sprintf('  settings: agentNexus.storagePid = %d', $storageUid));

        if ($dryRun) {
            return;
        }

        // write() creates the site folder and refreshes the site cache;
        // writeSettings() needs that folder and does not refresh anything, so
        // the cache has to be told about the settings afterwards or the storage
        // pid would only appear on the next flush.
        $this->siteWriter->write($identifier, $configuration);
        $this->siteWriter->writeSettings($identifier, array_replace_recursive(
            $this->existingSettings($identifier),
            ['agentNexus' => ['storagePid' => $storageUid]],
        ));
        $this->eventDispatcher->dispatch(new SiteConfigurationChangedEvent($identifier));
    }

    /**
     * Whatever settings.yaml already holds, read straight from disk.
     *
     * Deliberately not through SiteFinder: resolving a site's settings caches
     * them keyed by the site configuration, so asking before settings.yaml is
     * written would cache the "no storage pid" answer and keep serving it after
     * the file appears — the configuration, and therefore the key, never changed.
     *
     * @return array<string, mixed>
     */
    private function existingSettings(string $identifier): array
    {
        return $this->siteSettingsFactory->loadLocalSettings($identifier) ?? [];
    }

    /**
     * The uid a previous run gave this logical key, or 0.
     */
    private function findSeeded(string $table, string $key): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();

        $uid = $queryBuilder
            ->select('uid')
            ->from($table)
            ->where(
                $queryBuilder->expr()->eq('tx_agentnexus_seed_key', $queryBuilder->createNamedParameter($key)),
                $queryBuilder->expr()->eq('deleted', 0),
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();

        return $uid === false ? 0 : (int)$uid;
    }

    /**
     * @param array<string, array<int|string, array<string, mixed>>> $datamap
     */
    private function data(array $datamap): DataHandler
    {
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        // TYPO3 writes an "autogenerated-<uid>" site configuration whenever
        // DataHandler creates a root page. This command writes the real one a
        // moment later, and two configurations for the same root page make
        // SiteFinder resolve the page to whichever it happens to see last. The
        // importing flag is how Core lets a caller say "I own the site config".
        $dataHandler->isImporting = true;
        $dataHandler->start($datamap, []);
        $dataHandler->process_datamap();
        return $dataHandler;
    }

    /**
     * @param array<string, array<int, array<string, mixed>>> $cmdmap
     */
    private function commands(array $cmdmap): void
    {
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([], $cmdmap);
        $dataHandler->process_cmdmap();
    }

    private function line(string $label, int $uid, string $detail): string
    {
        return sprintf(
            '  %-22s %s%s',
            $label,
            $uid > 0 ? '#' . $uid : '(new)',
            $detail === '' ? '' : '  ' . $detail,
        );
    }
}
