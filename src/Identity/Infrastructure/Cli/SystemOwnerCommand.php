<?php

declare(strict_types=1);

namespace Providentia\Identity\Infrastructure\Cli;

use Doctrine\DBAL\Connection;
use Providentia\Identity\Application\EmailCodeService;
use Providentia\SharedKernel\Application\Clock;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'system:owner', description: 'Authorize the first system owner; email-code verification is still required.')]
final class SystemOwnerCommand extends Command
{
    public function __construct(private readonly Connection $connection, private readonly Clock $clock)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('email', InputArgument::REQUIRED, 'Email address of the system owner.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $email = EmailCodeService::normalizeEmail((string) $input->getArgument('email'));
        $existing = $this->connection->fetchOne('SELECT email FROM system_owner_bootstrap WHERE singleton_id = 1');
        if ($existing !== false) {
            $output->writeln($existing === $email ? 'This system owner is already configured.' : '<error>The system owner is already configured; it cannot be replaced by bootstrap.</error>');
            return $existing === $email ? Command::SUCCESS : Command::FAILURE;
        }
        $this->connection->insert('system_owner_bootstrap', ['singleton_id' => 1, 'email' => $email, 'user_id' => null, 'created_at' => $this->clock->now()->format('Y-m-d H:i:s')]);
        $output->writeln('System owner authorized. Sign in to Providentia Admin using the emailed code.');
        return Command::SUCCESS;
    }
}
