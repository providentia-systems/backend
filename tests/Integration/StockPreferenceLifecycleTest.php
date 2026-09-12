<?php

declare(strict_types=1);

namespace ProvidentiaTest\Integration;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
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
            'CREATE TABLE home_products (id TEXT PRIMARY KEY, home_id TEXT, product_id TEXT, status TEXT)',
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
        $changes->expects(self::exactly(2))->method('put')->willReturnCallback(
            /** @param array<string, mixed> $fields */
            function (string $home, string $actor, string $type, string $id, int $revision, array $fields): void {
                self::assertTrue($this->connection->isTransactionActive());
                self::assertSame('shopping-stock-preference', $type);
                self::assertSame('product', $fields['homeProductId']);
                self::assertSame($revision, (int) $this->store->preference($home, $id)['revision']);
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
        self::assertSame(['revision' => 2], $service->putPreference(
            $identity, 'home', 'product', array_replace($this->values(1), ['minimumQuantity' => null]),
        ));
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM stock_preference_revisions'));
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM audit_events'));
        try {
            $service->putPreference($identity, 'home', 'product', $this->values(1));
            self::fail('A stale preference must be rejected.');
        } catch (Problem $problem) {
            self::assertSame(409, $problem->status);
        }
        self::assertNull($this->store->preference('home', 'product')['minimumQuantity']);
    }

    public function testRevokedShoppingPermissionPreventsEveryMutation(): void
    {
        $authorizer = $this->createMock(HomePermissionAuthorizer::class);
        $authorizer->expects(self::once())->method('requirePermission')
            ->with(self::anything(), 'home', HomePermission::SHOPPING_MANAGE)
            ->willThrowException(new Problem(404, 'Unavailable', 'Permission revoked.'));
        $changes = $this->createMock(ChangeFeedWriter::class);
        $changes->expects(self::never())->method('put');
        $this->expectException(Problem::class);
        $this->service($authorizer, $changes)->putPreference(
            new AuthenticatedIdentity('actor', 'session', 'device', 'home', []),
            'home', 'product', $this->values(0),
        );
    }

    private function service(
        HomePermissionAuthorizer $authorizer,
        ChangeFeedWriter $changes,
    ): ShoppingIntelligenceService {
        $clock = $this->createStub(Clock::class);
        $clock->method('now')->willReturn($this->at);
        $transactions = $this->createStub(TransactionManager::class);
        $transactions->method('transactional')->willReturnCallback(
            fn (callable $operation): mixed => $this->connection->transactional($operation),
        );
        $ids = $this->createStub(UuidGenerator::class);
        $id = 0;
        $ids->method('generate')->willReturnCallback(static function () use (&$id): string {
            return 'id-' . ++$id;
        });
        return new ShoppingIntelligenceService(
            $this->store, $authorizer, new ConsumptionEstimator(), new SuggestionEngine(),
            new PackOptimizer(), $ids, $clock, $transactions, $changes,
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
