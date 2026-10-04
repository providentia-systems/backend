<?php

declare(strict_types=1);

namespace ProvidentiaTest\Integration;

use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use Laminas\Diactoros\Response\EmptyResponse;
use Laminas\Diactoros\ServerRequest;
use PHPUnit\Framework\TestCase;
use Providentia\SharedKernel\Application\Problem;
use Providentia\SharedKernel\Http\ProblemDetailsMiddleware;
use Providentia\SharedKernel\Infrastructure\Doctrine\DoctrineRetryableFailureClassifier;
use Providentia\SharedKernel\Infrastructure\Factory\ConnectionFactory;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class SqliteContentionResponseTest extends TestCase
{
    private string $databasePath;

    /** @var list<Connection> */
    private array $connections = [];

    protected function setUp(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'providentia-contention-');
        self::assertIsString($path);
        $this->databasePath = $path;
    }

    protected function tearDown(): void
    {
        foreach ($this->connections as $connection) {
            $connection->close();
        }
        if (is_file($this->databasePath)) {
            unlink($this->databasePath);
        }
    }

    public function testBusyTimeoutIsAppliedToTheInitialConnectionAndReconnect(): void
    {
        $connection = $this->connection();
        self::assertSame(5000, (int) $connection->fetchOne('PRAGMA busy_timeout'));
        $connection->close();
        self::assertSame(5000, (int) $connection->fetchOne('PRAGMA busy_timeout'));
        $configured = $this->connection(1);
        self::assertSame(1000, (int) $configured->fetchOne('PRAGMA busy_timeout'));
    }

    public function testConcurrentWriterGetsSafe503ThenSameIdentityProducesOnlyOneEffect(): void
    {
        $holder = $this->connection(0);
        $writer = $this->connection(0);
        $statements = [
            'CREATE TABLE writer_guard (id TEXT PRIMARY KEY)',
            'CREATE TABLE business_effects (operation_id TEXT PRIMARY KEY)',
            'CREATE TABLE outbox_effects (operation_id TEXT PRIMARY KEY)',
            'CREATE TABLE operation_receipts (operation_id TEXT PRIMARY KEY)',
        ];
        foreach ($statements as $sql) {
            $holder->executeStatement($sql);
        }
        $holder->beginTransaction();
        $holder->insert('writer_guard', ['id' => 'lock-held']);
        $attempts = 0;
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::exactly(3))->method('handle')->willReturnCallback(
            static function () use ($writer, &$attempts): ResponseInterface {
                ++$attempts;
                return $writer->transactional(static function (Connection $connection): ResponseInterface {
                    $id = 'same-original-operation';
                    $recordedId = $connection->fetchOne(
                        'SELECT operation_id FROM operation_receipts WHERE operation_id = :id',
                        ['id' => $id],
                    );
                    if ($recordedId !== false) {
                        return new EmptyResponse(204);
                    }
                    $connection->insert('business_effects', ['operation_id' => $id]);
                    $connection->insert('outbox_effects', ['operation_id' => $id]);
                    $connection->insert('operation_receipts', ['operation_id' => $id]);
                    return new EmptyResponse(204);
                });
            },
        );
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with(
            'HTTP request failed.',
            self::callback(static function (array $context): bool {
                return $context['status'] === 503
                    && $context['request_id'] === 'contention-regression'
                    && ! array_key_exists('exception', $context)
                    && ! array_key_exists('sql', $context);
            }),
        );
        // Even a development response must not disclose SQL, paths or data.
        $middleware = new ProblemDetailsMiddleware(true, $logger, new DoctrineRetryableFailureClassifier());
        $request = (new ServerRequest([], [], '/test/contention', 'POST'))
            ->withHeader('X-Request-Id', 'contention-regression');
        try {
            $response = $middleware->process($request, $handler);
            self::assertSame(503, $response->getStatusCode());
            self::assertSame('1', $response->getHeaderLine('Retry-After'));
            self::assertSame('application/problem+json', $response->getHeaderLine('Content-Type'));
            self::assertSame('contention-regression', $response->getHeaderLine('X-Request-Id'));
            $body = (string) $response->getBody();
            self::assertStringContainsString('Database temporarily busy', $body);
            self::assertStringNotContainsString('SQLSTATE', $body);
            self::assertStringNotContainsString('business_effects', $body);
            self::assertStringNotContainsString($this->databasePath, $body);
            self::assertSame(1, $attempts);
            self::assertFalse($writer->isTransactionActive());
            self::assertSame(0, (int) $writer->fetchOne('SELECT COUNT(*) FROM business_effects'));
            self::assertSame(0, (int) $writer->fetchOne('SELECT COUNT(*) FROM outbox_effects'));
            self::assertSame(0, (int) $writer->fetchOne('SELECT COUNT(*) FROM operation_receipts'));
        } finally {
            $holder->rollBack();
        }
        self::assertSame(204, $middleware->process($request, $handler)->getStatusCode());
        self::assertSame(204, $middleware->process($request, $handler)->getStatusCode());
        self::assertSame(3, $attempts);
        self::assertSame(1, (int) $writer->fetchOne('SELECT COUNT(*) FROM business_effects'));
        self::assertSame(1, (int) $writer->fetchOne('SELECT COUNT(*) FROM outbox_effects'));
        self::assertSame(1, (int) $writer->fetchOne('SELECT COUNT(*) FROM operation_receipts'));
    }

    public function testRevisionConflictsAreNotReclassifiedAsTransientDatabaseFailures(): void
    {
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::once())->method('handle')->willThrowException(
            new Problem(409, 'Revision conflict', 'Refresh before changing this item.'),
        );
        $response = (new ProblemDetailsMiddleware(
            false,
            new NullLogger(),
            new DoctrineRetryableFailureClassifier(),
        ))->process(
            new ServerRequest([], [], '/test/conflict', 'POST'),
            $handler,
        );
        self::assertSame(409, $response->getStatusCode());
        self::assertFalse($response->hasHeader('Retry-After'));
    }

    public function testInvalidTimeoutIsRejectedBeforeConnecting(): void
    {
        $container = $this->createStub(ContainerInterface::class);
        $container->method('get')->willReturn(['database' => [
            'url' => 'sqlite:///' . $this->databasePath,
            'sqlite_busy_timeout_seconds' => 31,
        ]]);
        $this->expectException(InvalidArgumentException::class);
        (new ConnectionFactory())($container);
    }

    private function connection(?int $timeout = null): Connection
    {
        $container = $this->createStub(ContainerInterface::class);
        $container->method('get')->willReturn(['database' => [
            'url' => 'sqlite:///' . $this->databasePath,
            ...($timeout === null ? [] : ['sqlite_busy_timeout_seconds' => $timeout]),
        ]]);
        $connection = (new ConnectionFactory())($container);
        $this->connections[] = $connection;

        return $connection;
    }
}
