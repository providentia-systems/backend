<?php

declare(strict_types=1);

namespace ProvidentiaTest\Integration;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Providentia\Home\Application\HomePermission;
use Providentia\Home\Application\HomePermissionAuthorizer;
use Providentia\Identity\Application\AuthenticatedIdentity;
use Providentia\SharedKernel\Application\ChangeFeedWriter;
use Providentia\SharedKernel\Application\Clock;
use Providentia\SharedKernel\Application\Problem;
use Providentia\SharedKernel\Application\TransactionManager;
use Providentia\SharedKernel\Application\UuidGenerator;
use Providentia\Shopping\Application\ShoppingIntelligenceService;
use Providentia\Shopping\Domain\ConsumptionEstimator;
use Providentia\Shopping\Domain\PackOptimizer;
use Providentia\Shopping\Domain\SuggestionEngine;
use Providentia\Shopping\Infrastructure\Doctrine\DbalShoppingIntelligenceStore;

final class StockPreferenceLifecycleTest extends TestCase
{
    private Connection $connection;
    private DbalShoppingIntelligenceStore $store;
    private DateTimeImmutable $at;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->connection->executeStatement(
            'CREATE TABLE home_products (global_category_id TEXT, unit TEXT NOT NULL DEFAULT \'units\',
                id TEXT PRIMARY KEY, home_id TEXT, product_id TEXT, status TEXT)',
        );
        $columns = 'minimum_quantity TEXT, always_keep INTEGER, never_suggest INTEGER,
            preferred_pack_id TEXT, lead_time_days INTEGER, target_coverage_days INTEGER, snooze_until TEXT';
        $this->connection->executeStatement(
            'CREATE TABLE stock_threshold_preferences (home_id TEXT, home_product_id TEXT, '
            . $columns . ', revision INTEGER, updated_at TEXT, PRIMARY KEY (home_id, home_product_id))',
        );
        $this->connection->executeStatement(
            'CREATE TABLE stock_preference_revisions (id TEXT PRIMARY KEY, home_id TEXT, home_product_id TEXT, '
            . $columns . ', revision INTEGER, preference_json TEXT, changed_at TEXT)',
        );
        $this->connection->executeStatement(
            'CREATE TABLE audit_events (id TEXT PRIMARY KEY, home_id TEXT, actor_user_id TEXT,
             action TEXT, target_type TEXT, target_id TEXT, details TEXT, occurred_at TEXT)',
        );
        $this->connection->insert('home_products', [
            'id' => 'product', 'home_id' => 'home', 'product_id' => null, 'status' => 'active',
        ]);
        $this->store = new DbalShoppingIntelligenceStore($this->connection);
        $this->at = new DateTimeImmutable('2026-09-12T12:00:00Z');
    }

    public function testPreferencesPublishAtomicallyAndStaleWritesCannotReplaceThem(): void
    {
        $authorizer = $this->createMock(HomePermissionAuthorizer::class);
        $authorizer->expects(self::exactly(4))->method('requirePermission')->willReturn([]);
        $changes = $this->createMock(ChangeFeedWriter::class);
        $changes
            ->expects(self::exactly(2))
            ->method('put')
            ->willReturnCallback(
                /** @param array<string, mixed> $fields */
                function (string $home, string $actor, string $type, string $id, int $revision, array $fields): int {
                    self::assertTrue($this->connection->isTransactionActive());
                    self::assertSame('shopping-stock-preference', $type);
                    self::assertSame('product', $fields['homeProductId']);
                    $stored = $this->store->preference($home, $id);
                    self::assertNotNull($stored);
                    self::assertSame($revision, (int) $stored['revision']);
                    return $revision;
                },
            );
        $service = $this->service($authorizer, $changes);
        $identity = new AuthenticatedIdentity('actor', 'session', 'device', 'home', []);
        self::assertSame(['revision' => 1], $service->putPreference($identity, 'home', 'product', $this->values(0)));
        $saved = $service->preference($identity, 'home', 'product');
        self::assertSame('4.125', $saved['minimumQuantity']);
        self::assertTrue($saved['alwaysKeep']);
        self::assertFalse($saved['neverSuggest']);
        self::assertSame(2, $saved['leadTimeDays']);
        self::assertSame(1, $saved['revision']);
        self::assertSame(
            ['revision' => 2],
            $service->putPreference(
                $identity,
                'home',
                'product',
                array_replace($this->values(1), ['minimumQuantity' => null]),
            ),
        );
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM stock_preference_revisions'));
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM audit_events'));
        try {
            $service->putPreference($identity, 'home', 'product', $this->values(1));
            self::fail('A stale preference must be rejected.');
        } catch (Problem $problem) {
            self::assertSame(409, $problem->status);
        }
        $stored = $this->store->preference('home', 'product');
        self::assertNotNull($stored);
        self::assertNull($stored['minimumQuantity']);
    }

    /** @return array<string, array{string, string, int}> */
    public static function booleanPreferenceWrites(): array
    {
        return [
            'current insert' => ['stock_threshold_preferences', 'INSERT', 0],
            'current update' => ['stock_threshold_preferences', 'UPDATE', 1],
            'immutable revision insert' => ['stock_preference_revisions', 'INSERT', 0],
        ];
    }

    #[DataProvider('booleanPreferenceWrites')]
    public function testBooleanPreferencesSatisfyStrictDatabaseConstraints(
        string $table,
        string $operation,
        int $expectedRevision,
    ): void {
        $values = array_diff_key($this->values(0), ['expectedRevision' => true]);
        if ($expectedRevision === 1) {
            self::assertTrue($this->connection->transactional(fn (): bool => $this->store->savePreference(
                'home',
                'product',
                'actor',
                'seed-audit',
                'seed-history',
                [...$values, 'alwaysKeep' => true, 'neverSuggest' => true],
                0,
                $this->at,
            )));
        }
        // SQLite otherwise accepts the empty string that an untyped PDO false
        // becomes. Strict MySQL/MariaDB rejects that value for BOOLEAN columns.
        $this->connection->executeStatement(sprintf(
            'CREATE TRIGGER strict_preferences BEFORE %s ON %s '
            . 'WHEN NEW.always_keep NOT IN (0, 1) OR NEW.never_suggest NOT IN (0, 1) '
            . "BEGIN SELECT RAISE(ABORT, 'Invalid boolean preference'); END",
            $operation,
            $table,
        ));
        self::assertTrue($this->connection->transactional(fn (): bool => $this->store->savePreference(
            'home',
            'product',
            'actor',
            'false-audit',
            'false-history',
            [...$values, 'alwaysKeep' => false, 'neverSuggest' => false],
            $expectedRevision,
            $this->at,
        )));
        $saved = $this->store->preference('home', 'product');
        self::assertNotNull($saved);
        self::assertFalse($saved['alwaysKeep']);
        self::assertFalse($saved['neverSuggest']);
        self::assertSame($expectedRevision + 1, $saved['revision']);
        $history = $this->connection->fetchAssociative(
            'SELECT always_keep, never_suggest, preference_json FROM stock_preference_revisions WHERE id = ?',
            ['false-history'],
        );
        self::assertIsArray($history);
        self::assertSame(0, (int) $history['always_keep']);
        self::assertSame(0, (int) $history['never_suggest']);
        $evidence = json_decode((string) $history['preference_json'], true, 512, JSON_THROW_ON_ERROR);
        self::assertFalse($evidence['alwaysKeep']);
        self::assertFalse($evidence['neverSuggest']);
        self::assertTrue($this->connection->transactional(fn (): bool => $this->store->savePreference(
            'home',
            'product',
            'actor',
            'true-audit',
            'true-history',
            [...$values, 'alwaysKeep' => true, 'neverSuggest' => true],
            $expectedRevision + 1,
            $this->at,
        )));
        $updated = $this->store->preference('home', 'product');
        self::assertNotNull($updated);
        self::assertTrue($updated['alwaysKeep']);
        self::assertTrue($updated['neverSuggest']);
    }

    public function testRevokedShoppingPermissionPreventsEveryMutation(): void
    {
        $authorizer = $this->createMock(HomePermissionAuthorizer::class);
        $authorizer
            ->expects(self::once())
            ->method('requirePermission')
            ->with(self::anything(), 'home', HomePermission::SHOPPING_MANAGE)
            ->willThrowException(new Problem(404, 'Unavailable', 'Permission revoked.'));
        $changes = $this->createMock(ChangeFeedWriter::class);
        $changes->expects(self::never())->method('put');
        $this->expectException(Problem::class);
        $this->service($authorizer, $changes)->putPreference(
            new AuthenticatedIdentity('actor', 'session', 'device', 'home', []),
            'home',
            'product',
            $this->values(0),
        );
    }

    private function service(
        HomePermissionAuthorizer $authorizer,
        ChangeFeedWriter $changes,
    ): ShoppingIntelligenceService {
        $clock = $this->createStub(Clock::class);
        $clock->method('now')->willReturn($this->at);
        $transactions = $this->createStub(TransactionManager::class);
        $transactions
            ->method('transactional')
            ->willReturnCallback(
                fn(callable $operation): mixed => $this->connection->transactional(static fn(): mixed => $operation()),
            );
        $ids = $this->createStub(UuidGenerator::class);
        $id = 0;
        $ids->method('generate')->willReturnCallback(static function () use (&$id): string {
            return 'id-' . ++$id;
        });
        return new ShoppingIntelligenceService(
            $this->store,
            $authorizer,
            new ConsumptionEstimator(),
            new SuggestionEngine(),
            new PackOptimizer(),
            $ids,
            $clock,
            $transactions,
            $changes,
        );
    }

    /** @return array<string, mixed> */
    private function values(int $revision): array
    {
        return [
            'minimumQuantity' => '4.125', 'alwaysKeep' => true, 'neverSuggest' => false,
            'preferredPackId' => null, 'leadTimeDays' => 2, 'targetCoverageDays' => 14,
            'snoozeUntil' => null, 'expectedRevision' => $revision,
        ];
    }
}
