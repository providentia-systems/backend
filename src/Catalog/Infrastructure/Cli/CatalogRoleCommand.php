<?php

declare(strict_types=1);

namespace Providentia\Catalog\Infrastructure\Cli;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Providentia\Catalog\Application\CatalogAuthorization;
use Providentia\SharedKernel\Application\Clock;
use Providentia\SharedKernel\Application\UuidGenerator;
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
        private readonly Connection $connection,
        private readonly Clock $clock,
        private readonly UuidGenerator $ids,
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
            CatalogAuthorization::PLATFORM_ADMINISTRATOR,
            CatalogAuthorization::CURATOR,
            CatalogAuthorization::REVIEWER,
        ];
        if ($email === '' || ! in_array($role, $allowed, true)) {
            $output->writeln('<error>--email and an allowed --role are required.</error>');

            return Command::INVALID;
        }
        $user = $this->connection->fetchAssociative(
            'SELECT id FROM users
             WHERE normalized_email = :email AND status = :status
               AND email_verified_at IS NOT NULL',
            ['email' => $email, 'status' => 'active'],
        );
        if ($user === false) {
            $output->writeln('<error>No active verified account matched.</error>');

            return Command::FAILURE;
        }
        $userId = (string) $user['id'];
        $now = $this->date($this->clock->now());
        $revoke = (bool) $input->getOption('revoke');
        $this->connection->transactional(function (Connection $connection) use (
            $userId,
            $role,
            $revoke,
            $now,
        ): void {
            $existing = $connection->fetchAssociative(
                'SELECT role, revoked_at AS revokedAt FROM user_platform_roles
                 WHERE user_id = :user AND role = :role',
                ['user' => $userId, 'role' => $role],
            );
            if ($revoke) {
                if ($existing !== false && $existing['revokedAt'] === null) {
                    $connection->update(
                        'user_platform_roles',
                        ['revoked_at' => $now],
                        ['user_id' => $userId, 'role' => $role],
                    );
                }
            } elseif ($existing === false) {
                $connection->insert('user_platform_roles', [
                    'user_id' => $userId,
                    'role' => $role,
                    'granted_at' => $now,
                    'revoked_at' => null,
                ]);
            } else {
                $connection->update(
                    'user_platform_roles',
                    ['granted_at' => $now, 'revoked_at' => null],
                    ['user_id' => $userId, 'role' => $role],
                );
            }
            $connection->insert('audit_events', [
                'id' => $this->ids->generate(),
                'home_id' => null,
                'actor_user_id' => null,
                'action' => $revoke ? 'catalog.role.revoked' : 'catalog.role.granted',
                'target_type' => 'user_platform_role',
                'target_id' => $userId,
                'details' => json_encode(['role' => $role], JSON_THROW_ON_ERROR),
                'occurred_at' => $now,
            ]);
        });
        $output->writeln(sprintf(
            '<info>Catalog role %s for user %s.</info>',
            $revoke ? 'revoked' : 'granted',
            $userId,
        ));

        return Command::SUCCESS;
    }

    private function date(DateTimeImmutable $date): string
    {
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
