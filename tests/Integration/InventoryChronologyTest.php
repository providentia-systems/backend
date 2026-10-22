<?php

declare(strict_types=1);

namespace ProvidentiaTest\Integration;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\Migrations\Configuration\Connection\ExistingConnection;
use Doctrine\Migrations\Configuration\Migration\ConfigurationArray;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\MigratorConfiguration;
use Doctrine\Migrations\Version\Version;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Providentia\DataGovernance\Infrastructure\Doctrine\DbalJsonDataExportGenerator;
use Providentia\Inventory\Infrastructure\Doctrine\DbalInventoryStore;
use Providentia\Inventory\Infrastructure\Doctrine\DbalStockChronologyReader;
use Providentia\Shopping\Domain\ConsumptionEstimator;

final class InventoryChronologyTest extends TestCase
{
    private const HOME = '01912345-6789-7abc-8def-0123456789ab';
    private const OTHER_HOME = '01912345-6789-7abc-8def-1123456789ab';
    private const USER = '01912345-6789-7abc-9def-0123456789ab';
    private const PRODUCT = '01912345-6789-7abc-adef-0123456789ab';
    private const LINE = '01912345-6789-7abc-bdef-0123456789ab';
    private Connection $db;
    private DbalInventoryStore $store;

    protected function setUp(): void
    {
        $this->db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->migrate('Providentia\\Migrations\\Version20261022000300');
        $this->seedHome();
        $this->store = new DbalInventoryStore($this->db);
    }

    /** @return array<string, array{list<string>}> */
    public static function arrivalOrders(): array
    {
        return [
            'chronological' => [['opening', 'purchase', 'closing']],
            'counts first' => [['opening', 'closing', 'purchase']],
            'receipt first' => [['purchase', 'opening', 'closing']],
            'reverse upload' => [['closing', 'purchase', 'opening']],
        ];
    }

    /** @param list<string> $order */
    #[DataProvider('arrivalOrders')]
    public function testLatePurchaseUsesObservedCountsIndependentOfArrivalOrder(array $order): void
    {
        foreach ($order as $fact) {
            match ($fact) {
                'opening' => $this->physicalCount('opening', '2', '2026-10-01T12:00:00Z'),
                'closing' => $this->physicalCount('closing', '3', '2026-10-20T12:00:00Z'),
                'purchase' => $this->purchase('4', '2026-10-10', self::LINE),
                default => throw new \LogicException('Unexpected fixture fact.'),
            };
        }
        self::assertSame('3', $this->balance()['quantity']);
        self::assertFalse($this->balance()['uncertain']);
        $reader = new DbalStockChronologyReader($this->db);
        $counts = array_map(static fn (array $row): array => [
            'at' => new DateTimeImmutable($row['occurredAt']), 'quantity' => $row['quantity'],
            'unit' => $row['unit'], 'calendarDay' => $row['calendarDay'],
        ], $reader->counts(self::HOME, new DateTimeImmutable('2026-10-23T12:00:00Z')));
        $movements = array_map(static fn (array $row): array => [
            'at' => new DateTimeImmutable($row['occurredAt']), 'quantity' => $row['quantity'],
            'unit' => $row['unit'], 'dateOnly' => $row['dateOnly'], 'movementType' => $row['movementType'],
        ], $reader->movements(self::HOME, new DateTimeImmutable('2026-10-23T12:00:00Z')));
        $evidence = (new ConsumptionEstimator())->estimate(
            $counts,
            $movements,
            [],
            new DateTimeImmutable('2026-10-23T12:00:00Z'),
        );
        self::assertSame('3', $evidence['residualDepletion']);
        self::assertSame(1, $evidence['sampleIntervals']);
        $this->purchase('2', '2026-10-22', 'second-line');
        self::assertSame('5', $this->balance()['quantity']);
        $replay = $this->purchase('2', '2026-10-22', 'second-line');
        self::assertTrue($replay['replayed']);
        self::assertSame('5', $this->balance()['quantity']);
        $this->store->rebuildBalances(self::HOME, new DateTimeImmutable('2026-10-23T12:00:00Z'));
        self::assertSame('5', $this->balance()['quantity']);
        self::assertSame(2, (int) $this->db->fetchOne('SELECT COUNT(*) FROM stock_movements'));
        self::assertSame('3', $this->db->fetchOne("SELECT quantity FROM stock_count_lines WHERE id = 'closing-line'"));
    }

    public function testQuantityCancellationAndSameSecondDateCorrectionsDoNotDuplicateLogicalPurchase(): void
    {
        $this->physicalCount('closing', '3', '2026-10-20T12:00:00Z');
        $this->purchase('4', '2026-10-21', self::LINE);
        self::assertSame('7', $this->balance()['quantity']);
        $this->store->correctPurchaseDate(
            self::USER,
            self::HOME,
            self::LINE,
            new DateTimeImmutable('2026-10-10'),
            'z-first-correction',
            'Correct day',
            new DateTimeImmutable('2026-10-23T12:00:00Z')
        );
        self::assertSame('3', $this->balance()['quantity']);
        $this->store->correctPurchaseDate(
            self::USER,
            self::HOME,
            self::LINE,
            new DateTimeImmutable('2026-10-22'),
            'a-second-correction',
            'Correct again',
            new DateTimeImmutable('2026-10-23T12:00:00Z')
        );
        self::assertSame('7', $this->balance()['quantity']);
        self::assertSame('2026-10-21 00:00:00', $this->db->fetchOne('SELECT occurred_at FROM stock_movements'));
        $this->store->appendMovement(
            'correction',
            self::HOME,
            self::PRODUCT,
            'purchase-correction',
            '-1',
            'receipt-line-correction',
            self::LINE . ':2',
            'Quantity corrected',
            self::USER,
            new DateTimeImmutable('2026-10-22'),
            new DateTimeImmutable('2026-10-23T12:00:00Z')
        );
        self::assertSame('6', $this->balance()['quantity']);
        $this->store->appendMovement(
            'cancel',
            self::HOME,
            self::PRODUCT,
            'purchase-correction',
            '-3',
            'receipt-line-correction',
            self::LINE . ':3',
            'Cancelled',
            self::USER,
            new DateTimeImmutable('2026-10-22'),
            new DateTimeImmutable('2026-10-23T12:00:00Z')
        );
        self::assertSame('3', $this->balance()['quantity']);
        $this->store->rebuildBalances(self::HOME, new DateTimeImmutable('2026-10-23T12:00:00Z'));
        self::assertSame('3', $this->balance()['quantity']);
    }

    /** @return array<string, array{string, string, string}> */
    public static function calendarBoundaries(): array
    {
        return [
            'Namibia after midnight' => ['Africa/Windhoek', '2026-10-19T22:30:00Z', '2026-10-20'],
            'negative offset previous day' => ['America/New_York', '2026-10-20T02:30:00Z', '2026-10-19'],
            'DST fall overlap' => ['America/New_York', '2026-11-01T05:30:00Z', '2026-11-01'],
            'DST spring change' => ['America/New_York', '2026-03-08T07:30:00Z', '2026-03-08'],
        ];
    }

    #[DataProvider('calendarBoundaries')]
    public function testDateOnlyPurchaseOnLocalCountDayIsExplicitlyUncertain(
        string $timezone,
        string $observedAt,
        string $purchaseDay,
    ): void {
        $this->db->update('homes', ['default_timezone' => $timezone], ['id' => self::HOME]);
        $this->physicalCount('closing', '3', $observedAt, '2026-12-01T12:00:00Z');
        $this->purchase('4', $purchaseDay, self::LINE, '2026-12-01T12:00:00Z');
        self::assertSame('3', $this->balance()['quantity']);
        self::assertTrue($this->balance()['uncertain']);
        self::assertSame('4', $this->balance()['pendingQuantity']);
        self::assertStringContainsString('before/after', implode(' ', $this->balance()['limitations']));
    }

    public function testObservationCalendarDaySurvivesHouseholdTimezoneChanges(): void
    {
        $this->db->update('homes', ['default_timezone' => 'Africa/Windhoek'], ['id' => self::HOME]);
        $this->physicalCount('closing', '3', '2026-10-19T22:30:00Z');
        $this->purchase('4', '2026-10-20', self::LINE);
        self::assertSame('3', $this->balance()['quantity']);
        self::assertTrue($this->balance()['uncertain']);
        $this->db->update('homes', ['default_timezone' => 'America/New_York'], ['id' => self::HOME]);
        $result = (new DbalStockChronologyReader($this->db))->projection(
            self::HOME,
            self::PRODUCT,
            new DateTimeImmutable('2026-10-23T12:00:00Z'),
        );
        self::assertSame('3', $result['quantity']);
        self::assertTrue($result['uncertain']);
        self::assertSame('4', $result['pendingQuantity']);
        $this->store->rebuildBalances(self::HOME, new DateTimeImmutable('2026-10-23T12:00:00Z'));
        self::assertSame('3', $this->balance()['quantity']);
        $session = $this->store->countSession(self::HOME, 'closing');
        self::assertNotNull($session);
        self::assertSame('Africa/Windhoek', $session['observedTimezone']);
    }

    public function testPartialLocationUnassessedAndOmittedProductDoNotResetWholeHome(): void
    {
        $this->purchase('4', '2026-10-10', self::LINE);
        $at = new DateTimeImmutable('2026-10-23T12:00:00Z');
        $this->store->createLocation('pantry', self::HOME, 'Pantry', 'pantry', 'pantry', $at);
        $this->store->createCountSession(
            'location-count',
            self::HOME,
            'pantry',
            '',
            true,
            'reliable',
            self::USER,
            $at,
            new DateTimeImmutable('2026-10-20T12:00:00Z')
        );
        self::assertTrue($this->store->saveCountLine(
            'location-line',
            self::HOME,
            'location-count',
            self::PRODUCT,
            '1',
            null,
            'manual',
            '',
            self::USER,
            0,
            $at
        ));
        self::assertTrue($this->store->closeCountSession(self::HOME, 'location-count', 2, self::USER, $at));
        self::assertSame('4', $this->balance()['quantity']);
        $this->physicalCount('unassessed', '1', '2026-10-20T12:00:00Z', '2026-10-23T12:00:00Z', false, 'unassessed');
        self::assertSame('4', $this->balance()['quantity']);
        self::assertSame([], (new DbalStockChronologyReader($this->db))->counts(self::HOME, $at));
        $this->store->createHomeProduct('other-product', self::HOME, null, null, 'Other', 'other', null, null, $at);
        $this->store->createCountSession(
            'omitted',
            self::HOME,
            null,
            '',
            true,
            'reliable',
            self::USER,
            $at,
            new DateTimeImmutable('2026-10-20T12:00:00Z')
        );
        self::assertTrue($this->store->saveCountLine(
            'other-line',
            self::HOME,
            'omitted',
            'other-product',
            '0',
            null,
            'manual',
            '',
            self::USER,
            0,
            $at
        ));
        self::assertTrue($this->store->closeCountSession(self::HOME, 'omitted', 2, self::USER, $at));
        self::assertSame('4', $this->balance()['quantity']);
    }

    public function testAuditedCountReviewPreservesOriginalAndHistoricalCutoffAndHomeIsolation(): void
    {
        $this->physicalCount('closing', '3', '2026-10-20T12:00:00Z');
        $reviewed = new DateTimeImmutable('2026-10-24T12:00:00Z');
        self::assertTrue($this->db->transactional(fn (): bool => $this->store->reviewCountSession(
            'review',
            self::HOME,
            'closing',
            3,
            self::USER,
            'Corrected physical observation',
            true,
            'reliable',
            new DateTimeImmutable('2026-10-20T12:00:00Z'),
            [['id' => 'closing-line', 'quantity' => '2', 'expectedRevision' => 1]],
            $reviewed,
        )));
        self::assertSame('2', $this->balance()['quantity']);
        $reader = new DbalStockChronologyReader($this->db);
        self::assertSame('3', $reader->projection(
            self::HOME,
            self::PRODUCT,
            new DateTimeImmutable('2026-10-23T12:00:00Z'),
        )['quantity']);
        self::assertSame('2', $reader->projection(self::HOME, self::PRODUCT, $reviewed)['quantity']);
        self::assertFalse($this->store->reviewCountSession(
            'stale',
            self::HOME,
            'closing',
            3,
            self::USER,
            'Stale',
            true,
            'reliable',
            new DateTimeImmutable('2026-10-20T12:00:00Z'),
            [],
            $reviewed
        ));
        self::assertNull($this->store->countSession(self::OTHER_HOME, 'closing'));
        $reviewedSession = $this->store->countSession(self::HOME, 'closing');
        self::assertNotNull($reviewedSession);
        $history = $reviewedSession['reviews'];
        self::assertCount(1, $history);
        self::assertSame('3', $history[0]['previousSnapshot']['lines'][0]['quantity']);
        self::assertSame('2', $history[0]['nextSnapshot']['lines'][0]['quantity']);
        self::assertArrayNotHasKey('reviews', $history[0]['previousSnapshot']['session']);
        $json = (new DbalJsonDataExportGenerator($this->db))->generate([
            'id' => 'export', 'scopeType' => 'home', 'homeId' => self::HOME,
        ]);
        $export = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        self::assertCount(1, $export['data']['countReviews']);
        self::assertStringContainsString(
            '"quantity":"3"',
            $export['data']['countReviews'][0]['previous_snapshot_json'],
        );
        $other = json_decode((new DbalJsonDataExportGenerator($this->db))->generate([
            'id' => 'export-other', 'scopeType' => 'home', 'homeId' => self::OTHER_HOME,
        ]), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame([], $other['data']['countReviews']);
    }

    public function testStockUnitCannotBeRelabelledAfterEvidenceAndCorrectionsRetainPurchaseUnit(): void
    {
        $this->purchase('4', '2026-10-10', self::LINE);
        $result = $this->db->transactional(fn (): array => $this->store->updateHomeProduct(
            self::HOME,
            self::PRODUCT,
            false,
            null,
            null,
            false,
            null,
            false,
            null,
            null,
            1,
            new DateTimeImmutable('2026-10-23T12:00:00Z'),
            false,
            null,
            'l',
        ));
        self::assertSame('unit-in-use', $result['status']);
        self::assertSame('kg', $this->db->fetchOne(
            'SELECT unit FROM home_products WHERE id = :id',
            ['id' => self::PRODUCT],
        ));
        self::assertSame('4', $this->balance()['quantity']);
        $this->store->appendMovement(
            'remove',
            self::HOME,
            self::PRODUCT,
            'purchase-correction',
            '-4',
            'receipt-line-correction',
            self::LINE . ':2',
            'Removed purchase',
            self::USER,
            new DateTimeImmutable('2026-10-10'),
            new DateTimeImmutable('2026-10-23T12:00:00Z')
        );
        self::assertSame('kg', $this->db->fetchOne("SELECT stock_unit FROM stock_movements WHERE id = 'remove'"));
        self::assertSame('0', $this->balance()['quantity']);
        $this->store->rebuildBalances(self::HOME, new DateTimeImmutable('2026-10-23T12:00:00Z'));
        self::assertSame('0', $this->balance()['quantity']);
    }

    public function testMigrationPreservesLegacyCountProjectionAndMarksLateFactsPendingUntilReview(): void
    {
        $this->db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->migrate('Providentia\\Migrations\\Version20261013000100');
        $this->seedHome();
        foreach (
            [
            ['old-purchase', '10', 'purchase-in'], ['old-count-delta', '-7', 'count-reconciliation'],
            ] as [$id, $delta, $type]
        ) {
            $this->db->insert('stock_movements', [
                'id' => $id, 'home_id' => self::HOME, 'home_product_id' => self::PRODUCT,
                'movement_type' => $type, 'quantity_delta' => $delta, 'source_type' => 'legacy', 'source_id' => $id,
                'reason' => 'Historical evidence', 'actor_user_id' => self::USER,
                'occurred_at' => '2026-10-20 12:00:00',
                'created_at' => '2026-10-20 12:00:00',
            ]);
        }
        $this->db->insert('inventory_balances', ['home_id' => self::HOME, 'home_product_id' => self::PRODUCT,
            'quantity' => '3', 'last_movement_id' => 'old-count-delta', 'revision' => 2,
            'updated_at' => '2026-10-20 12:00:00']);
        $this->migrate('Providentia\\Migrations\\Version20261022000300');
        $this->store = new DbalInventoryStore($this->db);
        $baseline = $this->db->fetchAssociative('SELECT * FROM stock_projection_baselines');
        self::assertNotFalse($baseline);
        self::assertSame('3', $baseline['quantity']);
        $historical = (new DbalStockChronologyReader($this->db))->projection(
            self::HOME,
            self::PRODUCT,
            new DateTimeImmutable('2026-10-20T18:00:00Z'),
        );
        self::assertSame('3', $historical['quantity']);
        self::assertTrue($historical['uncertain']);
        self::assertStringContainsString('Historical stock', implode(' ', $historical['limitations']));
        $baselineAt = new DateTimeImmutable($baseline['recorded_at']);
        $after = $baselineAt->modify('+2 days');
        $this->purchase('4', '2026-10-10', self::LINE, $after->format(DATE_ATOM));
        self::assertSame('3', $this->balance()['quantity']);
        self::assertSame('4', $this->balance()['pendingQuantity']);
        self::assertTrue($this->balance()['uncertain']);
        $later = $baselineAt->modify('+3 days');
        $this->purchase('2', $later->format('Y-m-d'), 'new-purchase', $later->format(DATE_ATOM));
        self::assertSame('5', $this->balance()['quantity']);
        $this->store->rebuildBalances(self::HOME, $later);
        $this->store->rebuildBalances(self::HOME, $later);
        self::assertSame('5', $this->balance()['quantity']);
        $latest = $baselineAt->modify('+4 days');
        $this->physicalCount('reviewed', '5', $latest->format(DATE_ATOM), $latest->format(DATE_ATOM));
        self::assertSame('5', $this->balance()['quantity']);
        self::assertFalse($this->balance()['uncertain']);
        self::assertSame('3', $this->db->fetchOne('SELECT quantity FROM stock_projection_baselines'));
    }

    public function testDowngradeRefusesToDiscardNewPhysicalObservationWithoutAnyCorrections(): void
    {
        $this->physicalCount('closing', '3', '2026-10-20T12:00:00Z');
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM stock_count_reviews'));
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM stock_purchase_date_corrections'));
        $this->expectException(\Doctrine\Migrations\Exception\AbortMigration::class);
        $this->migrate('Providentia\\Migrations\\Version20261013000100');
    }

    private function seedHome(): void
    {
        $this->db->insert('homes', ['id' => self::HOME, 'name' => 'Pantry', 'default_locale' => 'en-NA',
            'default_currency' => 'NAD', 'default_timezone' => 'UTC', 'status' => 'active',
            'created_at' => '2026-10-01 00:00:00', 'updated_at' => '2026-10-01 00:00:00']);
        $this->db->insert('users', ['id' => self::USER, 'email' => 'pantry@example.test',
            'normalized_email' => 'pantry@example.test', 'status' => 'active',
            'created_at' => '2026-10-01 00:00:00', 'updated_at' => '2026-10-01 00:00:00']);
        (new DbalInventoryStore($this->db))->createHomeProduct(
            self::PRODUCT,
            self::HOME,
            null,
            null,
            'Rice',
            'rice',
            null,
            null,
            new DateTimeImmutable('2026-10-01T00:00:00Z'),
            null,
            'kg'
        );
    }

    private function physicalCount(
        string $id,
        string $quantity,
        string $observedAt,
        string $recordedAt = '2026-10-23T12:00:00Z',
        bool $complete = true,
        string $reliability = 'reliable'
    ): void {
        $at = new DateTimeImmutable($recordedAt);
        $this->db->transactional(function () use ($id, $quantity, $observedAt, $at, $complete, $reliability): void {
            $this->store->createCountSession(
                $id,
                self::HOME,
                null,
                '',
                $complete,
                $reliability,
                self::USER,
                $at,
                new DateTimeImmutable($observedAt),
            );
            self::assertTrue($this->store->saveCountLine(
                $id . '-line',
                self::HOME,
                $id,
                self::PRODUCT,
                $quantity,
                null,
                'manual',
                '',
                self::USER,
                0,
                $at
            ));
            self::assertTrue($this->store->closeCountSession(self::HOME, $id, 2, self::USER, $at));
        });
    }

    /** @return array<string, mixed> */
    private function purchase(
        string $quantity,
        string $day,
        string $line,
        string $recordedAt = '2026-10-23T12:00:00Z',
    ): array {
        return $this->db->transactional(fn (): array => $this->store->appendMovement(
            $line . '-movement',
            self::HOME,
            self::PRODUCT,
            'purchase-in',
            $quantity,
            'receipt-line',
            $line,
            'Confirmed purchase',
            self::USER,
            new DateTimeImmutable($day),
            new DateTimeImmutable($recordedAt),
        ));
    }

    /** @return array<string, mixed> */
    private function balance(): array
    {
        return $this->store->balance(self::HOME, self::PRODUCT) ?? [];
    }

    private function migrate(string $version): void
    {
        $migrations = DependencyFactory::fromConnection(
            new ConfigurationArray(require dirname(__DIR__, 2) . '/config/migrations.php'),
            new ExistingConnection($this->db),
        );
        $migrations->getMetadataStorage()->ensureInitialized();
        $plan = $migrations->getMigrationPlanCalculator()->getPlanUntilVersion(new Version($version));
        $migrations->getMigrator()->migrate($plan, new MigratorConfiguration());
    }
}
