<?php

declare(strict_types=1);

namespace ProvidentiaTest\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\Migrations\Configuration\Connection\ExistingConnection;
use Doctrine\Migrations\Configuration\Migration\ConfigurationArray;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Exception\AbortMigration;
use Doctrine\Migrations\MigratorConfiguration;
use Doctrine\Migrations\Version\Version;
use PHPUnit\Framework\TestCase;
use Providentia\DataGovernance\Infrastructure\Doctrine\DbalJsonDataExportGenerator;

final class FinishedReceiptCorrectionMigrationTest extends TestCase
{
    public function testMigrationReappliesWhenEmptyAndRefusesToDiscardAuditedPurchases(): void
    {
        $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $previous = 'Providentia\\Migrations\\Version20261022000300';
        $current = 'Providentia\\Migrations\\Version20261026000100';
        $this->migrate($db, $current);
        self::assertTrue($db->createSchemaManager()->tablesExist(['receipt_line_corrections']));
        $this->migrate($db, $previous);
        self::assertFalse($db->createSchemaManager()->tablesExist(['receipt_line_corrections']));
        $this->migrate($db, $current);
        $home = '01912345-6789-7abc-8def-0123456789ab';
        $db->insert('homes', [
            'id' => $home, 'name' => 'Synthetic migration household', 'default_locale' => 'en-NA',
            'default_currency' => 'NAD', 'default_timezone' => 'UTC', 'status' => 'active',
            'created_at' => '2026-10-26 09:00:00', 'updated_at' => '2026-10-26 09:00:00',
        ]);
        $snapshot = '{"id":"01912345-6789-7abc-9def-0123456789ab","quantity":"0.00000001"}';
        $db->insert('receipt_line_corrections', [
            'id' => '01912345-6789-7abc-adef-0123456789ab', 'home_id' => $home,
            'receipt_id' => '01912345-6789-7abc-bdef-0123456789ab',
            'receipt_line_id' => '01912345-6789-7abc-9def-0123456789ab',
            'actor_user_id' => '01912345-6789-7abc-8def-1123456789ab',
            'request_hash' => str_repeat('a', 64), 'reason' => 'Synthetic omitted purchase',
            'receipt_revision' => 4, 'original_line_json' => null, 'next_line_json' => $snapshot,
            'created_at' => '2026-10-26 09:00:00',
        ]);
        $otherHome = '01912345-6789-7abc-8def-2123456789ab';
        $db->executeStatement(
            'INSERT INTO homes (id, name, default_locale, default_currency, default_timezone,
                status, created_at, updated_at)
             SELECT ?, name, default_locale, default_currency, default_timezone,
                status, created_at, updated_at FROM homes WHERE id = ?',
            [$otherHome, $home],
        );
        $db->executeStatement(
            'INSERT INTO receipt_line_corrections
            SELECT ?, ?, receipt_id, receipt_line_id, actor_user_id, request_hash, reason,
                receipt_revision, original_line_json, next_line_json, created_at
            FROM receipt_line_corrections WHERE home_id = ?',
            ['01912345-6789-7abc-8def-3123456789ab', $otherHome, $home]
        );
        $export = json_decode(new DbalJsonDataExportGenerator($db, 1)->generate([
            'id' => '01912345-6789-7abc-8def-4123456789ab', 'scopeType' => 'home', 'homeId' => $home,
        ]), true, 512, JSON_THROW_ON_ERROR);
        self::assertCount(1, $export['data']['receiptLineCorrections']);
        $audit = $export['data']['receiptLineCorrections'][0];
        self::assertSame($home, $audit['home_id']);
        self::assertSame($snapshot, $audit['next_line_json']);
        self::assertSame('Synthetic omitted purchase', $audit['reason']);
        self::assertArrayNotHasKey('request_hash', $audit);
        try {
            $this->migrate($db, $previous);
            self::fail('A downgrade must preserve receipt-line correction evidence.');
        } catch (AbortMigration $error) {
            self::assertStringContainsString('cannot be discarded', $error->getMessage());
        }
        self::assertSame($snapshot, $db->fetchOne(
            'SELECT next_line_json FROM receipt_line_corrections WHERE home_id = ?',
            [$home],
        ));
        self::assertNull($db->fetchOne(
            'SELECT original_line_json FROM receipt_line_corrections WHERE home_id = ?',
            [$home],
        ));
    }

    private function migrate(Connection $db, string $version): void
    {
        $migrations = DependencyFactory::fromConnection(
            new ConfigurationArray(require dirname(__DIR__, 2) . '/config/migrations.php'),
            new ExistingConnection($db),
        );
        $migrations->getMetadataStorage()->ensureInitialized();
        $plan = $migrations->getMigrationPlanCalculator()->getPlanUntilVersion(new Version($version));
        $migrations->getMigrator()->migrate($plan, new MigratorConfiguration());
    }
}
