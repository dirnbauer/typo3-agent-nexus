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
use Webconsulting\AgentNexus\A2ui\Domain\Model\A2uiVersion;
use Webconsulting\AgentNexus\A2ui\Domain\Model\GenerationRequest;
use Webconsulting\AgentNexus\A2ui\Service\AgentService;
use Webconsulting\AgentNexus\A2ui\Service\MessageBuilder;
use Webconsulting\AgentNexus\A2ui\Service\MessageValidator;

/**
 * Generate the A2UI messages for a request from the command line and check
 * them.
 *
 * Doubles as headless generation and as a self-test: it prints the message
 * list (v0.9.1 by default, `--a2ui-version=v1.0` for the release candidate)
 * and validates it — envelopes, order, the surface id, the basic catalogue and
 * one root. The option is not called `--version`, which every console
 * command already has.
 */
#[AsCommand(
    name: 'a2ui:generate',
    description: 'Generate the A2UI messages for a one-line request and validate them.',
)]
final class GenerateSurfaceCommand extends Command
{
    public function __construct(
        private readonly AgentService $agent,
        private readonly MessageBuilder $messages,
        private readonly MessageValidator $validator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('intent', InputArgument::OPTIONAL, 'What the interface should do', 'A contact form');
        $this->addOption('a2ui-version', null, InputOption::VALUE_REQUIRED, 'A2UI version: v0.9.1 (stable) or v1.0 (release candidate)', A2uiVersion::DEFAULT->value);
        $this->addOption('offline', null, InputOption::VALUE_NONE, 'Use the built-in generator, never a model');
        $this->addOption('json-only', null, InputOption::VALUE_NONE, 'Print only the message list');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $intent = (string)$input->getArgument('intent');
        $version = A2uiVersion::fromWire($input->getOption('a2ui-version'));
        if ($version === null) {
            $io->error('Unknown A2UI version. Use v0.9.1 or v1.0.');
            return Command::INVALID;
        }

        $result = $this->agent->generate(new GenerationRequest($intent, $version, offline: (bool)$input->getOption('offline')));
        $messages = $this->messages->surface($result->surface, $version);
        $json = (string)json_encode($messages, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $errors = $this->validator->validate($messages, $version);

        if ((bool)$input->getOption('json-only')) {
            $output->writeln($json);
            return $errors === [] ? Command::SUCCESS : Command::FAILURE;
        }

        $io->title('A2UI ' . $version->value . ' for: ' . $intent);
        $io->writeln('<info>Surface:</info> ' . $result->surface->surfaceId);
        $io->writeln('<info>Source:</info> ' . $result->label());
        foreach ($result->notes as $note) {
            $io->note($note);
        }
        $io->section(sprintf('%d message(s)', count($messages)));
        $output->writeln($json);

        if ($errors === []) {
            $io->success(sprintf('Valid A2UI %s: envelopes, order, one surface, one root and only the basic catalogue.', $version->value));
            return Command::SUCCESS;
        }
        $io->error($errors);
        return Command::FAILURE;
    }
}
