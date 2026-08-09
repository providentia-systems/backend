<?php

declare(strict_types=1);

namespace ProvidentiaTest\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Providentia\DataGovernance\Infrastructure\Doctrine\DbalDataErasureExecutor;

final class DataErasureExecutorTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);
        foreach ($this->schema() as $statement) {
            $this->connection->executeStatement($statement);
        }
        $this->user('subject', 'person@example.test');
    }

    public function testFinalPlatformAdministratorCannotEraseTheirAccount(): void
    {
        $this->role('subject');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage(
            'Grant another active platform administrator before erasing this account.',
        );

        $this->eraser()->erase([
            'scopeType' => 'account',
            'subjectUserId' => 'subject',
        ]);
    }

    public function testNonFinalAdministratorErasureRetiresCapabilitiesAndPseudonymizesPii(): void
    {
        $this->user('other-admin', 'other@example.test');
        $this->role('subject');
        $this->role('other-admin');
        $this->connection->insert('user_profiles', [
            'user_id' => 'subject',
            'display_name' => 'Person',
        ]);
        $this->connection->insert('auth_login_link_requests', [
            'id' => 'login-request',
            'request_hash' => str_repeat('a', 64),
            'normalized_email' => 'person@example.test',
            'installation_id' => '01912345-6789-7abc-8def-0123456789ab',
            'device_name' => 'Personal phone',
            'platform' => 'android',
            'poll_challenge' => str_repeat('b', 64),
            'code_challenge' => str_repeat('c', 64),
            'state_hash' => str_repeat('d', 64),
            'approval_token_hash' => str_repeat('e', 64),
            'status' => 'pending',
            'user_id' => null,
            'onboarding_home_id' => null,
            'issued_session_id' => null,
            'cancelled_at' => null,
            'updated_at' => '2026-08-09 12:00:00',
        ]);
        $this->connection->insert('home_invitations', [
            'id' => 'invitation',
            'normalized_email' => 'person@example.test',
            'token_hash' => str_repeat('f', 64),
            'status' => 'pending',
            'accepted_by_user_id' => null,
            'revoked_at' => null,
        ]);
        $this->connection->insert('platform_administrator_email_grants', [
            'id' => 'admin-grant',
            'normalized_email' => 'person@example.test',
            'status' => 'active',
            'revision' => 3,
            'granted_by_user_id' => 'other-admin',
            'accepted_by_user_id' => 'subject',
            'revoked_at' => null,
            'updated_at' => '2026-08-09 12:00:00',
        ]);
        $this->connection->insert('audit_events', [
            'id' => 'audit',
            'actor_user_id' => 'subject',
            'details' => '{"email":"person@example.test"}',
        ]);

        $this->eraser()->erase([
            'scopeType' => 'account',
            'subjectUserId' => 'subject',
        ]);

        $user = $this->connection->fetchAssociative(
            'SELECT normalized_email, status FROM users WHERE id = :id',
            ['id' => 'subject'],
        );
        if ($user === false) {
            self::fail('The erased account record is missing.');
        }
        self::assertSame('erased', $user['status']);
        self::assertMatchesRegularExpression('/^erased\+[a-f0-9]{24}@invalid$/', (string) $user['normalized_email']);

        $login = $this->connection->fetchAssociative(
            'SELECT * FROM auth_login_link_requests WHERE id = :id',
            ['id' => 'login-request'],
        );
        if ($login === false) {
            self::fail('The retired login request is missing.');
        }
        self::assertSame('cancelled', $login['status']);
        self::assertNull($login['approval_token_hash']);
        self::assertNull($login['user_id']);
        self::assertSame('Erased device', $login['device_name']);
        self::assertSame('erased', $login['platform']);
        self::assertSame($user['normalized_email'], $login['normalized_email']);
        self::assertNotSame(str_repeat('b', 64), $login['poll_challenge']);

        $grant = $this->connection->fetchAssociative(
            'SELECT * FROM platform_administrator_email_grants WHERE id = :id',
            ['id' => 'admin-grant'],
        );
        if ($grant === false) {
            self::fail('The retired administrator grant is missing.');
        }
        self::assertSame('revoked', $grant['status']);
        self::assertSame(4, (int) $grant['revision']);
        self::assertNull($grant['accepted_by_user_id']);
        self::assertSame($user['normalized_email'], $grant['normalized_email']);
        self::assertSame(0, (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM user_platform_roles WHERE user_id = :user',
            ['user' => 'subject'],
        ));
        self::assertStringNotContainsString(
            'person@example.test',
            (string) $this->connection->fetchOne(
                'SELECT details FROM audit_events WHERE id = :id',
                ['id' => 'audit'],
            ),
        );
    }

    private function eraser(): DbalDataErasureExecutor
    {
        return new DbalDataErasureExecutor($this->connection);
    }

    private function user(string $id, string $email): void
    {
        $this->connection->insert('users', [
            'id' => $id,
            'email' => $email,
            'normalized_email' => $email,
            'password_hash' => 'hash',
            'status' => 'active',
            'email_verified_at' => '2026-08-09 12:00:00',
        ]);
    }

    private function role(string $userId): void
    {
        $this->connection->insert('user_platform_roles', [
            'user_id' => $userId,
            'role' => 'platform_administrator',
            'revoked_at' => null,
            'updated_at' => '2026-08-09 12:00:00',
        ]);
    }

    /** @return list<string> */
    private function schema(): array
    {
        return [
            'CREATE TABLE users (id TEXT PRIMARY KEY, email TEXT, normalized_email TEXT,
                password_hash TEXT, status TEXT, email_verified_at TEXT)',
            'CREATE TABLE user_profiles (user_id TEXT PRIMARY KEY, display_name TEXT)',
            'CREATE TABLE user_platform_roles (user_id TEXT, role TEXT, revoked_at TEXT,
                updated_at TEXT, PRIMARY KEY (user_id, role))',
            'CREATE TABLE home_memberships (home_id TEXT, user_id TEXT, role TEXT, status TEXT)',
            'CREATE TABLE auth_sessions (id TEXT, user_id TEXT)',
            'CREATE TABLE auth_one_time_tokens (id TEXT, user_id TEXT)',
            'CREATE TABLE devices (id TEXT, user_id TEXT)',
            'CREATE TABLE auth_login_link_requests (id TEXT PRIMARY KEY, request_hash TEXT,
                normalized_email TEXT, installation_id TEXT, device_name TEXT, platform TEXT,
                poll_challenge TEXT UNIQUE, code_challenge TEXT, state_hash TEXT,
                approval_token_hash TEXT, status TEXT, user_id TEXT, onboarding_home_id TEXT,
                issued_session_id TEXT, cancelled_at TEXT, updated_at TEXT)',
            'CREATE TABLE home_invitations (id TEXT PRIMARY KEY, normalized_email TEXT,
                token_hash TEXT UNIQUE, status TEXT, accepted_by_user_id TEXT, revoked_at TEXT)',
            'CREATE TABLE platform_administrator_email_grants (id TEXT PRIMARY KEY,
                normalized_email TEXT UNIQUE, status TEXT, revision INTEGER,
                granted_by_user_id TEXT, accepted_by_user_id TEXT, revoked_at TEXT,
                updated_at TEXT)',
            'CREATE TABLE audit_events (id TEXT PRIMARY KEY, actor_user_id TEXT, details TEXT)',
            'CREATE TABLE catalog_contributions (id TEXT, submitted_by_user_id TEXT,
                reviewed_by_user_id TEXT)',
            'CREATE TABLE catalog_consent_receipts (id TEXT, recorded_by_user_id TEXT)',
            'CREATE TABLE catalog_contribution_consents (id TEXT, updated_by_user_id TEXT)',
            'CREATE TABLE data_governance_requests (id TEXT, subject_user_id TEXT,
                requested_by_user_id TEXT)',
        ];
    }
}
