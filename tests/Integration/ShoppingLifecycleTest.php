<?php

declare(strict_types=1);

namespace ProvidentiaTest\Integration;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Providentia\Home\Application\HomeAuthorization;
use Providentia\Home\Application\HomeStore;
use Providentia\Identity\Application\AuthenticatedIdentity;
use Providentia\SharedKernel\Application\ChangeFeedWriter;
use Providentia\SharedKernel\Application\Clock;
use Providentia\SharedKernel\Application\TransactionManager;
use Providentia\Shopping\Application\ShoppingService;
use Providentia\Shopping\Application\ShoppingIntelligenceService;
use Providentia\Shopping\Domain\ConsumptionEstimator;
use Providentia\Shopping\Domain\PackOptimizer;
use Providentia\Shopping\Domain\SuggestionEngine;
use Providentia\Shopping\Infrastructure\Doctrine\DbalShoppingIntelligenceStore;
use Providentia\Shopping\Domain\LegacySuggestionPolicy;
use ProvidentiaTest\Support\AccessFixture;
use Providentia\Shopping\Infrastructure\Doctrine\DbalShoppingStore;

final class ShoppingLifecycleTest extends TestCase
{
    private Connection $connection;
    private DbalShoppingStore $store;
    private DateTimeImmutable $at;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->connection->executeStatement(
            'CREATE TABLE shopping_lists (id TEXT PRIMARY KEY, home_id TEXT, name TEXT, kind TEXT,
             status TEXT, revision INTEGER, created_by_user_id TEXT, created_at TEXT, updated_at TEXT)',
        );
        $this->connection->executeStatement(
            'CREATE TABLE shopping_list_lines (id TEXT PRIMARY KEY, home_id TEXT, shopping_list_id TEXT,
             home_product_id TEXT, description TEXT, source TEXT, quantity_to_buy TEXT,
             explanation TEXT, confidence TEXT, checked_at TEXT, archived_at TEXT,
             suggestion_id TEXT, selected_pack_id TEXT,
             revision INTEGER, created_at TEXT, updated_at TEXT)',
        );
        $this->connection->executeStatement(
            'CREATE TABLE home_products (id TEXT, home_id TEXT, private_name TEXT, product_id TEXT, status TEXT)',
        );
        $this->connection->executeStatement('CREATE TABLE products (id TEXT, canonical_name TEXT)');
        foreach (
            [
                'CREATE TABLE shopping_suggestion_runs (id TEXT, home_id TEXT, status TEXT, as_of TEXT)',
                'CREATE TABLE shopping_suggestions (id TEXT, home_id TEXT, run_id TEXT, home_product_id TEXT,
             selected_pack_id TEXT, required_quantity TEXT, confidence_band TEXT, status TEXT, expires_at TEXT)',
                'CREATE TABLE user_suggestion_feedback (id TEXT PRIMARY KEY, home_id TEXT, suggestion_id TEXT,
             actor_user_id TEXT, decision TEXT, original_quantity TEXT, result_quantity TEXT,
             reason TEXT, created_at TEXT)',
                'CREATE TABLE audit_events (id TEXT PRIMARY KEY, home_id TEXT, actor_user_id TEXT, action TEXT,
             target_type TEXT, target_id TEXT, details TEXT, occurred_at TEXT)',
            ] as $sql
        ) {
            $this->connection->executeStatement($sql);
        }
        $this->store = new DbalShoppingStore($this->connection);
        $this->at = new DateTimeImmutable('2026-09-12T12:00:00Z');
        $this->store->createList('list', 'home', 'Weekly shop', 'manual', 'actor', $this->at);
    }

    public function testLineEditsArchivesAndRestoresRetainIdentityAndHistory(): void
    {
        self::assertTrue($this->store->addLine(
            'line',
            'home',
            'list',
            1,
            null,
            'Rice',
            'manual',
            '2',
            'Added manually.',
            null,
            $this->at,
        ));
        self::assertTrue($this->store->updateLine('home', 'list', 'line', 'Brown rice', '3.5', false, 1, $this->at));
        self::assertFalse($this->store->updateLine('home', 'list', 'line', 'Stale', '9', false, 1, $this->at));
        self::assertTrue($this->store->updateLine('home', 'list', 'line', 'Brown rice', '3.5', true, 2, $this->at));
        self::assertCount(1, $this->store->lines('home', 'list'));
        self::assertNotNull($this->store->lines('home', 'list')[0]['archivedAt']);
        self::assertSame(0, (int) $this->store->lists('home')[0]['lineCount']);
        self::assertFalse($this->store->setChecked('home', 'list', 'line', true, 3, $this->at));
        self::assertTrue($this->store->updateLine('home', 'list', 'line', 'Brown rice', '3.5', false, 3, $this->at));
        self::assertNull($this->store->lines('home', 'list')[0]['archivedAt']);
        self::assertSame('Added manually.', $this->store->lines('home', 'list')[0]['explanation']);
        $list = $this->store->shoppingList('home', 'list');
        self::assertNotNull($list);
        self::assertSame(5, (int) $list['revision']);
    }

    public function testServicePublishesLineAndParentRevisionInsideTheMutationTransaction(): void
    {
        $homes = $this->createStub(HomeStore::class);
        $homes->method('membership')->willReturn(['status' => 'active', 'role' => HomeAuthorization::MEMBER]);
        $clock = $this->createStub(Clock::class);
        $clock->method('now')->willReturn($this->at);
        $transactions = $this->createStub(TransactionManager::class);
        $transactions->method('transactional')->willReturnCallback(
            fn(callable $operation): mixed => $this->connection->transactional(static fn(): mixed => $operation()),
        );
        /** @var list<array{string, int, array<string, mixed>}> $published */
        $published = [];
        $changes = $this->createMock(ChangeFeedWriter::class);
        $changes->expects(self::exactly(5))->method('put')->willReturnCallback(
            /** @param array<string, mixed> $representation */
            function (
                string $home,
                ?string $actor,
                string $type,
                string $id,
                int $revision,
                array $representation,
            ) use (&$published): int {
                self::assertTrue($this->connection->isTransactionActive());
                $published[] = [$type, $revision, $representation];

                return count($published);
            },
        );
        $service = new ShoppingService(
            $this->store,
            new HomeAuthorization($homes, AccessFixture::create()),
            new LegacySuggestionPolicy(),
            new SequenceUuidGenerator(),
            $clock,
            $transactions,
            $changes,
        );
        $identity = new AuthenticatedIdentity('actor', 'session', 'device', 'home', []);
        $this->store->addLine('line', 'home', 'list', 1, null, 'Rice', 'manual', '2', 'Manual.', null, $this->at);
        $service->updateLine($identity, 'home', 'list', 'line', 'Brown rice', '3', false, 1);
        $service->setChecked($identity, 'home', 'list', 'line', true, 2);
        $service->updateList($identity, 'home', 'list', 'Monthly shop', 'archived', 4);
        self::assertSame(
            ['shopping-list-line', 'shopping-list', 'shopping-list-line', 'shopping-list', 'shopping-list'],
            array_column($published, 0),
        );
        self::assertSame([2, 3, 3, 4, 5], array_column($published, 1));
        self::assertTrue($published[2][2]['checked']);
        self::assertSame('archived', $published[4][2]['status']);
    }

    public function testRecommendationProvenanceAndFeedbackCommitOnlyWithASuccessfulLine(): void
    {
        $this->connection->insert('home_products', ['id' => 'product', 'home_id' => 'home', 'status' => 'active']);
        $this->connection->insert('shopping_suggestion_runs', [
            'id' => 'run',
            'home_id' => 'home',
            'status' => 'completed',
            'as_of' => '2026-09-12 10:00:00',
        ]);
        $this->connection->insert('shopping_suggestions', [
            'id' => 'suggestion',
            'home_id' => 'home',
            'run_id' => 'run',
            'home_product_id' => 'product',
            'selected_pack_id' => 'pack',
            'required_quantity' => '2',
            'confidence_band' => 'medium',
            'status' => 'active',
            'expires_at' => '2026-09-13 10:00:00',
        ]);
        $homes = $this->createStub(HomeStore::class);
        $homes->method('membership')->willReturn(['status' => 'active', 'role' => HomeAuthorization::MEMBER]);
        $authorization = new HomeAuthorization($homes, AccessFixture::create());
        $clock = $this->createStub(Clock::class);
        $clock->method('now')->willReturn($this->at);
        $transactions = $this->createStub(TransactionManager::class);
        $transactions
            ->method('transactional')
            ->willReturnCallback(
                fn(callable $operation): mixed => $this->connection->transactional(static fn(): mixed => $operation()),
            );
        $ids = new SequenceUuidGenerator();
        $intelligence = new ShoppingIntelligenceService(
            new DbalShoppingIntelligenceStore($this->connection),
            $authorization,
            new ConsumptionEstimator(),
            new SuggestionEngine(),
            new PackOptimizer(),
            $ids,
            $clock,
            $transactions,
        );
        $service = new ShoppingService(
            $this->store,
            $authorization,
            new LegacySuggestionPolicy(),
            $ids,
            $clock,
            $transactions,
            null,
            $intelligence,
        );
        $identity = new AuthenticatedIdentity('actor', 'session', 'device', 'home', []);
        try {
            $service->addLine($identity, 'home', 'list', 99, 'product', 'Rice', '3', null, 'suggestion');
            self::fail('A stale list revision must fail.');
        } catch (\Providentia\SharedKernel\Application\Problem $error) {
            self::assertSame(409, $error->status);
        }
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM user_suggestion_feedback'));
        self::assertSame('active', $this->connection->fetchOne('SELECT status FROM shopping_suggestions'));
        $created = $service->addLine($identity, 'home', 'list', 1, 'product', 'Rice', '3', null, 'suggestion');
        $line = $this->store->line('home', 'list', $created['id']);
        self::assertNotNull($line);
        self::assertSame('suggested', $line['source']);
        self::assertSame('suggestion', $line['suggestionId']);
        self::assertSame('pack', $line['selectedPackId']);
        self::assertSame('edited', $this->connection->fetchOne('SELECT decision FROM user_suggestion_feedback'));
        self::assertSame($created['id'], $this->connection->fetchOne('SELECT id FROM user_suggestion_feedback'));
        $service->updateLine($identity, 'home', 'list', $created['id'], 'Rice', '4', false, 1);
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM user_suggestion_feedback'));
        $updatedLine = $this->store->line('home', 'list', $created['id']);
        self::assertNotNull($updatedLine);
        self::assertSame('suggestion', $updatedLine['suggestionId']);
    }

    public function testListLifecycleRequiresCurrentRevisionAndKeepsTenantBoundaries(): void
    {
        self::assertFalse($this->store->updateList('other-home', 'list', 'Denied', 'open', 1, $this->at));
        self::assertTrue($this->store->updateList('home', 'list', 'Month end', 'archived', 1, $this->at));
        self::assertSame('archived', $this->store->lists('home')[0]['status']);
        self::assertFalse($this->store->addLine(
            'line',
            'home',
            'list',
            2,
            null,
            'Rice',
            'manual',
            '1',
            'Added manually.',
            null,
            $this->at,
        ));
        self::assertFalse($this->store->updateList('home', 'list', 'Stale', 'open', 1, $this->at));
        self::assertTrue($this->store->updateList('home', 'list', 'Month end', 'open', 2, $this->at));
        $list = $this->store->shoppingList('home', 'list');
        self::assertNotNull($list);
        self::assertSame('open', $list['status']);
    }
}
