<?php

declare(strict_types=1);

namespace ProvidentiaTest\Integration;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Providentia\DataGovernance\Infrastructure\Doctrine\DbalJsonDataExportGenerator;

final class DataGovernanceExportTest extends TestCase
{
    public function testAccountExportIsBoundedAndExcludesCredentialHashes(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        foreach (
            [
                'CREATE TABLE users (id TEXT, email TEXT, password_hash TEXT, status TEXT,
                    email_verified_at TEXT, created_at TEXT, updated_at TEXT)',
                'CREATE TABLE user_profiles (user_id TEXT, display_name TEXT, locale TEXT,
                    timezone TEXT, created_at TEXT, updated_at TEXT)',
                'CREATE TABLE devices (id TEXT, user_id TEXT, name TEXT, platform TEXT,
                    last_seen_at TEXT, revoked_at TEXT, created_at TEXT)',
                'CREATE TABLE home_memberships (home_id TEXT, user_id TEXT, role TEXT,
                    status TEXT, revision INTEGER, joined_at TEXT, left_at TEXT, updated_at TEXT)',
                'CREATE TABLE catalog_contributions (id TEXT, submitted_by_user_id TEXT,
                    contribution_type TEXT, payload_json TEXT, moderation_status TEXT,
                    revision INTEGER, created_at TEXT)',
            ] as $statement
        ) {
            $connection->executeStatement($statement);
        }
        $connection->insert('users', [
            'id' => 'user-1', 'email' => 'person@example.test', 'password_hash' => 'never-export',
            'status' => 'active', 'email_verified_at' => null, 'created_at' => '2026-08-04',
            'updated_at' => '2026-08-04',
        ]);
        $connection->insert('user_profiles', [
            'user_id' => 'user-1', 'display_name' => 'Person', 'locale' => 'en-NA',
            'timezone' => 'Africa/Windhoek', 'created_at' => '2026-08-04', 'updated_at' => '2026-08-04',
        ]);

        $json = (new DbalJsonDataExportGenerator($connection, 1))->generate([
            'id' => 'request-1', 'scopeType' => 'account', 'subjectUserId' => 'user-1',
        ]);
        /** @var array<string, mixed> $export */
        $export = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('person@example.test', $export['data']['account'][0]['email']);
        self::assertStringNotContainsString('never-export', $json);
    }
}
