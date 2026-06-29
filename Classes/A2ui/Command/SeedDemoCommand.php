<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\A2ui\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Seed the A2UI Smart Project Inquiry demo plugin onto a page.
 *
 * Idempotent: re-running updates the existing element in place (matched by
 * CType + page) instead of creating duplicates, so the demo is reproducible and
 * safe to wire into a project's seeding routine. desiderio ships as a vendor
 * package, so each protocol extension carries its own seed command rather than
 * editing the shared seeder.
 */
#[AsCommand(
    name: 'a2ui:seed:demo',
    description: 'Place (or refresh) the A2UI Smart Project Inquiry plugin on a page. Idempotent.',
)]
final class SeedDemoCommand extends Command
{
    private const CTYPE = 'agentnexus_inquiry';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('page', 'p', InputOption::VALUE_REQUIRED, 'Target page UID', '747')
            ->addOption('colpos', null, InputOption::VALUE_REQUIRED, 'Column position', '0')
            ->addOption('sorting', null, InputOption::VALUE_REQUIRED, 'Sorting value (lower = higher on page)', '2700')
            ->addOption('header', null, InputOption::VALUE_REQUIRED, 'Headline', 'Tell us what you need');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $page = (int)$input->getOption('page');

        if ($page <= 0) {
            $io->error('A positive --page UID is required.');
            return Command::FAILURE;
        }

        // The Extbase plugin reads its settings from FlexForm (pi_flexform).
        // Leaving pi_flexform unset uses the plugin's sensible FlexForm defaults.
        $fields = [
            'CType' => self::CTYPE,
            'colPos' => (int)$input->getOption('colpos'),
            'sorting' => (int)$input->getOption('sorting'),
            'header' => (string)$input->getOption('header'),
            'header_layout' => '0',
            'tstamp' => time(),
        ];

        $connection = $this->connectionPool->getConnectionForTable('tt_content');
        $existing = $connection->select(['uid'], 'tt_content', [
            'pid' => $page,
            'CType' => self::CTYPE,
            'deleted' => 0,
        ])->fetchOne();

        if ($existing !== false) {
            $connection->update('tt_content', $fields, ['uid' => (int)$existing]);
            $io->success(sprintf('Refreshed A2UI Smart Project Inquiry plugin (uid %d) on page %d.', (int)$existing, $page));
            return Command::SUCCESS;
        }

        $fields['pid'] = $page;
        $fields['crdate'] = time();
        $connection->insert('tt_content', $fields);
        $uid = (int)$connection->lastInsertId();
        $io->success(sprintf('Created A2UI Smart Project Inquiry plugin (uid %d) on page %d.', $uid, $page));

        $this->flushPageCache($page);
        return Command::SUCCESS;
    }

    private function flushPageCache(int $page): void
    {
        try {
            GeneralUtility::makeInstance(\TYPO3\CMS\Core\Cache\CacheManager::class)
                ->getCache('pages')
                ->flushByTag('pageId_' . $page);
        } catch (\Throwable) {
            // best-effort
        }
    }
}
