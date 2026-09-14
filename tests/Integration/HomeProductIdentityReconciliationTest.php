<?php

declare(strict_types=1);

namespace ProvidentiaTest\Integration;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Providentia\Home\Application\HomeStore;
use Providentia\Inventory\Application\HomeProductIdentityReconciler;
use Providentia\Inventory\Infrastructure\Doctrine\DbalHomeProductIdentityRepairStore;
use Providentia\Inventory\Infrastructure\Doctrine\DbalHomeProductSyncNormalizer;
use Providentia\SharedKernel\Application\ChangeFeedWriter;
use Providentia\SharedKernel\Application\Clock;
use Providentia\SharedKernel\Application\TransactionManager;
use Providentia\Synchronization\Infrastructure\Doctrine\DbalChangeFeedWriter;
use Providentia\Synchronization\Infrastructure\Doctrine\DbalSyncStore;

final class HomeProductIdentityReconciliationTest extends TestCase
{
    private Connection $db;
    private DateTimeImmutable $at;

    protected function setUp(): void
    {
        $this->db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->at = new DateTimeImmutable('2026-09-14T12:00:00Z');
        foreach (
            [
            'CREATE TABLE products (id TEXT PRIMARY KEY, canonical_name TEXT NOT NULL)',
            'CREATE TABLE product_packs (id TEXT PRIMARY KEY, product_id TEXT NOT NULL)',
            'CREATE TABLE home_products (id TEXT PRIMARY KEY, home_id TEXT NOT NULL, product_id TEXT,
             pack_id TEXT, private_name TEXT, original_pack_text TEXT, home_category_id TEXT,
             status TEXT, revision INTEGER NOT NULL, updated_at TEXT)',
            'CREATE TABLE stock_history (id TEXT PRIMARY KEY, home_product_id TEXT, quantity TEXT,
             raw_description TEXT)',
            'CREATE TABLE change_log (sequence_id INTEGER PRIMARY KEY AUTOINCREMENT, home_id TEXT,
             entity_type TEXT, entity_id TEXT, operation_type TEXT, revision INTEGER, payload_schema_version INTEGER,
             payload_json TEXT, changed_by_user_id TEXT, changed_at TEXT)',
            'CREATE TABLE outbox_messages (id TEXT PRIMARY KEY, message_type TEXT, queue_name TEXT, payload TEXT,
             occurred_at TEXT, available_at TEXT, published_at TEXT, attempts INTEGER, last_error TEXT, status TEXT)',
            ] as $sql
        ) {
            $this->db->executeStatement($sql);
        }
        $this->db->insert('products', ['id' => 'family', 'canonical_name' => 'Synthetic beans']);
        foreach (['pack-one', 'pack-two'] as $id) {
            $this->db->insert('product_packs', ['id' => $id, 'product_id' => 'family']);
        }
        $this->product('01-partial', null, 'pack-two');
        $this->product('02-family', 'family', null);
        $this->product('03-private', null, null);
        $this->product('04-ambiguous', 'other-parent', 'pack-one');
        $this->product('05-foreign', null, 'pack-one', 'other-home');
        $this->db->insert('stock_history', [
            'id' => 'movement', 'home_product_id' => '01-partial',
            'quantity' => '7.50000000', 'raw_description' => 'Original receipt description',
        ]);
        $writer = new DbalChangeFeedWriter($this->db, new SequenceUuidGenerator());
        $writer->put('home', 'owner', 'inventory-home-product', '01-partial', 5, [
            'productId' => null, 'packId' => 'pack-two', 'originalPackText' => 'original 2x wording',
        ], $this->at);
    }

    public function testDryRunApplyAndReplayPreserveIdentityTextQuantitiesAndHistory(): void
    {
        $service = $this->service();
        $history = $this->db->fetchAllAssociative('SELECT * FROM stock_history');
        $originalEvent = $this->db->fetchAssociative('SELECT * FROM change_log WHERE sequence_id = 1');
        $before = $this->db->fetchAllAssociative('SELECT * FROM home_products ORDER BY id');
        $preview = $service->run('home', null, 100);
        self::assertTrue($preview['dryRun']);
        self::assertSame(4, $preview['scanned']);
        self::assertSame($before, $this->db->fetchAllAssociative('SELECT * FROM home_products ORDER BY id'));
        self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM change_log'));
        self::assertStringNotContainsString('Original receipt', json_encode($preview, JSON_THROW_ON_ERROR));
        $apply = $service->run('home', null, 100, 'owner');
        self::assertSame(
            ['updated',
            'updated',
            'updated',
            'manual_review'],
            array_column(
                $apply['records'],
                'status'
            )
        );
        self::assertSame(
            'family',
            $this->db->fetchOne("SELECT product_id FROM home_products WHERE id = '01-partial'")
        );
        self::assertSame(
            'pack-two',
            $this->db->fetchOne("SELECT pack_id FROM home_products WHERE id = '01-partial'")
        );
        self::assertNull($this->db->fetchOne("SELECT pack_id FROM home_products WHERE id = '02-family'"));
        self::assertSame(
            'original 2x wording',
            $this->db->fetchOne("SELECT original_pack_text FROM home_products WHERE id = '01-partial'")
        );
        self::assertSame($history, $this->db->fetchAllAssociative('SELECT * FROM stock_history'));
        self::assertSame(
            $originalEvent,
            $this->db->fetchAssociative('SELECT * FROM change_log WHERE sequence_id = 1')
        );
        self::assertSame(
            $before[4],
            $this->db->fetchAssociative("SELECT * FROM home_products WHERE id = '05-foreign'")
        );
        $count = (int) $this->db->fetchOne('SELECT COUNT(*) FROM change_log');
        $replay = $service->run('home', null, 100, 'owner');
        self::assertSame(
            ['unchanged',
            'unchanged',
            'unchanged',
            'manual_review'],
            array_column(
                $replay['records'],
                'status'
            )
        );
        self::assertSame($count, (int) $this->db->fetchOne('SELECT COUNT(*) FROM change_log'));
    }

    public function testPagingAndStaleRevisionDoNotChooseAPack(): void
    {
        $first = $this->service()->run('home', null, 1);
        self::assertTrue($first['hasMore']);
        self::assertSame('01-partial', $first['nextAfterId']);
        $second = $this->service()->run('home', '01-partial', 1);
        self::assertSame('family_without_pack', $second['records'][0]['identity']);
        $store = new DbalHomeProductIdentityRepairStore($this->db);
        self::assertSame(['status' => 'conflict'], $store->repair('home', '01-partial', 4, $this->at));
        self::assertSame(['status' => 'conflict'], $store->repair('other-home', '01-partial', 5, $this->at));
    }

    public function testFeedFailureRollsBackTheIdentityRepair(): void
    {
        $writer = $this->createMock(ChangeFeedWriter::class);
        $writer->expects(self::once())
            ->method('put')
            ->willThrowException(new \RuntimeException('synthetic outbox failure'));
        try {
            $this->service($writer)->run('home', null, 1, 'owner');
            self::fail('The failing outbox was accepted.');
        } catch (\RuntimeException $failure) {
            self::assertSame('synthetic outbox failure', $failure->getMessage());
        }
        self::assertNull($this->db->fetchOne("SELECT product_id FROM home_products WHERE id = '01-partial'"));
        self::assertSame(
            5,
            (int) $this->db->fetchOne("SELECT revision FROM home_products WHERE id = '01-partial'")
        );
        self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM change_log'));
    }

    public function testOwnerAttributionCannotBeBorrowedFromAnotherHome(): void
    {
        $this->expectException(\DomainException::class);
        $this->service()->run('other-home', null, 10, 'owner');
    }

    public function testOldBootstrapAndDeltaAreNormalizedWithoutRewritingTheLog(): void
    {
        $before = $this->db->fetchAllAssociative('SELECT * FROM change_log');
        $sync = new DbalSyncStore(
            $this->db,
            new SequenceUuidGenerator(),
            90,
            120,
            new DbalHomeProductSyncNormalizer($this->db)
        );
        $snapshot = $sync->captureSnapshot('home', 10);
        self::assertSame('family', $snapshot->records[0]['representation']['productId']);
        self::assertSame('pack-two', $snapshot->records[0]['representation']['packId']);
        $delta = $sync->changes('home', 0, 1, 10);
        self::assertSame('family', $delta[0]['payload']['productId']);
        self::assertSame('Synthetic beans', $delta[0]['payload']['productName']);
        self::assertSame($before, $this->db->fetchAllAssociative('SELECT * FROM change_log'));
        self::assertSame([], $sync->changes('other-home', 0, 1, 10));
        $this->expectException(\UnexpectedValueException::class);
        (new DbalHomeProductSyncNormalizer($this->db))->normalize(
            'other-home',
            'inventory-home-product',
            '01-partial',
            ['productId' => null, 'packId' => 'pack-two']
        );
    }

    private function product(string $id, ?string $product, ?string $pack, string $home = 'home'): void
    {
        $this->db->insert('home_products', [
            'id' => $id, 'home_id' => $home, 'product_id' => $product, 'pack_id' => $pack,
            'private_name' => 'original description', 'original_pack_text' => 'original 2x wording',
            'home_category_id' => null, 'status' => 'active', 'revision' => 5, 'updated_at' => '2026-09-13 12:00:00',
        ]);
    }

    private function service(?ChangeFeedWriter $writer = null): HomeProductIdentityReconciler
    {
        $homes = $this->createStub(HomeStore::class);
        $homes->method('membership')->willReturnCallback(static fn (string $home, string $user): ?array =>
            $home === 'home' && $user === 'owner' ? ['role' => 'owner', 'status' => 'active'] : null);
        $clock = $this->createStub(Clock::class);
        $clock->method('now')->willReturn($this->at);
        return new HomeProductIdentityReconciler(
            new DbalHomeProductIdentityRepairStore($this->db),
            $homes,
            $writer ?? new DbalChangeFeedWriter($this->db, new IdentityRepairUuidGenerator()),
            new IdentityRepairTransactionManager($this->db),
            $clock,
        );
    }
}
