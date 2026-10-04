<?php

declare(strict_types=1);

namespace ProvidentiaTest\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;
use Providentia\SharedKernel\Application\Async\AsyncMessage;
use Providentia\SharedKernel\Application\Async\AsyncMessageBus;
use Providentia\SharedKernel\Infrastructure\Queue\FailedSynchronizationReplay;
use RuntimeException;

final class FailedSynchronizationReplayTest extends TestCase
{
    private const FAILURE = 'No handler is registered for synchronization.record-changed.v2';
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->connection->executeStatement(
            'CREATE TABLE async_failed_messages (id TEXT PRIMARY KEY, source_message_id TEXT,
             reason TEXT, resolved_at TEXT)',
        );
        $this->connection->executeStatement(
            'CREATE TABLE outbox_messages (id TEXT PRIMARY KEY, message_type TEXT, queue_name TEXT,
             payload TEXT, occurred_at TEXT, status TEXT)',
        );
    }

    public function testDryRunDoesNotPublishOrChangeSourceAndFailureRows(): void
    {
        $this->seed('event-1');
        $this->connection->insert('async_failed_messages', [
            'id' => 'duplicate-failure',
            'source_message_id' => 'event-1',
            'reason' => self::FAILURE,
            'resolved_at' => null,
        ]);
        $bus = $this->createMock(AsyncMessageBus::class);
        $bus->expects(self::never())->method('publish');
        $before = $this->snapshot();
        $result = (new FailedSynchronizationReplay($this->connection, $bus))->replay();
        self::assertSame(1, $result['ready']);
        self::assertSame(0, $result['queued']);
        self::assertSame(0, $result['blocked']);
        self::assertSame([['id' => 'event-1', 'status' => 'ready']], $result['items']);
        self::assertSame($before, $this->snapshot());
    }

    public function testRepeatedApplyUsesExactlyTheOriginalNotificationIdentityAndPayload(): void
    {
        $this->seed('event-1');
        $before = $this->snapshot();
        $bus = $this->createMock(AsyncMessageBus::class);
        $bus->expects(self::exactly(2))->method('publish')->willReturnCallback(
            static function (AsyncMessage $message): void {
                self::assertSame('event-1', $message->id);
                self::assertSame('synchronization.record-changed.v2', $message->type);
                self::assertSame('providentia.default', $message->queue);
                self::assertSame('2026-10-03T12:00:00+00:00', $message->occurredAt->format(DATE_ATOM));
                self::assertSame([
                    'homeId' => 'home',
                    'entityType' => 'home-product',
                    'entityId' => 'product',
                    'revision' => 3,
                    'cursor' => 42,
                ], $message->payload);
            },
        );
        $replay = new FailedSynchronizationReplay($this->connection, $bus);
        foreach ([1, 2] as $attempt) {
            $result = $replay->replay(100, true);
            self::assertSame(1, $result['queued'], 'Attempt ' . $attempt);
            self::assertSame(0, $result['blocked']);
        }
        self::assertSame($before, $this->snapshot());
    }

    public function testMissingAndInvalidRetainedSourcesAreNotInventedOrPublished(): void
    {
        $this->seed('event-invalid');
        $this->connection->update('outbox_messages', ['payload' => '{"cursor":0}'], ['id' => 'event-invalid']);
        $this->connection->insert('async_failed_messages', [
            'id' => 'missing-source-failure',
            'source_message_id' => 'event-missing',
            'reason' => self::FAILURE,
            'resolved_at' => null,
        ]);
        $bus = $this->createMock(AsyncMessageBus::class);
        $bus->expects(self::never())->method('publish');
        $before = $this->snapshot();
        $result = (new FailedSynchronizationReplay($this->connection, $bus))->replay(100, true);
        self::assertSame(0, $result['queued']);
        self::assertSame(2, $result['blocked']);
        self::assertSame([
            ['id' => 'event-invalid', 'status' => 'invalid-source'],
            ['id' => 'event-missing', 'status' => 'missing-source'],
        ], $result['items']);
        self::assertSame($before, $this->snapshot());
    }

    public function testBrokerFailureLeavesAuditUnresolvedAndDoesNotExposeTransportDetails(): void
    {
        $this->seed('event-1');
        $before = $this->snapshot();
        $bus = $this->createMock(AsyncMessageBus::class);
        $bus->expects(self::once())->method('publish')->willThrowException(new RuntimeException('PRIVATE transport'));
        $result = (new FailedSynchronizationReplay($this->connection, $bus))->replay(100, true);
        self::assertSame(0, $result['queued']);
        self::assertSame(1, $result['blocked']);
        self::assertSame([['id' => 'event-1', 'status' => 'publish-failed']], $result['items']);
        self::assertStringNotContainsString('PRIVATE', json_encode($result, JSON_THROW_ON_ERROR));
        self::assertSame($before, $this->snapshot());
    }

    public function testRecoveryIsBoundedAndExcludesResolvedOrUnrelatedFailures(): void
    {
        $this->seed('event-1');
        $this->seed('event-2');
        $this->seed('event-3');
        $result = (new FailedSynchronizationReplay($this->connection))->replay(1);
        self::assertSame([['id' => 'event-1', 'status' => 'ready']], $result['items']);
        $this->connection->update(
            'async_failed_messages',
            ['resolved_at' => '2026-10-04 12:00:00'],
            ['source_message_id' => 'event-1'],
        );
        $this->connection->update(
            'async_failed_messages',
            ['reason' => 'A different failure requiring investigation.'],
            ['source_message_id' => 'event-2'],
        );
        $result = (new FailedSynchronizationReplay($this->connection))->replay();
        self::assertSame([['id' => 'event-3', 'status' => 'ready']], $result['items']);
    }

    public function testInvalidLimitIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new FailedSynchronizationReplay($this->connection))->replay(1001);
    }

    public function testApplyRequiresAnExplicitMessageBus(): void
    {
        $this->expectException(LogicException::class);
        (new FailedSynchronizationReplay($this->connection))->replay(100, true);
    }

    private function seed(string $id): void
    {
        $this->connection->insert('async_failed_messages', [
            'id' => 'failure-' . $id,
            'source_message_id' => $id,
            'reason' => self::FAILURE,
            'resolved_at' => null,
        ]);
        $this->connection->insert('outbox_messages', [
            'id' => $id,
            'message_type' => 'synchronization.record-changed.v2',
            'queue_name' => 'providentia.default',
            'payload' => json_encode([
                'homeId' => 'home',
                'entityType' => 'home-product',
                'entityId' => 'product',
                'revision' => 3,
                'cursor' => 42,
            ], JSON_THROW_ON_ERROR),
            'occurred_at' => '2026-10-03 12:00:00',
            'status' => 'published',
        ]);
    }

    /** @return array{list<array<string, mixed>>, list<array<string, mixed>>} */
    private function snapshot(): array
    {
        return [
            $this->connection->fetchAllAssociative('SELECT * FROM async_failed_messages ORDER BY id'),
            $this->connection->fetchAllAssociative('SELECT * FROM outbox_messages ORDER BY id'),
        ];
    }
}
