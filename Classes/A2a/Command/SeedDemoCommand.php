<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\A2a\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Seed the A2A Concierge demo plugin onto a page. Idempotent: re-running
 * refreshes the existing element (matched by CType + page) instead of duplicating.
 */
#[AsCommand(
    name: 'a2a:seed:demo',
    description: 'Place (or refresh) the A2A Concierge plugin on a page. Idempotent.',
)]
final class SeedDemoCommand extends Command
{
    private const CTYPE = 'agentnexus_concierge';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('page', 'p', InputOption::VALUE_REQUIRED, 'Target page UID', '671')
            ->addOption('colpos', null, InputOption::VALUE_REQUIRED, 'Column position', '0')
            ->addOption('sorting', null, InputOption::VALUE_REQUIRED, 'Sorting value (lower = higher on page)', '2900')
            ->addOption('header', null, InputOption::VALUE_REQUIRED, 'Headline', 'Delegate a task to our site agent');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $page = (int)$input->getOption('page');
        if ($page <= 0) {
            $io->error('A positive --page UID is required.');
            return Command::FAILURE;
        }

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
            $io->success(sprintf('Refreshed A2A Concierge (uid %d) on page %d.', (int)$existing, $page));
            return Command::SUCCESS;
        }

        $fields['pid'] = $page;
        $fields['crdate'] = time();
        $connection->insert('tt_content', $fields);
        $uid = (int)$connection->lastInsertId();
        $io->success(sprintf('Created A2A Concierge (uid %d) on page %d.', $uid, $page));

        try {
            GeneralUtility::makeInstance(\TYPO3\CMS\Core\Cache\CacheManager::class)
                ->getCache('pages')->flushByTag('pageId_' . $page);
        } catch (\Throwable) {
        }
        return Command::SUCCESS;
    }
}
