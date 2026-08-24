<?php

declare(strict_types=1);

namespace ProvidentiaTest\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Providentia\Catalog\Infrastructure\Cli\CatalogRoleCommand;
use Providentia\Identity\Application\PlatformRoleService;
use Providentia\Identity\Infrastructure\Doctrine\DbalIdentityStore;
use Providentia\Identity\Infrastructure\Cli\PlatformRoleCommand;
use Providentia\SharedKernel\Application\TransactionManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class CatalogRoleCommandTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);
        $this->connection->executeStatement(
            'CREATE TABLE users (
                id VARCHAR(36) PRIMARY KEY,
                normalized_email VARCHAR(254) NOT NULL,
                status VARCHAR(16) NOT NULL,
                email_verified_at DATETIME NULL,
                revision INTEGER NOT NULL,
                updated_at DATETIME NOT NULL
            )',
        );
        $this->connection->executeStatement(
            'CREATE TABLE user_platform_roles (
                user_id VARCHAR(36) NOT NULL,
                role VARCHAR(64) NOT NULL,
                granted_at DATETIME NOT NULL,
                revoked_at DATETIME NULL,
                granted_by_user_id VARCHAR(36) NULL,
                source VARCHAR(24) NOT NULL,
                revision INTEGER NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (user_id, role)
            )',
        );
        $this->connection->executeStatement(
            'CREATE TABLE audit_events (
                id VARCHAR(36) PRIMARY KEY,
                home_id VARCHAR(36) NULL,
                actor_user_id VARCHAR(36) NULL,
                action VARCHAR(120) NOT NULL,
                target_type VARCHAR(80) NOT NULL,
                target_id VARCHAR(64) NOT NULL,
                details TEXT NOT NULL,
                occurred_at DATETIME NOT NULL
            )',
        );
        $this->connection->executeStatement(
            'CREATE TABLE platform_administrator_email_grants (
                id VARCHAR(36) PRIMARY KEY,
                normalized_email VARCHAR(254) NOT NULL UNIQUE,
                status VARCHAR(24) NOT NULL,
                source VARCHAR(24) NOT NULL,
                revision INTEGER NOT NULL,
                granted_by_user_id VARCHAR(36) NULL,
                accepted_by_user_id VARCHAR(36) NULL,
                accepted_at DATETIME NULL,
                revoked_at DATETIME NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL
            )',
        );
        $this->connection->insert('users', [
            'id' => '01912345-6789-7abc-8def-0123456789ab',
            'normalized_email' => 'admin@example.test',
            'status' => 'active',
            'email_verified_at' => '2026-08-08 12:00:00',
            'revision' => 1,
            'updated_at' => '2026-08-08 12:00:00',
        ]);
    }

    public function testGrantAndAuditCommitTogether(): void
    {
        $status = $this->tester()->execute([
            '--email' => 'admin@example.test',
            '--role' => 'catalog_curator',
        ]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame(1, (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM user_platform_roles',
        ));
        self::assertSame(1, (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM audit_events',
        ));
    }

    public function testAuditFailureRollsBackTheRoleGrant(): void
    {
        $this->connection->executeStatement(
            "CREATE TRIGGER reject_role_audit
             BEFORE INSERT ON audit_events
             BEGIN
                 SELECT RAISE(FAIL, 'audit rejected');
             END",
        );

        try {
            $this->tester()->execute([
                '--email' => 'admin@example.test',
                '--role' => 'catalog_curator',
            ]);
            self::fail('The forced audit failure did not abort the command.');
        } catch (\Throwable $error) {
            self::assertStringContainsString('audit rejected', $error->getMessage());
        }

        self::assertSame(0, (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM user_platform_roles',
        ));
        self::assertSame(0, (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM audit_events',
        ));
    }

    public function testPlatformAdministratorUsesSafeguardedApiInsteadOfCatalogCommand(): void
    {
        $status = $this->tester()->execute([
            '--email' => 'admin@example.test',
            '--role' => 'platform_administrator',
        ]);

        self::assertSame(Command::INVALID, $status);
        self::assertSame(0, (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM user_platform_roles',
        ));
    }

    public function testOwnerPlatformCommandBootstrapsAnAdministratorThroughTheSharedService(): void
    {
        $status = $this->platformTester()->execute([
            '--email' => 'admin@example.test',
            '--role' => PlatformRoleService::ADMINISTRATOR,
        ]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame(1, (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM user_platform_roles
             WHERE role = :role AND revoked_at IS NULL',
            ['role' => PlatformRoleService::ADMINISTRATOR],
        ));
        self::assertSame('active', $this->connection->fetchOne(
            'SELECT status FROM platform_administrator_email_grants
             WHERE normalized_email = :email',
            ['email' => 'admin@example.test'],
        ));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM audit_events'));
    }

    private function tester(): CommandTester
    {
        return new CommandTester(new CatalogRoleCommand($this->roles()));
    }

    private function platformTester(): CommandTester
    {
        return new CommandTester(new PlatformRoleCommand($this->roles()));
    }

    private function roles(): PlatformRoleService
    {
        $transactions = new class ($this->connection) implements TransactionManager {
            public function __construct(private readonly Connection $connection)
            {
            }

            public function transactional(callable $operation): mixed
            {
                return $this->connection->transactional(static fn (): mixed => $operation());
            }
        };
        return new PlatformRoleService(
            new DbalIdentityStore($this->connection),
            new SequenceUuidGenerator(),
            new CatalogRoleFixedClock(),
            $transactions,
        );
    }
}
