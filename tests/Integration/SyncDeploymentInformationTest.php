<?php

declare(strict_types=1);

namespace ProvidentiaTest\Integration;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Providentia\Synchronization\Infrastructure\Doctrine\DbalSyncDeploymentInformation;

final class SyncDeploymentInformationTest extends TestCase
{
    private const CONTRACT = '{"openapi":"3.1.0","info":{"version":"diagnostics-fixture"},"paths":{}}';
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/providentia-sync-diagnostics-' . bin2hex(random_bytes(12));
        self::assertTrue(mkdir($this->root . '/contracts/openapi', 0700, true));
        self::assertTrue(mkdir($this->root . '/migrations', 0700));
        self::assertNotFalse(file_put_contents($this->contractPath(), self::CONTRACT));
        self::assertNotFalse(file_put_contents(
            $this->root . '/contracts/openapi/contract.lock.json',
            json_encode(['artifacts' => ['providentia-v1.json' => [
                'sha256' => hash('sha256', self::CONTRACT),
            ]]], JSON_THROW_ON_ERROR),
        ));
        foreach (['Version20261022000100', 'Version20261022000200'] as $migration) {
            self::assertNotFalse(file_put_contents($this->root . '/migrations/' . $migration . '.php', ''));
        }
    }

    protected function tearDown(): void
    {
        foreach (
            [
            $this->contractPath(),
            $this->root . '/contracts/openapi/contract.lock.json',
            $this->root . '/migrations/Version20261022000100.php',
            $this->root . '/migrations/Version20261022000200.php',
            ] as $file
        ) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        rmdir($this->root . '/contracts/openapi');
        rmdir($this->root . '/contracts');
        rmdir($this->root . '/migrations');
        rmdir($this->root);
    }

    public function testDiagnosticsDistinguishUninitializedPendingCurrentAndDivergentMigrations(): void
    {
        $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $information = new DbalSyncDeploymentInformation($db, $this->root, 'regression-build', str_repeat('a', 40));
        $uninitialized = $information->information();
        self::assertSame('uninitialized', $uninitialized['migrationStatus']);
        self::assertSame(str_repeat('a', 40), $uninitialized['buildRevision']);
        self::assertSame(hash('sha256', self::CONTRACT), $uninitialized['contractHash']);
        self::assertTrue($uninitialized['contractMatchesLock']);
        self::assertSame([
            'Providentia\\Migrations\\Version20261022000100',
            'Providentia\\Migrations\\Version20261022000200',
        ], $uninitialized['pendingMigrations']);
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
        $development = new DbalSyncDeploymentInformation($db, $this->root, 'dev', 'unknown');
        self::assertNull($development->information()['buildRevision']);
    }

    public function testMissingAndChangedCanonicalFilesDoNotClaimLockAgreement(): void
    {
        $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $information = new DbalSyncDeploymentInformation($db, $this->root, 'regression-build', 'unknown');
        self::assertTrue(unlink($this->contractPath()));
        $missing = $information->information();
        self::assertNull($missing['contractHash']);
        self::assertFalse($missing['contractMatchesLock']);

        $changedContract = self::CONTRACT . "\n";
        self::assertNotFalse(file_put_contents($this->contractPath(), $changedContract));
        $changed = $information->information();
        self::assertSame(hash('sha256', $changedContract), $changed['contractHash']);
        self::assertFalse($changed['contractMatchesLock']);
    }

    private function contractPath(): string
    {
        return $this->root . '/contracts/openapi/providentia-v1.json';
    }
}
