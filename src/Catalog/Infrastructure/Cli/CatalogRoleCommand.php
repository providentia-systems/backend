<?php

declare(strict_types=1);

namespace Providentia\Catalog\Infrastructure\Cli;

use Providentia\Catalog\Application\CatalogAuthorization;
use Providentia\Identity\Application\PlatformRoleService;
use Providentia\SharedKernel\Application\Problem;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'catalog:role',
    description: 'Grant or revoke a platform catalog role by verified account email.',
)]
final class CatalogRoleCommand extends Command
{
    public function __construct(
        private readonly PlatformRoleService $roles,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('email', null, InputOption::VALUE_REQUIRED, 'Verified account email.')
            ->addOption('role', null, InputOption::VALUE_REQUIRED, 'Platform catalog role.')
            ->addOption('revoke', null, InputOption::VALUE_NONE, 'Revoke instead of grant.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $email = mb_strtolower(trim((string) $input->getOption('email')));
        $role = trim((string) $input->getOption('role'));
        $allowed = [
            CatalogAuthorization::CURATOR,
            CatalogAuthorization::REVIEWER,
        ];
        if ($email === '' || ! in_array($role, $allowed, true)) {
            $output->writeln('<error>--email and an allowed --role are required.</error>');

            return Command::INVALID;
        }
        $revoke = (bool) $input->getOption('revoke');
        try {
            $account = $this->roles->changeVerifiedEmail($email, $role, ! $revoke);
        } catch (Problem $problem) {
            $output->writeln('<error>' . $problem->getMessage() . '</error>');

            return Command::FAILURE;
        }
        $output->writeln(sprintf(
            '<info>Catalog role %s for user %s.</info>',
            $revoke ? 'revoked' : 'granted',
            (string) $account['userId'],
        ));

        return Command::SUCCESS;
    }
}
