<?php

declare(strict_types=1);

namespace ProvidentiaTest\Integration;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Providentia\Home\Infrastructure\Doctrine\DbalHomeStore;

final class HomeInvitationAuthorityTest extends TestCase
{
    private const HOME_ID = '01912345-6789-7abc-8def-0123456789ab';
    private const INVITER_ID = '01912345-6789-7abc-9def-0123456789ab';
    private const INVITEE_ID = '01912345-6789-7abc-adef-0123456789ab';
    private Connection $connection;
    private DbalHomeStore $store;
    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->connection->executeStatement(
            'CREATE TABLE home_memberships (
                home_id VARCHAR(36) NOT NULL,
                user_id VARCHAR(36) NOT NULL,
                role VARCHAR(16) NOT NULL,
                status VARCHAR(16) NOT NULL,
                revision INTEGER NOT NULL,
                joined_at DATETIME NOT NULL,
                left_at DATETIME NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (home_id, user_id)
            )',
        );
        $this->connection->executeStatement(
            'CREATE TABLE home_invitations (
                id VARCHAR(36) PRIMARY KEY,
                home_id VARCHAR(36) NOT NULL,
                inviter_user_id VARCHAR(36) NOT NULL,
                normalized_email VARCHAR(254) NOT NULL,
                role VARCHAR(16) NOT NULL,
                token_hash VARCHAR(64) NOT NULL,
                status VARCHAR(16) NOT NULL,
                expires_at DATETIME NOT NULL,
                accepted_by_user_id VARCHAR(36) NULL,
                accepted_at DATETIME NULL,
                revoked_at DATETIME NULL,
                revoked_by_user_id VARCHAR(36) NULL,
                revision INTEGER NOT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL
            )',
        );
        $this->connection->executeStatement(
            'CREATE TABLE home_role_policies (
                home_id VARCHAR(36) NOT NULL,
                role VARCHAR(16) NOT NULL,
                revision INTEGER NOT NULL,
                updated_by_user_id VARCHAR(36) NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (home_id, role)
            )',
        );
        $this->connection->executeStatement(
            'CREATE TABLE home_role_permission_grants (
                home_id VARCHAR(36) NOT NULL,
                role VARCHAR(16) NOT NULL,
                permission VARCHAR(80) NOT NULL,
                PRIMARY KEY (home_id, role, permission)
            )',
        );
        $this->connection->insert(
            'home_memberships',
            [
                'home_id' => self::HOME_ID,
                'user_id' => self::INVITER_ID,
                'role' => 'manager',
                'status' => 'active',
                'revision' => 1,
                'joined_at' => '2026-07-30 10:00:00',
                'left_at' => null,
                'updated_at' => '2026-07-30 10:00:00',
            ],
        );
        $this->connection->insert(
            'home_invitations',
            [
                'id' => '01912345-6789-7abc-bdef-0123456789ab',
                'home_id' => self::HOME_ID,
                'inviter_user_id' => self::INVITER_ID,
                'normalized_email' => 'invitee@example.test',
                'role' => 'manager',
                'token_hash' => str_repeat('a', 64),
                'status' => 'pending',
                'expires_at' => '2026-08-01 12:00:00',
                'accepted_by_user_id' => null,
                'accepted_at' => null,
                'revoked_at' => null,
                'revoked_by_user_id' => null,
                'revision' => 1,
                'created_at' => '2026-07-30 10:00:00',
                'updated_at' => '2026-07-30 10:00:00',
            ],
        );
        $this->connection->executeStatement(
            'CREATE TABLE user_emails (user_id VARCHAR(36), normalized_email VARCHAR(254))',
        );
        $this->connection->insert(
            'user_emails',
            [
                'user_id' => self::INVITEE_ID,
                'normalized_email' => 'invitee@example.test',
            ],
        );
        $this->store = new DbalHomeStore($this->connection);
    }

    public function testAcceptanceResolvesTheVerifiedRecipientAndCreatesOneMembership(): void
    {
        $this->connection->update(
            'home_invitations',
            ['role' => 'member'],
            ['id' => '01912345-6789-7abc-bdef-0123456789ab'],
        );
        $accepted = $this->store->acceptInvitation(
            str_repeat('a', 64),
            self::INVITEE_ID,
            'invitee@example.test',
            new DateTimeImmutable('2026-07-30T12:01:00+00:00'),
        );
        if ($accepted === null) {
            self::fail(
                'A manager was unable to grant an ordinary member role.',
            );
        }
        self::assertSame('member', $accepted['role']);
        self::assertSame(
            1,
            (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM home_memberships
                 WHERE home_id = ? AND user_id = ? AND role = ? AND status = ?',
                [
                    self::HOME_ID,
                    self::INVITEE_ID,
                    'member',
                    'active',
                ],
            ),
        );
    }

    public function testAcceptanceFailsAfterInviterMembershipIsRevoked(): void
    {
        $this->connection->update(
            'home_memberships',
            ['status' => 'left', 'role' => 'owner'],
            [
                'home_id' => self::HOME_ID,
                'user_id' => self::INVITER_ID,
            ],
        );
        $result = $this->store->acceptInvitation(
            str_repeat('a', 64),
            self::INVITEE_ID,
            'invitee@example.test',
            new DateTimeImmutable('2026-07-30T12:00:00+00:00'),
        );
        self::assertNull($result);
        self::assertSame(
            'pending',
            $this->connection->fetchOne(
                'SELECT status FROM home_invitations WHERE token_hash = ?',
                [str_repeat('a', 64)],
            ),
        );
    }
}
