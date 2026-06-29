<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Agui\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Seed the AG-UI Live Assistant demo plugin onto a page.
 *
 * Idempotent: re-running updates the existing element in place (matched by
 * CType + page) instead of creating duplicates, so the demo is reproducible and
 * safe to wire into a project's seeding routine. desiderio ships as a vendor
 * package, so each protocol extension carries its own seed command rather than
 * editing the shared seeder.
 */
#[AsCommand(
    name: 'agui:seed:demo',
    description: 'Place (or refresh) the AG-UI Live Assistant plugin on a page. Idempotent.',
)]
final class SeedDemoCommand extends Command
{
    private const CTYPE = 'agentnexus_assistant';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('page', 'p', InputOption::VALUE_REQUIRED, 'Target page UID', '671')
            ->addOption('scenario', 's', InputOption::VALUE_REQUIRED, 'Scenario: plan | support', 'plan')
            ->addOption('colpos', null, InputOption::VALUE_REQUIRED, 'Column position', '0')
            ->addOption('sorting', null, InputOption::VALUE_REQUIRED, 'Sorting value (lower = higher on page)', '2800')
            ->addOption('header', null, InputOption::VALUE_REQUIRED, 'Headline', 'Not sure which plan fits? Ask our live assistant.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $page = (int)$input->getOption('page');
        $scenario = $input->getOption('scenario') === 'support' ? 'support' : 'plan';

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
            $io->success(sprintf('Refreshed AG-UI Live Assistant plugin (uid %d) on page %d [%s].', (int)$existing, $page, $scenario));
            return Command::SUCCESS;
        }

        $fields['pid'] = $page;
        $fields['crdate'] = time();
        $connection->insert('tt_content', $fields);
        $uid = (int)$connection->lastInsertId();
        $io->success(sprintf('Created AG-UI Live Assistant plugin (uid %d) on page %d [%s].', $uid, $page, $scenario));

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
