<?php

declare(strict_types=1);

namespace ProvidentiaTest\Integration;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Providentia\Synchronization\Infrastructure\Doctrine\DbalSyncDeploymentInformation;

final class SyncDeploymentInformationTest extends TestCase
{
    public function testDiagnosticsDistinguishUninitializedPendingCurrentAndDivergentMigrations(): void
    {
        $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $root = dirname(__DIR__, 2);
        $information = new DbalSyncDeploymentInformation($db, $root, 'regression-build', str_repeat('a', 40));
        $uninitialized = $information->information();
        self::assertSame('uninitialized', $uninitialized['migrationStatus']);
        self::assertSame(str_repeat('a', 40), $uninitialized['buildRevision']);
        self::assertSame(
            hash_file('sha256', $root . '/contracts/openapi/providentia-v1.json'),
            $uninitialized['contractHash'],
        );
        self::assertTrue($uninitialized['contractMatchesLock']);
        self::assertNotEmpty($uninitialized['pendingMigrations']);
        $db->executeStatement('CREATE TABLE doctrine_migration_versions (version TEXT PRIMARY KEY)');
        self::assertSame('pending', $information->information()['migrationStatus']);
        foreach ($uninitialized['availableMigrations'] as $version) {
            $db->insert('doctrine_migration_versions', ['version' => $version]);
        }
        $current = $information->information();
        self::assertSame('current', $current['migrationStatus']);
        self::assertSame([], $current['pendingMigrations']);
        self::assertSame([], $current['unknownMigrations']);
        $db->insert('doctrine_migration_versions', ['version' => 'Providentia\\Migrations\\Version20990101000100']);
        $divergent = $information->information();
        self::assertSame('divergent', $divergent['migrationStatus']);
        self::assertSame(['Providentia\\Migrations\\Version20990101000100'], $divergent['unknownMigrations']);
        $development = new DbalSyncDeploymentInformation($db, $root, 'dev', 'unknown');
        self::assertNull($development->information()['buildRevision']);
    }
}
