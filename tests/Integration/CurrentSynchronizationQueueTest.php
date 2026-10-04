<?php

declare(strict_types=1);

namespace ProvidentiaTest\Integration;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Interop\Queue\Consumer;
use Interop\Queue\Context;
use Interop\Queue\Message;
use Interop\Queue\Producer;
use Interop\Queue\Queue;
use PHPUnit\Framework\TestCase;
use Providentia\SharedKernel\Application\Async\AsyncMessage;
use Providentia\SharedKernel\Application\UuidGenerator;
use Providentia\SharedKernel\Infrastructure\Cli\QueueConsumeCommand;
use Providentia\SharedKernel\Infrastructure\Queue\EnqueueAsyncMessageBus;
use Providentia\Synchronization\Infrastructure\Doctrine\DbalChangeFeedWriter;
use RuntimeException;
use Symfony\Component\Console\Tester\CommandTester;

final class CurrentSynchronizationQueueTest extends TestCase
{
    private const MESSAGE_ID = '01912345-6789-7abc-8def-0123456789ab';

    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        foreach ([
            'CREATE TABLE change_log (id INTEGER PRIMARY KEY AUTOINCREMENT, home_id TEXT,
             entity_type TEXT, entity_id TEXT, operation_type TEXT, revision INTEGER,
             payload_schema_version INTEGER, payload_json TEXT, changed_by_user_id TEXT, changed_at TEXT)',
            'CREATE TABLE outbox_messages (id TEXT PRIMARY KEY, message_type TEXT, queue_name TEXT,
             payload TEXT, occurred_at TEXT, available_at TEXT, published_at TEXT, attempts INTEGER,
             last_error TEXT, status TEXT)',
            'CREATE TABLE async_processed_messages (message_id TEXT PRIMARY KEY, processed_at TEXT, handler_name TEXT)',
            'CREATE TABLE async_failed_messages (id TEXT PRIMARY KEY, source_message_id TEXT,
             failed_at TEXT, reason TEXT, resolved_at TEXT)',
        ] as $sql) {
            $this->connection->executeStatement($sql);
        }
    }

    public function testActualWriterAndBusEnvelopeCanBeConsumedTwiceWithoutRepeatingTheMutation(): void
    {
        $this->connection->transactional(function (): void {
            $this->writer()->put('home', 'actor', 'home-product', 'product', 1, ['name' => 'Rice'], $this->at());
        });
        $row = $this->connection->fetchAssociative('SELECT * FROM outbox_messages');
        self::assertIsArray($row);
        self::assertSame('synchronization.record-changed.v2', $row['message_type']);
        $this->connection->insert('async_failed_messages', [
            'id' => 'historical-failure',
            'source_message_id' => self::MESSAGE_ID,
            'failed_at' => '2026-10-03 12:00:00',
            'reason' => 'No handler is registered for synchronization.record-changed.v2',
            'resolved_at' => null,
        ]);
        $body = '';
        $transport = $this->createMock(Message::class);
        $transport->expects(self::once())->method('setMessageId')->with(self::MESSAGE_ID);
        $producer = $this->createMock(Producer::class);
        $queue = $this->createStub(Queue::class);
        $producer->expects(self::once())->method('send')->with($queue, $transport);
        $context = $this->createMock(Context::class);
        $context->method('createQueue')->willReturn($queue);
        $context->method('createProducer')->willReturn($producer);
        $context->expects(self::once())->method('createMessage')->willReturnCallback(
            static function (string $encoded) use (&$body, $transport): Message {
                $body = $encoded;
                return $transport;
            },
        );
        /** @var array<string, mixed> $payload */
        $payload = json_decode((string) $row['payload'], true, 512, JSON_THROW_ON_ERROR);
        (new EnqueueAsyncMessageBus($context))->publish(new AsyncMessage(
            (string) $row['id'],
            (string) $row['message_type'],
            $payload,
            $this->at(),
            (string) $row['queue_name'],
        ));
        self::assertNotSame('', $body);
        $command = $this->consumer($body, 2);
        self::assertSame(0, $command->execute(['--once' => true, '--timeout' => '1']));
        self::assertSame(0, $command->execute(['--once' => true, '--timeout' => '1']));
        self::assertStringContainsString('Acknowledged duplicate', $command->getDisplay());
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM async_processed_messages'));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM change_log'));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM outbox_messages'));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM async_failed_messages'));
        self::assertNotNull($this->connection->fetchOne('SELECT resolved_at FROM async_failed_messages'));
    }

    public function testRolledBackMutationLeavesNeitherChangeFeedNorOutboxEvent(): void
    {
        try {
            $this->connection->transactional(function (): void {
                $this->writer()->put('home', 'actor', 'home-product', 'product', 1, [], $this->at());
                throw new RuntimeException('Simulated business rollback.');
            });
            self::fail('Expected the transaction to roll back.');
        } catch (RuntimeException $error) {
            self::assertSame('Simulated business rollback.', $error->getMessage());
        }
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM change_log'));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM outbox_messages'));
    }

    public function testInvalidCurrentNotificationIsRetainedForReviewInsteadOfMarkedProcessed(): void
    {
        $body = json_encode([
            'id' => self::MESSAGE_ID,
            'type' => 'synchronization.record-changed.v2',
            'payload' => ['homeId' => 'home', 'entityType' => 'home-product', 'entityId' => 'product',
                'revision' => 1, 'cursor' => 0],
        ], JSON_THROW_ON_ERROR);
        $command = $this->consumer($body, 1);
        self::assertSame(1, $command->execute(['--once' => true, '--timeout' => '1']));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM async_processed_messages'));
        self::assertNull($this->connection->fetchOne('SELECT resolved_at FROM async_failed_messages'));
        self::assertSame(
            'Invalid synchronization notification cursor.',
            $this->connection->fetchOne('SELECT reason FROM async_failed_messages'),
        );
    }

    public function testLegacySynchronizationNotificationStillProcesses(): void
    {
        $command = $this->consumer(json_encode([
            'id' => self::MESSAGE_ID,
            'type' => 'synchronization.record-changed.v1',
            'payload' => [],
        ], JSON_THROW_ON_ERROR), 1);
        self::assertSame(0, $command->execute(['--once' => true, '--timeout' => '1']));
        self::assertSame(
            'synchronization-notification',
            $this->connection->fetchOne('SELECT handler_name FROM async_processed_messages'),
        );
    }

    private function consumer(string $body, int $deliveries): CommandTester
    {
        $message = $this->createStub(Message::class);
        $message->method('getBody')->willReturn($body);
        $message->method('getMessageId')->willReturn(self::MESSAGE_ID);
        $consumer = $this->createMock(Consumer::class);
        $consumer->expects(self::exactly($deliveries))->method('receive')->willReturn($message);
        $consumer->expects(self::exactly($deliveries))->method('acknowledge')->with($message);
        $context = $this->createStub(Context::class);
        $context->method('createQueue')->willReturn($this->createStub(Queue::class));
        $context->method('createConsumer')->willReturn($consumer);

        return new CommandTester(new QueueConsumeCommand($context, $this->connection, 'providentia.default'));
    }

    private function writer(): DbalChangeFeedWriter
    {
        $ids = $this->createStub(UuidGenerator::class);
        $ids->method('generate')->willReturn(self::MESSAGE_ID);

        return new DbalChangeFeedWriter($this->connection, $ids);
    }

    private function at(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-10-03T12:00:00Z');
    }
}
