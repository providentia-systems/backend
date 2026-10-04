<?php

declare(strict_types=1);

namespace ProvidentiaTest\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\RetryableException;
use Interop\Queue\Consumer;
use Interop\Queue\Context;
use Interop\Queue\Message;
use Interop\Queue\Queue;
use Laminas\Diactoros\Response\EmptyResponse;
use Laminas\Diactoros\ServerRequest;
use PDO;
use PHPUnit\Framework\TestCase;
use Providentia\SharedKernel\Http\ProblemDetailsMiddleware;
use Providentia\SharedKernel\Infrastructure\Cli\QueueConsumeCommand;
use Providentia\SharedKernel\Infrastructure\Doctrine\DoctrineRetryableFailureClassifier;
use Providentia\SharedKernel\Infrastructure\Factory\ConnectionFactory;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Symfony\Component\Console\Tester\CommandTester;

final class SqliteCommitSafetyTest extends TestCase
{
    private string $databasePath;

    /** @var list<Connection> */
    private array $connections = [];

    protected function setUp(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'providentia-commit-');
        self::assertIsString($path);
        $this->databasePath = $path;
    }

    protected function tearDown(): void
    {
        foreach ($this->connections as $connection) {
            $connection->close();
        }
        unlink($this->databasePath);
    }

    public function testDirectBusyCommitRollsBackNativeStateBeforeConnectionReuse(): void
    {
        $reader = $this->connection();
        $writer = $this->connection();
        $reader->executeStatement('CREATE TABLE effects (id TEXT PRIMARY KEY)');
        $reader->beginTransaction();
        $reader->fetchAllAssociative('SELECT * FROM effects');
        $writer->beginTransaction();
        $writer->insert('effects', ['id' => 'original-operation']);
        $native = $writer->getNativeConnection();
        self::assertInstanceOf(PDO::class, $native);
        try {
            $writer->commit();
            self::fail('The active reader must prevent the outer commit.');
        } catch (RetryableException) {
            self::assertFalse($writer->isTransactionActive());
            self::assertFalse($native->inTransaction());
            self::assertSame($native, $writer->getNativeConnection());
            self::assertSame(0, (int) $reader->fetchOne('SELECT COUNT(*) FROM effects'));
        } finally {
            $reader->rollBack();
        }
        $writer->transactional(static function (Connection $connection): void {
            $connection->insert('effects', ['id' => 'original-operation']);
        });
        self::assertSame(1, (int) $reader->fetchOne('SELECT COUNT(*) FROM effects'));
        self::assertSame(0, (int) $writer->fetchOne('PRAGMA busy_timeout'));
    }

    public function testTransactionalBusyCommitKeepsRetryable503AndRollsBackEveryEffect(): void
    {
        $reader = $this->connection();
        $writer = $this->connection();
        $reader->executeStatement('CREATE TABLE effects (id TEXT PRIMARY KEY)');
        $reader->beginTransaction();
        $reader->fetchAllAssociative('SELECT * FROM effects');
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::exactly(2))->method('handle')->willReturnCallback(
            static fn (): ResponseInterface => $writer->transactional(
                static function (Connection $connection): ResponseInterface {
                    $connection->insert('effects', ['id' => 'original-operation']);
                    return new EmptyResponse(204);
                },
            ),
        );
        $middleware = new ProblemDetailsMiddleware(true, new NullLogger(), new DoctrineRetryableFailureClassifier());
        $request = new ServerRequest([], [], '/test/commit-contention', 'POST');
        try {
            $response = $middleware->process($request, $handler);
            self::assertSame(503, $response->getStatusCode());
            self::assertSame('1', $response->getHeaderLine('Retry-After'));
            self::assertStringNotContainsString('SQLSTATE', (string) $response->getBody());
            self::assertStringNotContainsString('effects', (string) $response->getBody());
            self::assertFalse($writer->isTransactionActive());
            self::assertTrue($writer->isConnected());
            self::assertSame(0, (int) $reader->fetchOne('SELECT COUNT(*) FROM effects'));
        } finally {
            $reader->rollBack();
        }
        self::assertSame(204, $middleware->process($request, $handler)->getStatusCode());
        self::assertSame(1, (int) $reader->fetchOne('SELECT COUNT(*) FROM effects'));
    }

    public function testQueueCannotAcknowledgeAfterNondurableCommitOrFailureRecording(): void
    {
        $reader = $this->connection();
        $writer = $this->connection();
        $reader->executeStatement(
            'CREATE TABLE async_processed_messages (message_id TEXT PRIMARY KEY, processed_at TEXT, handler_name TEXT)',
        );
        $reader->executeStatement(
            'CREATE TABLE async_failed_messages (id TEXT PRIMARY KEY, source_message_id TEXT,
             failed_at TEXT, reason TEXT, resolved_at TEXT)',
        );
        $reader->beginTransaction();
        $reader->fetchAllAssociative('SELECT * FROM async_processed_messages');
        $id = '01912345-6789-7abc-8def-0123456789ab';
        $message = $this->createStub(Message::class);
        $message->method('getMessageId')->willReturn($id);
        $message->method('getBody')->willReturn(json_encode([
            'id' => $id,
            'type' => 'synchronization.record-changed.v2',
            'payload' => ['homeId' => 'home', 'entityType' => 'home-product', 'entityId' => 'product',
                'revision' => 1, 'cursor' => 1],
        ], JSON_THROW_ON_ERROR));
        $acknowledged = 0;
        $consumer = $this->createMock(Consumer::class);
        $consumer->expects(self::exactly(2))->method('receive')->willReturn($message);
        $consumer->expects(self::once())->method('acknowledge')->with($message)->willReturnCallback(
            static function () use (&$acknowledged): void {
                ++$acknowledged;
            },
        );
        $context = $this->createStub(Context::class);
        $context->method('createQueue')->willReturn($this->createStub(Queue::class));
        $context->method('createConsumer')->willReturn($consumer);
        $command = new CommandTester(new QueueConsumeCommand($context, $writer, 'providentia.default'));
        try {
            $command->execute(['--once' => true, '--timeout' => '1']);
            self::fail('The retained reader must also prevent durable failure recording.');
        } catch (RetryableException) {
            self::assertSame(0, $acknowledged);
            self::assertFalse($writer->isTransactionActive());
            $native = $writer->getNativeConnection();
            self::assertInstanceOf(PDO::class, $native);
            self::assertFalse($native->inTransaction());
            self::assertSame(0, (int) $reader->fetchOne('SELECT COUNT(*) FROM async_processed_messages'));
            self::assertSame(0, (int) $reader->fetchOne('SELECT COUNT(*) FROM async_failed_messages'));
        } finally {
            $reader->rollBack();
        }
        self::assertSame(0, $command->execute(['--once' => true, '--timeout' => '1']));
        self::assertSame(1, $acknowledged);
        self::assertSame(1, (int) $reader->fetchOne('SELECT COUNT(*) FROM async_processed_messages'));
    }

    public function testNestedSuccessAndRollbackPreserveTheOuterTransaction(): void
    {
        $connection = $this->connection();
        $connection->executeStatement('CREATE TABLE effects (id TEXT PRIMARY KEY)');
        $connection->transactional(static function (Connection $outer): void {
            $outer->insert('effects', ['id' => 'outer']);
            try {
                $outer->transactional(static function (Connection $inner): void {
                    $inner->insert('effects', ['id' => 'rolled-back']);
                    throw new RuntimeException('Rollback only this savepoint.');
                });
            } catch (RuntimeException) {
                self::assertTrue($outer->isTransactionActive());
            }
            $outer->transactional(static function (Connection $inner): void {
                $inner->insert('effects', ['id' => 'nested']);
            });
        });
        self::assertSame(['nested', 'outer'], $connection->fetchFirstColumn('SELECT id FROM effects ORDER BY id'));
        self::assertFalse($connection->isTransactionActive());
    }

    public function testDeferredConstraintCommitFailureKeepsTheInMemoryDatabaseUsable(): void
    {
        $connection = $this->connection('sqlite:///:memory:');
        $connection->executeStatement('PRAGMA foreign_keys = ON');
        $connection->executeStatement('CREATE TABLE parents (id INTEGER PRIMARY KEY)');
        $connection->executeStatement(
            'CREATE TABLE children (parent_id INTEGER REFERENCES parents (id) DEFERRABLE INITIALLY DEFERRED)',
        );
        try {
            $connection->transactional(static function (Connection $connection): void {
                $connection->insert('children', ['parent_id' => 1]);
            });
            self::fail('The deferred foreign key must reject the outer commit.');
        } catch (ForeignKeyConstraintViolationException) {
            self::assertFalse($connection->isTransactionActive());
            $native = $connection->getNativeConnection();
            self::assertInstanceOf(PDO::class, $native);
            self::assertFalse($native->inTransaction());
            self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM children'));
        }
        $connection->transactional(static function (Connection $connection): void {
            $connection->insert('parents', ['id' => 1]);
            $connection->insert('children', ['parent_id' => 1]);
        });
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM children'));
    }

    private function connection(?string $url = null): Connection
    {
        $container = $this->createStub(ContainerInterface::class);
        $container->method('get')->willReturn(['database' => [
            'url' => $url ?? 'sqlite:///' . $this->databasePath,
            'sqlite_busy_timeout_seconds' => 0,
        ]]);
        $connection = (new ConnectionFactory())($container);
        $this->connections[] = $connection;
        return $connection;
    }
}
