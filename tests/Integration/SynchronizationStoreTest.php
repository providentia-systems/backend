<?php

declare(strict_types=1);

namespace ProvidentiaTest\Integration;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Providentia\Synchronization\Application\SyncOperation;
use Providentia\Synchronization\Infrastructure\Doctrine\DbalSyncStore;

final class SynchronizationStoreTest extends TestCase
{
    private const HOME_ID = '01912345-6789-7abc-8def-0123456789ab';
    private const USER_ID = '01912345-6789-7abc-9def-0123456789ab';
    private const DEVICE_ONE = '01912345-6789-7abc-adef-0123456789ab';
    private const DEVICE_TWO = '01912345-6789-7abc-bdef-0123456789ab';
    private const ENTITY_ID = '01912345-6789-7abc-8def-1123456789ab';

    private Connection $connection;
    private DbalSyncStore $store;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);
        foreach (
            [
                'CREATE TABLE home_memberships (
                home_id VARCHAR(36) NOT NULL,
                user_id VARCHAR(36) NOT NULL,
                role VARCHAR(16) NOT NULL,
                status VARCHAR(16) NOT NULL,
                PRIMARY KEY (home_id, user_id)
            )',
                'CREATE TABLE client_operations (
                operation_id VARCHAR(36) PRIMARY KEY,
                home_id VARCHAR(36) NOT NULL,
                user_id VARCHAR(36) NOT NULL,
                device_id VARCHAR(36) NOT NULL,
                entity_type VARCHAR(64) NOT NULL,
                entity_id VARCHAR(36) NOT NULL,
                operation_type VARCHAR(16) NOT NULL,
                base_revision INTEGER NULL,
                payload_schema_version INTEGER NOT NULL,
                request_hash VARCHAR(64) NOT NULL,
                status VARCHAR(32) NOT NULL,
                response_json TEXT NOT NULL,
                client_timestamp VARCHAR(40) NOT NULL,
                processed_at DATETIME NOT NULL
            )',
                'CREATE TABLE sync_documents (
                home_id VARCHAR(36) NOT NULL,
                entity_type VARCHAR(64) NOT NULL,
                entity_id VARCHAR(36) NOT NULL,
                revision INTEGER NOT NULL,
                payload_schema_version INTEGER NOT NULL,
                payload_json TEXT NOT NULL,
                deleted_at DATETIME NULL,
                updated_by_user_id VARCHAR(36) NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (home_id, entity_type, entity_id)
            )',
                'CREATE TABLE change_log (
                sequence_id INTEGER PRIMARY KEY AUTOINCREMENT,
                home_id VARCHAR(36) NOT NULL,
                entity_type VARCHAR(64) NOT NULL,
                entity_id VARCHAR(36) NOT NULL,
                operation_type VARCHAR(16) NOT NULL,
                revision INTEGER NOT NULL,
                payload_schema_version INTEGER NOT NULL,
                payload_json TEXT NOT NULL,
                changed_by_user_id VARCHAR(36) NOT NULL,
                changed_at DATETIME NOT NULL
            )',
                'CREATE TABLE record_tombstones (
                home_id VARCHAR(36) NOT NULL,
                entity_type VARCHAR(64) NOT NULL,
                entity_id VARCHAR(36) NOT NULL,
                revision INTEGER NOT NULL,
                change_cursor INTEGER NOT NULL,
                deleted_by_user_id VARCHAR(36) NOT NULL,
                deleted_at DATETIME NOT NULL,
                retain_until DATETIME NULL,
                PRIMARY KEY (home_id, entity_type, entity_id)
            )',
                'CREATE TABLE audit_events (
                id VARCHAR(36) PRIMARY KEY,
                home_id VARCHAR(36) NULL,
                actor_user_id VARCHAR(36) NULL,
                action VARCHAR(120) NOT NULL,
                target_type VARCHAR(80) NOT NULL,
                target_id VARCHAR(64) NOT NULL,
                details TEXT NOT NULL,
                occurred_at DATETIME NOT NULL
            )',
                'CREATE TABLE outbox_messages (
                id VARCHAR(36) PRIMARY KEY,
                message_type VARCHAR(120) NOT NULL,
                queue_name VARCHAR(120) NOT NULL,
                payload TEXT NOT NULL,
                occurred_at DATETIME NOT NULL,
                available_at DATETIME NOT NULL,
                published_at DATETIME NULL,
                attempts INTEGER NOT NULL,
                last_error TEXT NULL,
                status VARCHAR(32) NOT NULL
            )',
                'CREATE TABLE sync_cursors (
                home_id VARCHAR(36) NOT NULL,
                user_id VARCHAR(36) NOT NULL,
                device_id VARCHAR(36) NOT NULL,
                last_acknowledged_cursor INTEGER NOT NULL,
                schema_version INTEGER NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (home_id, user_id, device_id)
            )',
            ] as $statement
        ) {
            $this->connection->executeStatement($statement);
        }
        $this->connection->insert('home_memberships', [
            'home_id' => self::HOME_ID,
            'user_id' => self::USER_ID,
            'role' => 'member',
            'status' => 'active',
        ]);
        $this->store = new DbalSyncStore($this->connection, new SequenceUuidGenerator());
    }

    public function testLostResponseRetryReplaysTheExactCommittedResult(): void
    {
        $operation = $this->operation('01912345-6789-7abc-9def-1123456789ab', null, 'put');
        $at = new DateTimeImmutable('2026-07-30T12:00:00+00:00');

        $first = $this->store->apply(
            self::HOME_ID,
            self::USER_ID,
            self::DEVICE_ONE,
            $operation,
            str_repeat('a', 64),
            $at,
        );
        $replay = $this->store->apply(
            self::HOME_ID,
            self::USER_ID,
            self::DEVICE_ONE,
            $operation,
            str_repeat('a', 64),
            $at->modify('+1 minute'),
        );

        self::assertSame($first, $replay);
        self::assertSame(1, $this->tableRowCount('client_operations'));
        self::assertSame(1, $this->tableRowCount('change_log'));
        self::assertSame(1, $this->tableRowCount('outbox_messages'));
        self::assertSame(1, $this->tableRowCount('audit_events'));
    }

    public function testChangingTheRouteHomeCannotCreateOrRevealState(): void
    {
        $result = $this->store->apply(
            '01912345-6789-7abc-8def-2123456789ab',
            self::USER_ID,
            self::DEVICE_ONE,
            $this->operation('01912345-6789-7abc-9def-1123456789ab', null, 'put'),
            str_repeat('a', 64),
            new DateTimeImmutable('2026-07-30T12:00:00+00:00'),
        );

        self::assertSame('authorization_failure', $result['status']);
        self::assertSame(0, $this->tableRowCount('client_operations'));
        self::assertSame(0, $this->tableRowCount('sync_documents'));
        self::assertSame(0, $this->tableRowCount('change_log'));
    }

    public function testOperationIdentifierCannotBeReplayedByAnotherDevice(): void
    {
        $operation = $this->operation('01912345-6789-7abc-9def-1123456789ab', null, 'put');
        $at = new DateTimeImmutable('2026-07-30T12:00:00+00:00');
        $this->store->apply(
            self::HOME_ID,
            self::USER_ID,
            self::DEVICE_ONE,
            $operation,
            str_repeat('a', 64),
            $at,
        );

        $result = $this->store->apply(
            self::HOME_ID,
            self::USER_ID,
            self::DEVICE_TWO,
            $operation,
            str_repeat('a', 64),
            $at,
        );

        self::assertSame('conflict', $result['status']);
        self::assertSame('operation_id_reuse', $result['code']);
        self::assertSame(1, $this->tableRowCount('change_log'));
    }

    public function testConcurrentDeviceConflictAndTombstoneAreDurable(): void
    {
        $at = new DateTimeImmutable('2026-07-30T12:00:00+00:00');
        $this->store->apply(
            self::HOME_ID,
            self::USER_ID,
            self::DEVICE_ONE,
            $this->operation('01912345-6789-7abc-9def-1123456789ab', null, 'put'),
            str_repeat('a', 64),
            $at,
        );
        $conflict = $this->store->apply(
            self::HOME_ID,
            self::USER_ID,
            self::DEVICE_TWO,
            $this->operation('01912345-6789-7abc-adef-1123456789ab', 0, 'put'),
            str_repeat('b', 64),
            $at,
        );
        $deleted = $this->store->apply(
            self::HOME_ID,
            self::USER_ID,
            self::DEVICE_ONE,
            $this->operation('01912345-6789-7abc-bdef-1123456789ab', 1, 'delete'),
            str_repeat('c', 64),
            $at,
        );

        self::assertSame('conflict', $conflict['status']);
        self::assertSame('revision_mismatch', $conflict['code']);
        self::assertSame('accepted', $deleted['status']);
        self::assertSame(2, $deleted['serverRevision']);
        self::assertSame(2, $this->tableRowCount('change_log'));
        self::assertSame(1, $this->tableRowCount('record_tombstones'));
        $tombstone = $this->connection->fetchAssociative(
            'SELECT retain_until FROM record_tombstones',
        );
        if ($tombstone === false) {
            self::fail('The durable tombstone is missing.');
        }
        self::assertNull($tombstone['retain_until']);
    }

    public function testCapturedSnapshotUsesTheSameHighWaterBoundaryAsItsRecords(): void
    {
        $this->store->apply(
            self::HOME_ID,
            self::USER_ID,
            self::DEVICE_ONE,
            $this->operation('01912345-6789-7abc-9def-1123456789ab', null, 'put'),
            str_repeat('a', 64),
            new DateTimeImmutable('2026-07-30T12:00:00+00:00'),
        );

        $snapshot = $this->store->captureSnapshot(self::HOME_ID, 251);

        self::assertSame(1, $snapshot->highWater);
        self::assertCount(1, $snapshot->records);
        self::assertSame(self::ENTITY_ID, $snapshot->records[0]['entityId']);
        self::assertSame(1, $snapshot->records[0]['revision']);
    }

    private function operation(
        string $operationId,
        ?int $baseRevision,
        string $type,
    ): SyncOperation
    {
        return new SyncOperation(
            $operationId,
            'private-note',
            self::ENTITY_ID,
            $type,
            $baseRevision,
            '2026-07-30T11:59:00+00:00',
            1,
            $type === 'delete' ? [] : ['body' => 'freezer'],
        );
    }

    private function tableRowCount(string $table): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM ' . $table);
    }
}
