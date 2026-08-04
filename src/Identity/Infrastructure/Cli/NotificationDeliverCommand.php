<?php

declare(strict_types=1);

namespace Providentia\Identity\Infrastructure\Cli;

use Providentia\Identity\Application\NotificationDeliveryService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'notification:deliver', description: 'Deliver encrypted transactional notifications.')]
final class NotificationDeliverCommand extends Command
{
    public function __construct(private readonly NotificationDeliveryService $delivery)
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
            $result = $this->delivery->deliverOnce();
            $output->writeln(sprintf(
                'Notification batch: %d sent, %d failed.',
                $result['sent'],
                $result['failed'],
            ));
            if (! $input->getOption('once') && $result['sent'] + $result['failed'] === 0) {
                usleep(500_000);
            }
        } while (! $input->getOption('once'));

        return Command::SUCCESS;
    }
}
