<?php

declare(strict_types=1);

namespace ProvidentiaTest\Integration;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Providentia\Identity\Infrastructure\Doctrine\DbalAuthenticationRateLimitStore;

final class AuthenticationRateLimitStoreTest extends TestCase
{
    private Connection $connection;
    private DbalAuthenticationRateLimitStore $store;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->connection->executeStatement(
            'CREATE TABLE authentication_rate_limits (
                bucket_hash VARCHAR(64) PRIMARY KEY,
                attempts INTEGER NOT NULL,
                window_started_at DATETIME NOT NULL,
                blocked_until DATETIME NULL,
                updated_at DATETIME NOT NULL
            )',
        );
        $this->store = new DbalAuthenticationRateLimitStore($this->connection);
    }

    public function testEstablishedBucketSerializesAttemptsAndPersistsBlocking(): void
    {
        $now = new DateTimeImmutable('2026-08-09T12:00:00+00:00');

        self::assertTrue($this->store->consume('bucket', $now, 900, 2, 900));
        self::assertTrue($this->store->consume('bucket', $now, 900, 2, 900));
        self::assertFalse($this->store->consume('bucket', $now, 900, 2, 900));
        self::assertFalse($this->store->consume('bucket', $now->modify('+1 second'), 900, 2, 900));
        self::assertSame(3, (int) $this->connection->fetchOne(
            'SELECT attempts FROM authentication_rate_limits WHERE bucket_hash = :hash',
            ['hash' => 'bucket'],
        ));
    }

    public function testWindowResetsAfterItsDeadline(): void
    {
        $now = new DateTimeImmutable('2026-08-09T12:00:00+00:00');
        self::assertTrue($this->store->consume('bucket', $now, 10, 1, 5));

        self::assertTrue($this->store->consume('bucket', $now->modify('+11 seconds'), 10, 1, 5));
        self::assertSame(1, (int) $this->connection->fetchOne(
            'SELECT attempts FROM authentication_rate_limits WHERE bucket_hash = :hash',
            ['hash' => 'bucket'],
        ));
    }

    public function testInactiveBucketsArePurgedWithoutDeletingCurrentOrBlockedRows(): void
    {
        $this->connection->insert('authentication_rate_limits', [
            'bucket_hash' => 'stale',
            'attempts' => 1,
            'window_started_at' => '2026-08-01 12:00:00',
            'blocked_until' => null,
            'updated_at' => '2026-08-01 12:00:00',
        ]);
        $this->connection->insert('authentication_rate_limits', [
            'bucket_hash' => 'current',
            'attempts' => 1,
            'window_started_at' => '2026-08-09 11:59:00',
            'blocked_until' => null,
            'updated_at' => '2026-08-09 11:59:00',
        ]);
        $this->connection->insert('authentication_rate_limits', [
            'bucket_hash' => 'still-blocked',
            'attempts' => 100,
            'window_started_at' => '2026-08-01 12:00:00',
            'blocked_until' => '2026-08-09 12:10:00',
            'updated_at' => '2026-08-01 12:00:00',
        ]);

        $purged = $this->store->purgeInactive(
            new DateTimeImmutable('2026-08-09T12:00:00+00:00'),
            new DateTimeImmutable('2026-08-07T12:00:00+00:00'),
            100,
        );

        self::assertSame(1, $purged);
        self::assertSame(['current', 'still-blocked'], $this->connection->fetchFirstColumn(
            'SELECT bucket_hash FROM authentication_rate_limits ORDER BY bucket_hash',
        ));
    }
}
