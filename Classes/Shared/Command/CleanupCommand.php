<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Shared\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Webconsulting\AgentNexus\Shared\Configuration\ExtensionSettings;
use Webconsulting\AgentNexus\Shared\Store\ObjectStore;
use Webconsulting\AgentNexus\Shared\Traffic\TrafficRepository;

/**
 * Applies the retention settings: deletes traffic entries and protocol objects
 * older than the configured number of days.
 *
 * Run it daily — from cron, or from the scheduler's "Execute console commands"
 * task, which lists it because it is schedulable.
 */
#[AsCommand(
    name: 'agentnexus:cleanup',
    description: 'Delete Agent Nexus traffic entries and protocol objects older than the retention settings.',
)]
final class CleanupCommand extends Command
{
    private const int DAY = 86400;

    public function __construct(
        private readonly ExtensionSettings $settings,
        private readonly TrafficRepository $traffic,
        private readonly ObjectStore $objects,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addOption('traffic-days', null, InputOption::VALUE_REQUIRED, 'Keep traffic entries for this many days (default: the trafficRetentionDays setting; 0 keeps everything).')
            ->addOption('object-days', null, InputOption::VALUE_REQUIRED, 'Keep protocol objects for this many days (default: the objectRetentionDays setting; 0 keeps everything).')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Count what would be deleted without deleting it.');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool)$input->getOption('dry-run');
        $trafficDays = $this->days($input->getOption('traffic-days'), $this->settings->trafficRetentionDays());
        $objectDays = $this->days($input->getOption('object-days'), $this->settings->objectRetentionDays());
        if ($trafficDays === null || $objectDays === null) {
            $io->error('--traffic-days and --object-days take a whole number of days, 0 or more.');
            return Command::INVALID;
        }

        $now = time();
        $rows = [];
        if ($trafficDays > 0) {
            $before = $now - $trafficDays * self::DAY;
            $count = $dryRun ? $this->traffic->countOlderThan($before) : $this->traffic->deleteOlderThan($before);
            $rows[] = ['Traffic entries', sprintf('older than %d days', $trafficDays), (string)$count];
        } else {
            $rows[] = ['Traffic entries', 'kept (retention 0)', '0'];
        }
        if ($objectDays > 0) {
            $before = $now - $objectDays * self::DAY;
            $count = $dryRun ? $this->objects->countOlderThan($before) : $this->objects->deleteOlderThan($before);
            $rows[] = ['Protocol objects', sprintf('unchanged for %d days', $objectDays), (string)$count];
        } else {
            $rows[] = ['Protocol objects', 'kept (retention 0)', '0'];
        }

        $io->table(['What', 'Rule', $dryRun ? 'Would delete' : 'Deleted'], $rows);
        return Command::SUCCESS;
    }

    private function days(mixed $option, int $default): ?int
    {
        if ($option === null) {
            return $default;
        }
        if (!is_string($option) || preg_match('/^\d+$/', $option) !== 1) {
            return null;
        }
        return (int)$option;
    }
}
