<?php

declare(strict_types=1);

namespace Providentia\SharedKernel\Infrastructure\Cli;

use Providentia\SharedKernel\Application\Async\OutboxRelay;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'outbox:relay', description: 'Publish committed outbox messages to the queue broker.')]
final class OutboxRelayCommand extends Command
{
    public function __construct(private readonly OutboxRelay $relay)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('once', null, InputOption::VALUE_NONE, 'Process one batch and exit.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        do {
            $result = $this->relay->relayOnce();
            $output->writeln(sprintf(
                'Outbox batch: %d published, %d failed.',
                $result['published'],
                $result['failed'],
            ));
            if (! $input->getOption('once') && $result['published'] + $result['failed'] === 0) {
                usleep(500_000);
            }
        } while (! $input->getOption('once'));

        return Command::SUCCESS;
    }
}
