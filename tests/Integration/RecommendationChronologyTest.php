<?php

declare(strict_types=1);

namespace ProvidentiaTest\Integration;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\Migrations\Configuration\Connection\ExistingConnection;
use Doctrine\Migrations\Configuration\Migration\ConfigurationArray;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\MigratorConfiguration;
use Doctrine\Migrations\Version\Version;
use PHPUnit\Framework\TestCase;
use Providentia\Home\Application\HomePermissionAuthorizer;
use Providentia\Identity\Application\AuthenticatedIdentity;
use Providentia\SharedKernel\Application\Clock;
use Providentia\SharedKernel\Application\TransactionManager;
use Providentia\SharedKernel\Application\UuidGenerator;
use Providentia\Shopping\Application\ShoppingIntelligenceService;
use Providentia\Shopping\Domain\ConsumptionEstimator;
use Providentia\Shopping\Domain\PackOptimizer;
use Providentia\Shopping\Domain\SuggestionEngine;
use Providentia\Shopping\Infrastructure\Doctrine\DbalShoppingIntelligenceStore;

final class RecommendationChronologyTest extends TestCase
{
    private Connection $db;
    private DbalShoppingIntelligenceStore $store;
    private DateTimeImmutable $asOf;

    protected function setUp(): void
    {
        $this->db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $migrations = DependencyFactory::fromConnection(
            new ConfigurationArray(require dirname(__DIR__, 2) . '/config/migrations.php'),
            new ExistingConnection($this->db),
        );
        $migrations->getMetadataStorage()->ensureInitialized();
        $plan = $migrations->getMigrationPlanCalculator()->getPlanUntilVersion(
            new Version('Providentia\Migrations\Version20261022000300'),
        );
        $migrations->getMigrator()->migrate($plan, new MigratorConfiguration());
        $this->store = new DbalShoppingIntelligenceStore($this->db);
        $this->asOf = new DateTimeImmutable('2026-10-22T12:00:00Z');
        $this->db->insert('home_products', [
            'id' => 'rice', 'home_id' => 'home', 'private_name' => 'Rice', 'status' => 'active',
            'unit' => 'kg', 'created_at' => '2026-09-01 12:00:00', 'updated_at' => '2026-09-01 12:00:00',
        ]);
        $this->seedCount('opening', '2026-10-01 12:00:00', '2');
        $this->seedCount('closing', '2026-10-20 12:00:00', '3');
        $this->movement('purchase', '4', '2026-10-10 00:00:00', '2026-10-21 12:00:00');
    }

    public function testLatePurchaseFeedsTheSameCountAwareBalanceAndPersistedUsageExplanation(): void
    {
        $input = $this->store->estimationInputs('home', $this->asOf)[0];
        self::assertSame('3', $input['factualStock']);
        self::assertFalse($input['stockUncertain']);
        $result = $this->service()->generate($this->identity(), 'home', 30);
        self::assertSame('completed', $result['status']);
        self::assertSame(1, $result['suggestionCount']);
        $estimate = $this->store->latestEstimates('home')[0];
        self::assertSame('3', $estimate['evidence']['totalEstimatedUsage']);
        self::assertSame('3', $estimate['evidence']['residualDepletion']);
        self::assertSame('4', $estimate['evidence']['intervals'][0]['incomingQuantity']);
        $suggestion = $this->store->latestSuggestions('home', $this->asOf)[0];
        $explanation = $this->store->explanation('home', (string) $suggestion['id']);
        self::assertNotNull($explanation);
        $usage = array_values(array_filter(
            $explanation['factors'],
            static fn (array $factor): bool => $factor['key'] === 'usage-evidence',
        ))[0];
        self::assertSame(19, $usage['coverageDays']);
        self::assertSame('3', $usage['intervals'][0]['residualDepletion']);
    }

    public function testPartialUnassessedAndIncompatibleCountsNeverSupplyWholeHomeUsage(): void
    {
        $this->seedCount('partial', '2026-10-21 12:00:00', '99', ['location_id' => 'shelf']);
        $this->seedCount('unassessed', '2026-10-21 13:00:00', '88', ['reliability' => 'unassessed']);
        $this->seedCount('incomplete', '2026-10-21 14:00:00', '77', ['scope_complete' => false]);
        $this->db->update('stock_count_lines', ['stock_unit' => 'units'], ['id' => 'closing-line']);
        self::assertCount(1, $this->store->reliableCounts('home', $this->asOf));
        $result = $this->service()->generate($this->identity(), 'home', 14);
        self::assertSame(0, $result['suggestionCount']);
        self::assertFalse($result['diagnostics'][0]['usageEvidenceAvailable']);
    }

    public function testDateCorrectionRecomputesEvidenceWithoutASecondPurchaseAndRespectsAsOf(): void
    {
        $later = $this->asOf->modify('+2 hours');
        $before = $this->store->inputWatermark('home', $later);
        $this->db->insert('stock_purchase_date_corrections', [
            'id' => 'date-correction', 'home_id' => 'home', 'receipt_line_id' => 'purchase',
            'original_purchase_date' => '2026-10-10', 'purchase_date' => '2026-10-22', 'revision' => 1,
            'actor_user_id' => 'actor', 'reason' => 'Verified original receipt',
            'created_at' => '2026-10-22 13:00:00',
        ]);
        self::assertSame('3', $this->store->estimationInputs('home', $this->asOf)[0]['factualStock']);
        self::assertSame('7', $this->store->estimationInputs('home', $later)[0]['factualStock']);
        self::assertCount(1, $this->store->purchaseMovements('home', $later));
        self::assertNotSame($before, $this->store->inputWatermark('home', $later));
    }

    public function testReadRefreshesSameSecondCorrectionsAndPreservesSupersededExplanations(): void
    {
        $service = $this->service();
        $first = $service->generate($this->identity(), 'home', 30);
        $oldSuggestion = $this->store->latestSuggestions('home', $this->asOf)[0];
        $this->db->insert('stock_purchase_date_corrections', [
            'id' => 'same-second-date-correction', 'home_id' => 'home', 'receipt_line_id' => 'purchase',
            'original_purchase_date' => '2026-10-10', 'purchase_date' => '2026-10-22', 'revision' => 1,
            'actor_user_id' => 'actor', 'reason' => 'Correct date after the closing count',
            'created_at' => '2026-10-22 12:00:00',
        ]);

        self::assertSame([], $service->suggestions($this->identity(), 'home'));
        $estimate = $service->estimates($this->identity(), 'home')[0];
        self::assertSame('current', $estimate['freshness']);
        self::assertSame('review_required', $estimate['evidence']['intervals'][0]['status']);
        $explanation = $service->explanation($this->identity(), 'home', (string) $oldSuggestion['id']);
        self::assertSame('superseded', $explanation['freshness']);
        self::assertNotSame($first['id'], $explanation['latestRunId']);
        self::assertSame(2, (int) $this->db->fetchOne('SELECT COUNT(*) FROM shopping_suggestion_runs'));
        self::assertSame(1, (int) $this->db->fetchOne(
            "SELECT COUNT(*) FROM shopping_suggestion_runs WHERE status = 'completed'",
        ));
        self::assertSame(1, (int) $this->db->fetchOne(
            "SELECT COUNT(*) FROM shopping_suggestion_runs WHERE status = 'superseded'",
        ));
        // The read refresh writes derived estimates only, never another stock fact.
        self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM stock_movements'));
    }

    public function testFailedRefreshRollsBackSupersessionAndRetainsEveryHistoricalFact(): void
    {
        $first = $this->service()->generate($this->identity(), 'home', 30);
        $this->movement('later-purchase', '1', '2026-10-22 00:00:00', '2026-10-22 12:00:00');
        // A new deterministic test generator intentionally collides with the first
        // run, reproducing a persistence failure after supersession has started.
        try {
            $this->service()->suggestions($this->identity(), 'home');
            self::fail('A failed derived refresh must be reported.');
        } catch (UniqueConstraintViolationException) {
            $latest = $this->store->latestRun('home');
            self::assertNotNull($latest);
            self::assertSame($first['id'], $latest['id']);
            self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM shopping_suggestion_runs'));
            self::assertSame(2, (int) $this->db->fetchOne('SELECT COUNT(*) FROM stock_movements'));
            self::assertSame(2, (int) $this->db->fetchOne('SELECT COUNT(*) FROM stock_count_lines'));
        }
    }

    public function testAutomaticRefreshRetainsDismissalAcrossDisappearingShortagesUntilExplicitGeneration(): void
    {
        $service = $this->service();
        $service->generate($this->identity(), 'home', 30);
        $suggestion = $this->store->latestSuggestions('home', $this->asOf)[0];
        $service->feedback($this->identity(), 'home', (string) $suggestion['id'], 'dismissed', null, 'Not needed');
        $this->movement('temporary-stock', '100', '2026-10-22 12:00:00', '2026-10-22 12:00:00', 'manual-adjustment');
        self::assertSame([], $service->suggestions($this->identity(), 'home'));
        $this->movement('stock-used', '-100', '2026-10-22 12:00:00', '2026-10-22 12:00:00', 'manual-adjustment');
        self::assertSame([], $service->suggestions($this->identity(), 'home'));
        $current = $this->store->latestRun('home');
        self::assertNotNull($current);
        self::assertSame('dismissed', $current['retainedDecisions']['rice']);

        $service->generate($this->identity(), 'home', 30);
        self::assertCount(1, $service->suggestions($this->identity(), 'home'));
    }

    /** @param array<string, mixed> $overrides */
    private function seedCount(string $id, string $observedAt, string $quantity, array $overrides = []): void
    {
        $this->db->insert('stock_count_sessions', [
            'id' => $id, 'home_id' => 'home', 'location_id' => null, 'status' => 'closed',
            'notes' => '', 'opened_by_user_id' => 'actor', 'opened_at' => $observedAt,
            'closed_by_user_id' => 'actor', 'closed_at' => $observedAt,
            'created_at' => $observedAt, 'updated_at' => $observedAt,
            'scope_complete' => true, 'reliability' => 'reliable', 'observed_at' => $observedAt,
            ...$overrides,
        ]);
        $this->db->insert('stock_count_lines', [
            'id' => $id . '-line', 'home_id' => 'home', 'session_id' => $id, 'home_product_id' => 'rice',
            'quantity' => $quantity, 'source' => 'manual', 'notes' => '', 'status' => 'confirmed',
            'counted_by_user_id' => 'actor', 'created_at' => $observedAt,
            'updated_at' => $observedAt, 'stock_unit' => 'kg',
        ]);
    }

    private function movement(
        string $id,
        string $quantity,
        string $occurredAt,
        string $recordedAt,
        string $sourceType = 'receipt-line',
    ): void {
        $this->db->insert('stock_movements', [
            'id' => $id, 'home_id' => 'home', 'home_product_id' => 'rice',
            'movement_type' => $sourceType === 'receipt-line' ? 'purchase-in' : 'manual-adjustment',
            'quantity_delta' => $quantity, 'source_type' => $sourceType, 'source_id' => $id,
            'reason' => 'Confirmed purchase', 'actor_user_id' => 'actor',
            'occurred_at' => $occurredAt, 'created_at' => $recordedAt,
            'receipt_line_id' => $sourceType === 'receipt-line' ? $id : null, 'stock_unit' => 'kg',
        ]);
    }

    private function identity(): AuthenticatedIdentity
    {
        return new AuthenticatedIdentity('actor', 'session', 'device', 'home', []);
    }

    private function service(): ShoppingIntelligenceService
    {
        $clock = $this->createStub(Clock::class);
        $clock->method('now')->willReturn($this->asOf);
        $ids = $this->createStub(UuidGenerator::class);
        $sequence = 0;
        $ids->method('generate')->willReturnCallback(static function () use (&$sequence): string {
            return 'generated-' . ++$sequence;
        });
        $transactions = $this->createStub(TransactionManager::class);
        $transactions->method('transactional')->willReturnCallback(
            fn (callable $work): mixed => $this->db->transactional(static fn (): mixed => $work()),
        );

        return new ShoppingIntelligenceService(
            $this->store,
            $this->createStub(HomePermissionAuthorizer::class),
            new ConsumptionEstimator(),
            new SuggestionEngine(),
            new PackOptimizer(),
            $ids,
            $clock,
            $transactions,
        );
    }
}
