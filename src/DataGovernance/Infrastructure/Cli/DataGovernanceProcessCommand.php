<?php

declare(strict_types=1);

namespace Providentia\DataGovernance\Infrastructure\Cli;

use Providentia\DataGovernance\Application\DataGovernanceProcessor;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'data-governance:process', description: 'Process queued export and erasure requests.')]
final class DataGovernanceProcessCommand extends Command
{
    public function __construct(private readonly DataGovernanceProcessor $processor)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('once', null, InputOption::VALUE_NONE, 'Process at most one request and exit.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        do {
            $processed = $this->processor->processOnce();
            if ($processed) {
                $output->writeln('Processed one data-governance request.');
            }
        } while (! $input->getOption('once') && $processed);

        return Command::SUCCESS;
    }
}
