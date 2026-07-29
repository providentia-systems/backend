<?php

declare(strict_types=1);

namespace Providentia\SharedKernel\Infrastructure\Cli;

use Providentia\SharedKernel\Application\FoundationProofService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'foundation:prove', description: 'Persist the Doctrine and transactional-outbox proof.')]
final class FoundationProofCommand extends Command
{
    public function __construct(private readonly FoundationProofService $service)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('label', InputArgument::OPTIONAL, 'Non-sensitive proof label.', 'phase-1');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $id = $this->service->prove((string) $input->getArgument('label'));
        $output->writeln('Created foundation proof ' . $id . ' with an atomic outbox event.');

        return Command::SUCCESS;
    }
}
