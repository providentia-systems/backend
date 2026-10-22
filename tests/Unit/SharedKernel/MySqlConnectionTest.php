<?php

declare(strict_types=1);

namespace ProvidentiaTest\Unit\SharedKernel;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\TransactionIsolationLevel;
use PHPUnit\Framework\TestCase;
use Providentia\SharedKernel\Infrastructure\Doctrine\MySqlConnection;
use RuntimeException;

final class MySqlConnectionTest extends TestCase
{
    public function testFreshAndReconnectedSessionsUseCommittedEvidenceWithoutEagerConnection(): void
    {
        $native = $this->createMock(DriverConnection::class);
        $native->expects(self::exactly(2))->method('exec')
            ->with('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED')->willReturn(0);
        $native->method('getServerVersion')->willReturn('8.4.6');
        $driver = $this->createMock(Driver::class);
        $driver->expects(self::exactly(2))->method('connect')->willReturn($native);
        $driver->method('getDatabasePlatform')->willReturn(new MySQLPlatform());
        $connection = new MySqlConnection([], $driver);

        self::assertFalse($connection->isConnected());
        self::assertSame('8.4.6', $connection->getServerVersion());
        self::assertSame(TransactionIsolationLevel::READ_COMMITTED, $connection->getTransactionIsolation());
        self::assertSame('8.4.6', $connection->getServerVersion());
        $connection->close();
        self::assertFalse($connection->isConnected());
        self::assertSame('8.4.6', $connection->getServerVersion());
        self::assertSame(TransactionIsolationLevel::READ_COMMITTED, $connection->getTransactionIsolation());
    }

    public function testAnUnconfiguredSessionIsDiscardedIfIsolationSetupFails(): void
    {
        $native = $this->createStub(DriverConnection::class);
        $native->method('exec')->willThrowException(new RuntimeException('Session setup failed.'));
        $driver = $this->createStub(Driver::class);
        $driver->method('connect')->willReturn($native);
        $driver->method('getDatabasePlatform')->willReturn(new MySQLPlatform());
        $connection = new MySqlConnection([], $driver);

        try {
            $connection->getServerVersion();
            self::fail('A session with the wrong isolation level must not be used.');
        } catch (RuntimeException $error) {
            self::assertSame('Session setup failed.', $error->getMessage());
            self::assertFalse($connection->isConnected());
        }
    }
}
