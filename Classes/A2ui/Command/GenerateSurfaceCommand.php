<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\A2ui\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Webconsulting\AgentNexus\A2ui\Domain\Model\Surface;
use Webconsulting\AgentNexus\A2ui\Domain\Repository\ComponentRegistry;
use Webconsulting\AgentNexus\A2ui\Service\AgentService;

/**
 * Generate an A2UI v1.0 surface for an intent from the CLI.
 *
 * Doubles as headless generation and as a self-test: it prints the surface JSON
 * and validates the protocol invariants (single root, catalog-only components,
 * resolvable child references).
 */
#[AsCommand(
    name: 'a2ui:generate',
    description: 'Generate an A2UI v1.0 surface for a natural-language intent and validate it.',
)]
final class GenerateSurfaceCommand extends Command
{
    public function __construct(
        private readonly AgentService $agentService,
        private readonly ComponentRegistry $registry,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('intent', InputArgument::OPTIONAL, 'What the UI should do', 'create page');
        $this->addOption('offline', null, InputOption::VALUE_NONE, 'Force the built-in generator (skip the LLM)');
        $this->addOption('json-only', null, InputOption::VALUE_NONE, 'Print only the A2UI JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $intent = (string)$input->getArgument('intent');

        if ($input->getOption('offline')) {
            $surface = $this->agentService->generateOffline($intent);
            $source = 'built-in generator (forced offline)';
        } else {
            $result = $this->agentService->generate($intent);
            $surface = $result->getSurface();
            $source = $result->getProvenanceLabel();
            foreach ($result->getNotes() as $note) {
                $io->note($note);
            }
        }

        if ($input->getOption('json-only')) {
            $output->writeln($surface->toJson());
            return Command::SUCCESS;
        }

        $io->title('A2UI surface for: ' . $intent);
        $io->writeln('<info>Source:</info> ' . $source);
        $io->section('Payload');
        $output->writeln($surface->toJson());

        $errors = $this->validate($surface);
        if ($errors === []) {
            $io->success('Valid A2UI v1.0 surface (single root, catalog-only components, resolvable references).');
            return Command::SUCCESS;
        }
        $io->error($errors);
        return Command::FAILURE;
    }

    /**
     * @return array<int, string>
     */
    private function validate(Surface $surface): array
    {
        $errors = [];
        $message = $surface->toMessage();
        if (($message['version'] ?? null) !== Surface::VERSION) {
            $errors[] = 'Missing or wrong version (expected "' . Surface::VERSION . '").';
        }
        if ($surface->getRoot() === null) {
            $errors[] = 'No component with id "root".';
        }

        $ids = [];
        foreach ($surface->getComponents() as $component) {
            $ids[$component->getId()] = true;
        }
        foreach ($surface->getComponents() as $component) {
            if (!$this->registry->isRegistered($component->getComponent())) {
                $errors[] = sprintf('Component "%s" (id %s) is not in the catalog.', $component->getComponent(), $component->getId());
            }
            if (!$this->registry->validate($component)) {
                $errors[] = sprintf('Component id "%s" carries a property outside the allow-list.', $component->getId());
            }
            foreach ($component->getChildren() as $childId) {
                if (!isset($ids[$childId])) {
                    $errors[] = sprintf('Component id "%s" references missing child "%s".', $component->getId(), $childId);
                }
            }
        }
        return $errors;
    }
}
