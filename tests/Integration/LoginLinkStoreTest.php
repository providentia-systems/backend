<?php

declare(strict_types=1);

namespace ProvidentiaTest\Integration;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Providentia\Identity\Infrastructure\Doctrine\DbalLoginLinkStore;

final class LoginLinkStoreTest extends TestCase
{
    private const REQUEST_ID = '01912345-6789-7abc-8def-0123456789ab';

    private Connection $connection;
    private DbalLoginLinkStore $store;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->connection->executeStatement(
            'CREATE TABLE auth_login_link_requests (
                id VARCHAR(36) PRIMARY KEY,
                normalized_email VARCHAR(254) NOT NULL,
                application_kind VARCHAR(16) NOT NULL,
                revision INTEGER NOT NULL,
                status VARCHAR(16) NOT NULL,
                failed_proof_attempts INTEGER NOT NULL,
                approval_token_hash VARCHAR(64) NULL,
                expires_at DATETIME NOT NULL,
                exchange_expires_at DATETIME NULL,
                exchanged_at DATETIME NULL,
                denied_at DATETIME NULL,
                cancelled_at DATETIME NULL,
                updated_at DATETIME NOT NULL
            )',
        );
        $this->store = new DbalLoginLinkStore($this->connection);
    }

    public function testFifthInvalidOriginProofCancelsRequest(): void
    {
        $this->insert('approved', '2026-08-09 11:59:00', '2026-08-09 12:02:00');
        $now = new DateTimeImmutable('2026-08-09T12:00:00+00:00');

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            self::assertSame($attempt, $this->store->recordFailedProof(self::REQUEST_ID, $now));
        }

        $row = $this->store->find(self::REQUEST_ID);
        self::assertNotNull($row);
        self::assertSame('cancelled', $row['status']);
        self::assertNotNull($row['cancelled_at']);
    }

    public function testApprovedRequestUsesExchangeGraceRatherThanOriginalExpiry(): void
    {
        $this->insert('approved', '2026-08-09 11:59:00', '2026-08-09 12:02:00');

        $this->store->expire(
            self::REQUEST_ID,
            new DateTimeImmutable('2026-08-09T12:00:00+00:00'),
        );
        self::assertSame('approved', $this->store->find(self::REQUEST_ID)['status'] ?? null);

        $this->store->expire(
            self::REQUEST_ID,
            new DateTimeImmutable('2026-08-09T12:02:00+00:00'),
        );
        self::assertSame('expired', $this->store->find(self::REQUEST_ID)['status'] ?? null);
    }

    public function testMaintenanceExpiresAndPurgesRequestsPastRetention(): void
    {
        $this->insert('pending', '2026-07-01 12:00:00', null);

        $result = $this->store->purgeExpired(
            new DateTimeImmutable('2026-08-09T12:00:00+00:00'),
            new DateTimeImmutable('2026-07-10T12:00:00+00:00'),
            100,
        );

        self::assertSame(['expired' => 1, 'purged' => 1], $result);
        self::assertNull($this->store->find(self::REQUEST_ID));
    }

    private function insert(string $status, string $expiresAt, ?string $exchangeExpiresAt): void
    {
        $this->connection->insert('auth_login_link_requests', [
            'id' => self::REQUEST_ID,
            'normalized_email' => 'person@example.test',
            'application_kind' => 'homeowner',
            'revision' => 1,
            'status' => $status,
            'failed_proof_attempts' => 0,
            'approval_token_hash' => 'approval-hash',
            'expires_at' => $expiresAt,
            'exchange_expires_at' => $exchangeExpiresAt,
            'exchanged_at' => null,
            'denied_at' => null,
            'cancelled_at' => null,
            'updated_at' => '2026-08-09 11:45:00',
        ]);
    }
}
