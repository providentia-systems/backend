<?php

declare(strict_types=1);

namespace Providentia\Synchronization\Infrastructure\Doctrine;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Providentia\Synchronization\Application\SyncStore;
use Providentia\Synchronization\Application\SyncCommand;
use Providentia\Synchronization\Application\SyncOperation;
use Providentia\Synchronization\Application\SyncSnapshot;
use Providentia\Synchronization\Application\SyncSnapshotPage;
use Providentia\SharedKernel\Application\UuidGenerator;
use Providentia\SharedKernel\Application\Health\SyncMetricsProbe;

final class DbalSyncStore implements SyncStore, SyncMetricsProbe
{
    public function __construct(
        private readonly Connection $connection,
        private readonly UuidGenerator $ids,
        private readonly int $offlineWindowDays = 90,
        private readonly int $tombstoneRetentionDays = 120,
    ) {
        if ($this->offlineWindowDays < 1 || $this->tombstoneRetentionDays < $this->offlineWindowDays) {
            throw new \InvalidArgumentException(
                'Tombstone retention must be at least as long as the supported offline window.',
            );
        }
    }

    /** @return array<string, mixed> */
    public function apply(
        string $homeId,
        string $userId,
        string $deviceId,
        SyncOperation $operation,
        string $requestHash,
        DateTimeImmutable $at,
    ): array {
        return $this->connection->transactional(function () use (
            $homeId,
            $userId,
            $deviceId,
            $operation,
            $requestHash,
            $at,
        ): array {
            $membership = $this->one(
                'SELECT role, status FROM home_memberships
                 WHERE home_id = :home AND user_id = :user',
                ['home' => $homeId, 'user' => $userId],
            );
            if (
                $membership === null
                || (string) $membership['status'] !== 'active'
                || (string) $membership['role'] === 'viewer'
            ) {
                return [
                    'operationId' => $operation->operationId,
                    'status' => 'authorization_failure',
                    'detail' => 'The current membership cannot write this synchronized resource.',
                ];
            }
            $existingOperation = $this->one(
                'SELECT home_id, user_id, device_id, request_hash, response_json FROM client_operations
                 WHERE operation_id = :id',
                ['id' => $operation->operationId],
            );
            if ($existingOperation !== null) {
                if (
                    (string) $existingOperation['home_id'] === $homeId
                    && (string) $existingOperation['user_id'] === $userId
                    && (string) $existingOperation['device_id'] === $deviceId
                    && hash_equals((string) $existingOperation['request_hash'], $requestHash)
                ) {
                    $replay = json_decode(
                        (string) $existingOperation['response_json'],
                        true,
                        64,
                        JSON_THROW_ON_ERROR,
                    );
                    if (! is_array($replay)) {
                        throw new \RuntimeException('A stored synchronization receipt is invalid.');
                    }

                    return $replay;
                }

                return [
                    'operationId' => $operation->operationId,
                    'status' => 'conflict',
                    'code' => 'operation_id_reuse',
                    'detail' => 'The operation identifier was already bound to a different immutable request.',
                ];
            }

            $document = $this->one(
                'SELECT revision, payload_json, deleted_at FROM sync_documents
                 WHERE home_id = :home AND entity_type = :type AND entity_id = :entity',
                [
                    'home' => $homeId,
                    'type' => $operation->entityType,
                    'entity' => $operation->entityId,
                ],
            );
            $currentRevision = $document === null ? 0 : (int) $document['revision'];
            if ($currentRevision !== (int) $operation->baseRevision) {
                $response = [
                    'operationId' => $operation->operationId,
                    'status' => 'conflict',
                    'code' => 'revision_mismatch',
                    'currentRevision' => $currentRevision,
                    'current' => $document === null || $document['deleted_at'] !== null
                        ? null
                        : json_decode((string) $document['payload_json'], true, 64, JSON_THROW_ON_ERROR),
                ];
                $this->recordOperation(
                    $homeId,
                    $userId,
                    $deviceId,
                    $operation,
                    $requestHash,
                    $response,
                    $at,
                );

                return $response;
            }

            $revision = $currentRevision + 1;
            $deleted = $operation->operationType === 'delete';
            $payload = $deleted ? [] : $operation->payload;
            $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            if ($document === null) {
                $this->connection->insert('sync_documents', [
                    'home_id' => $homeId,
                    'entity_type' => $operation->entityType,
                    'entity_id' => $operation->entityId,
                    'revision' => $revision,
                    'payload_schema_version' => $operation->payloadSchemaVersion,
                    'payload_json' => $payloadJson,
                    'deleted_at' => $deleted ? $this->date($at) : null,
                    'updated_by_user_id' => $userId,
                    'updated_at' => $this->date($at),
                ]);
            } else {
                $updated = $this->connection->executeStatement(
                    'UPDATE sync_documents SET revision = :next_revision,
                            payload_schema_version = :schema_version,
                            payload_json = :payload, deleted_at = :deleted,
                            updated_by_user_id = :user, updated_at = :updated
                     WHERE home_id = :home AND entity_type = :type
                       AND entity_id = :entity AND revision = :expected_revision',
                    [
                        'next_revision' => $revision,
                        'schema_version' => $operation->payloadSchemaVersion,
                        'payload' => $payloadJson,
                        'deleted' => $deleted ? $this->date($at) : null,
                        'user' => $userId,
                        'updated' => $this->date($at),
                        'home' => $homeId,
                        'type' => $operation->entityType,
                        'entity' => $operation->entityId,
                        'expected_revision' => $currentRevision,
                    ],
                );
                if ($updated !== 1) {
                    return [
                        'operationId' => $operation->operationId,
                        'status' => 'retryable_failure',
                        'code' => 'concurrent_write',
                    ];
                }
            }

            $this->connection->insert('change_log', [
                'home_id' => $homeId,
                'entity_type' => $operation->entityType,
                'entity_id' => $operation->entityId,
                'operation_type' => $deleted ? 'delete' : 'put',
                'revision' => $revision,
                'payload_schema_version' => $operation->payloadSchemaVersion,
                'payload_json' => $payloadJson,
                'changed_by_user_id' => $userId,
                'changed_at' => $this->date($at),
            ]);
            $cursor = (int) $this->connection->lastInsertId();
            if ($deleted) {
                $this->replaceTombstone(
                    $homeId,
                    $operation->entityType,
                    $operation->entityId,
                    $revision,
                    $cursor,
                    $userId,
                    $at,
                );
            }
            $this->connection->insert('audit_events', [
                'id' => $this->ids->generate(),
                'home_id' => $homeId,
                'actor_user_id' => $userId,
                'action' => 'synchronization.' . ($deleted ? 'deleted' : 'updated'),
                'target_type' => $operation->entityType,
                'target_id' => $operation->entityId,
                'details' => json_encode(
                    ['revision' => $revision, 'cursor' => $cursor, 'deviceId' => $deviceId],
                    JSON_THROW_ON_ERROR,
                ),
                'occurred_at' => $this->date($at),
            ]);
            $this->connection->insert('outbox_messages', [
                'id' => $this->ids->generate(),
                'message_type' => 'synchronization.record-changed.v1',
                'queue_name' => 'providentia.default',
                'payload' => json_encode([
                    'homeId' => $homeId,
                    'entityType' => $operation->entityType,
                    'entityId' => $operation->entityId,
                    'revision' => $revision,
                    'cursor' => $cursor,
                ], JSON_THROW_ON_ERROR),
                'occurred_at' => $this->date($at),
                'available_at' => $this->date($at),
                'published_at' => null,
                'attempts' => 0,
                'last_error' => null,
                'status' => 'pending',
            ]);
            $response = [
                'operationId' => $operation->operationId,
                'status' => 'accepted',
                'entityType' => $operation->entityType,
                'entityId' => $operation->entityId,
                'serverRevision' => $revision,
                'cursor' => $cursor,
                'payload' => $payload,
                'deleted' => $deleted,
            ];
            $this->recordOperation(
                $homeId,
                $userId,
                $deviceId,
                $operation,
                $requestHash,
                $response,
                $at,
            );

            return $response;
        });
    }

    public function highWater(string $homeId): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COALESCE(MAX(sequence_id), 0) FROM change_log WHERE home_id = :home',
            ['home' => $homeId],
        );
    }

    public function captureSnapshot(string $homeId, int $limit): SyncSnapshot
    {
        $highWater = $this->highWater($homeId);
        $page = $this->captureSnapshotPage($homeId, $highWater, null, null, $limit);

        return new SyncSnapshot($highWater, $page->records);
    }

    public function captureSnapshotPage(
        string $homeId,
        int $highWater,
        ?string $afterEntityType,
        ?string $afterEntityId,
        int $limit,
    ): SyncSnapshotPage {
        $limit = max(1, $limit);
        $afterEntityType ??= '';
        $afterEntityId ??= '';
        $rows = $this->connection->fetchAllAssociative(
            'SELECT c.entity_type, c.entity_id, c.revision, c.payload_schema_version,
                    c.payload_json, c.changed_at
             FROM change_log c
             WHERE c.home_id = :home
               AND c.sequence_id <= :high_water
               AND c.operation_type <> :deleted
               AND (
                    c.entity_type > :after_type
                    OR (c.entity_type = :after_type AND c.entity_id > :after_id)
               )
               AND NOT EXISTS (
                    SELECT 1 FROM change_log newer
                    WHERE newer.home_id = c.home_id
                      AND newer.entity_type = c.entity_type
                      AND newer.entity_id = c.entity_id
                      AND newer.sequence_id > c.sequence_id
                      AND newer.sequence_id <= :high_water
               )
             ORDER BY c.entity_type ASC, c.entity_id ASC
             LIMIT ' . ($limit + 1),
            [
                'home' => $homeId,
                'high_water' => $highWater,
                'deleted' => 'delete',
                'after_type' => $afterEntityType,
                'after_id' => $afterEntityId,
            ],
        );
        $hasMore = count($rows) > $limit;
        if ($hasMore) {
            array_pop($rows);
        }

        return new SyncSnapshotPage(
            $highWater,
            array_map(static fn (array $row): array => [
                'entityType' => (string) $row['entity_type'],
                'entityId' => (string) $row['entity_id'],
                'revision' => (int) $row['revision'],
                'representationSchemaVersion' => (int) $row['payload_schema_version'],
                'representation' => array_merge(
                    ['id' => (string) $row['entity_id'], 'revision' => (int) $row['revision']],
                    (array) json_decode(
                        (string) $row['payload_json'],
                        true,
                        64,
                        JSON_THROW_ON_ERROR,
                    ),
                ),
                'serverTimestamp' => (string) $row['changed_at'],
            ], $rows),
            $hasMore,
        );
    }

    public function changes(string $homeId, int $after, int $highWater, int $limit): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT sequence_id, entity_type, entity_id, operation_type, revision,
                    payload_schema_version, payload_json, changed_at
             FROM change_log
             WHERE home_id = :home AND sequence_id > :after AND sequence_id <= :high_water
             ORDER BY sequence_id ASC LIMIT ' . max(1, $limit),
            ['home' => $homeId, 'after' => $after, 'high_water' => $highWater],
        );

        return array_map(static fn (array $row): array => [
            'cursor' => (int) $row['sequence_id'],
            'entityType' => (string) $row['entity_type'],
            'entityId' => (string) $row['entity_id'],
            'operationType' => (string) $row['operation_type'],
            'revision' => (int) $row['revision'],
            'payloadSchemaVersion' => (int) $row['payload_schema_version'],
            'payload' => (array) json_decode(
                (string) $row['payload_json'],
                true,
                64,
                JSON_THROW_ON_ERROR,
            ),
            'changedAt' => (string) $row['changed_at'],
        ], $rows);
    }

    public function acknowledgeCursor(
        string $homeId,
        string $userId,
        string $deviceId,
        int $position,
        DateTimeImmutable $at,
    ): void {
        $updated = $this->connection->executeStatement(
            'UPDATE sync_cursors SET last_acknowledged_cursor = :cursor,
                    schema_version = :schema, updated_at = :at
             WHERE home_id = :home AND user_id = :user AND device_id = :device',
            [
                'cursor' => $position,
                'schema' => 1,
                'at' => $this->date($at),
                'home' => $homeId,
                'user' => $userId,
                'device' => $deviceId,
            ],
        );
        if ($updated === 0) {
            $this->connection->insert('sync_cursors', [
                'home_id' => $homeId,
                'user_id' => $userId,
                'device_id' => $deviceId,
                'last_acknowledged_cursor' => $position,
                'schema_version' => 1,
                'updated_at' => $this->date($at),
            ]);
        }
    }

    public function operationReceipt(string $operationId): ?array
    {
        $row = $this->one(
            'SELECT home_id, user_id, device_id, request_hash, response_json
             FROM client_operations WHERE operation_id = :id',
            ['id' => $operationId],
        );
        if ($row === null) {
            return null;
        }
        $response = json_decode((string) $row['response_json'], true, 64, JSON_THROW_ON_ERROR);
        if (! is_array($response)) {
            throw new \RuntimeException('A stored synchronization receipt is invalid.');
        }

        return [
            'homeId' => (string) $row['home_id'],
            'userId' => (string) $row['user_id'],
            'deviceId' => (string) $row['device_id'],
            'requestHash' => (string) $row['request_hash'],
            'response' => $response,
        ];
    }

    public function recordCommandReceipt(
        string $homeId,
        string $userId,
        string $deviceId,
        SyncCommand $command,
        string $requestHash,
        array $response,
        DateTimeImmutable $at,
    ): void {
        $this->connection->insert('client_operations', [
            'operation_id' => $command->operationId,
            'home_id' => $homeId,
            'user_id' => $userId,
            'device_id' => $deviceId,
            'entity_type' => 'pantry-command',
            'entity_id' => $command->entityId,
            'operation_type' => $command->commandType,
            'base_revision' => $command->baseRevision,
            'payload_schema_version' => $command->payloadSchemaVersion,
            'request_hash' => $requestHash,
            'status' => (string) $response['status'],
            'response_json' => json_encode($response, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'client_timestamp' => $command->clientTimestamp,
            'processed_at' => $this->date($at),
        ]);
    }

    public function operationStatuses(
        string $homeId,
        string $userId,
        string $deviceId,
        array $operationIds,
    ): array {
        if ($operationIds === []) {
            return [];
        }
        $query = $this->connection->createQueryBuilder();
        $rows = $query
            ->select('operation_id', 'response_json')
            ->from('client_operations')
            ->where('home_id = :home')
            ->andWhere('user_id = :user')
            ->andWhere('device_id = :device')
            ->andWhere('operation_id IN (:ids)')
            ->setParameter('home', $homeId)
            ->setParameter('user', $userId)
            ->setParameter('device', $deviceId)
            ->setParameter('ids', $operationIds, ArrayParameterType::STRING)
            ->executeQuery()
            ->fetchAllAssociative();
        $statuses = [];
        foreach ($rows as $row) {
            $response = json_decode((string) $row['response_json'], true, 64, JSON_THROW_ON_ERROR);
            if (! is_array($response)) {
                throw new \RuntimeException('A stored synchronization receipt is invalid.');
            }
            $statuses[(string) $row['operation_id']] = $response;
        }

        return $statuses;
    }

    public function compactTombstones(string $homeId, DateTimeImmutable $at, int $batchSize): array
    {
        return $this->connection->transactional(function () use ($homeId, $at, $batchSize): array {
            $batchSize = max(1, min(1000, $batchSize));
            $activeSince = $at->modify('-' . $this->offlineWindowDays . ' days');
            $safeCursor = $this->connection->fetchOne(
                'SELECT MIN(last_acknowledged_cursor) FROM sync_cursors
                 WHERE home_id = :home AND updated_at >= :active_since',
                ['home' => $homeId, 'active_since' => $this->date($activeSince)],
            );
            if ($safeCursor === false || $safeCursor === null) {
                $safeCursor = $this->highWater($homeId);
            }
            $tombstones = $this->connection->fetchAllAssociative(
                'SELECT entity_type, entity_id, change_cursor FROM record_tombstones
                 WHERE home_id = :home AND retain_until IS NOT NULL
                   AND retain_until <= :now AND change_cursor <= :safe_cursor
                 ORDER BY change_cursor ASC LIMIT ' . $batchSize,
                ['home' => $homeId, 'now' => $this->date($at), 'safe_cursor' => (int) $safeCursor],
            );
            $deleted = 0;
            $minimumAvailable = $this->minimumAvailableCursor($homeId);
            foreach ($tombstones as $tombstone) {
                $entityType = (string) $tombstone['entity_type'];
                $entityId = (string) $tombstone['entity_id'];
                $changeCursor = (int) $tombstone['change_cursor'];
                $this->connection->executeStatement(
                    'DELETE FROM change_log
                     WHERE home_id = :home AND entity_type = :type
                       AND entity_id = :entity AND sequence_id <= :cursor',
                    [
                        'home' => $homeId,
                        'type' => $entityType,
                        'entity' => $entityId,
                        'cursor' => $changeCursor,
                    ],
                );
                $this->connection->delete('sync_documents', [
                    'home_id' => $homeId,
                    'entity_type' => $entityType,
                    'entity_id' => $entityId,
                ]);
                $deleted += (int) $this->connection->delete('record_tombstones', [
                    'home_id' => $homeId,
                    'entity_type' => $entityType,
                    'entity_id' => $entityId,
                ]);
                $minimumAvailable = max($minimumAvailable, $changeCursor);
            }
            if ($deleted > 0) {
                $existing = $this->connection->fetchOne(
                    'SELECT minimum_available_cursor FROM sync_retention_state WHERE home_id = :home',
                    ['home' => $homeId],
                );
                $values = [
                    'minimum_available_cursor' => $minimumAvailable,
                    'compacted_at' => $this->date($at),
                ];
                if ($existing === false) {
                    $this->connection->insert('sync_retention_state', ['home_id' => $homeId, ...$values]);
                } else {
                    $this->connection->update('sync_retention_state', $values, ['home_id' => $homeId]);
                }
            }

            return ['deleted' => $deleted, 'safeCursor' => (int) $safeCursor];
        });
    }

    public function minimumAvailableCursor(string $homeId): int
    {
        $cursor = $this->connection->fetchOne(
            'SELECT minimum_available_cursor FROM sync_retention_state WHERE home_id = :home',
            ['home' => $homeId],
        );

        return $cursor === false ? 0 : (int) $cursor;
    }

    public function homesWithExpiredTombstones(DateTimeImmutable $at, int $limit): array
    {
        $rows = $this->connection->fetchFirstColumn(
            'SELECT DISTINCT home_id FROM record_tombstones
             WHERE retain_until IS NOT NULL AND retain_until <= :now
             ORDER BY home_id ASC LIMIT ' . max(1, min(1000, $limit)),
            ['now' => $this->date($at)],
        );

        return array_values(array_map(static fn (mixed $homeId): string => (string) $homeId, $rows));
    }

    public function metrics(): array
    {
        $scalar = fn (string $sql): int => (int) $this->connection->fetchOne($sql);

        return [
            'operations' => $scalar('SELECT COUNT(*) FROM client_operations'),
            'accepted' => $scalar("SELECT COUNT(*) FROM client_operations WHERE status = 'accepted'"),
            'conflicts' => $scalar("SELECT COUNT(*) FROM client_operations WHERE status = 'conflict'"),
            'tombstones' => $scalar('SELECT COUNT(*) FROM record_tombstones'),
            'changes' => $scalar('SELECT COUNT(*) FROM change_log'),
            'cursors' => $scalar('SELECT COUNT(*) FROM sync_cursors'),
        ];
    }

    /**
     * @param array<string, mixed> $response
     */
    private function recordOperation(
        string $homeId,
        string $userId,
        string $deviceId,
        SyncOperation $operation,
        string $requestHash,
        array $response,
        DateTimeImmutable $at,
    ): void {
        $this->connection->insert('client_operations', [
            'operation_id' => $operation->operationId,
            'home_id' => $homeId,
            'user_id' => $userId,
            'device_id' => $deviceId,
            'entity_type' => $operation->entityType,
            'entity_id' => $operation->entityId,
            'operation_type' => $operation->operationType,
            'base_revision' => $operation->baseRevision,
            'payload_schema_version' => $operation->payloadSchemaVersion,
            'request_hash' => $requestHash,
            'status' => $response['status'],
            'response_json' => json_encode($response, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'client_timestamp' => $operation->clientTimestamp,
            'processed_at' => $this->date($at),
        ]);
    }

    private function replaceTombstone(
        string $homeId,
        string $entityType,
        string $entityId,
        int $revision,
        int $cursor,
        string $userId,
        DateTimeImmutable $at,
    ): void {
        $deleted = $this->connection->executeStatement(
            'DELETE FROM record_tombstones
             WHERE home_id = :home AND entity_type = :type AND entity_id = :entity',
            ['home' => $homeId, 'type' => $entityType, 'entity' => $entityId],
        );
        unset($deleted);
        $this->connection->insert('record_tombstones', [
            'home_id' => $homeId,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'revision' => $revision,
            'change_cursor' => $cursor,
            'deleted_by_user_id' => $userId,
            'deleted_at' => $this->date($at),
            'retain_until' => $this->date($at->modify('+' . $this->tombstoneRetentionDays . ' days')),
        ]);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>|null
     */
    private function one(string $sql, array $params): ?array
    {
        $row = $this->connection->fetchAssociative($sql, $params);

        return $row === false ? null : $row;
    }

    private function date(DateTimeImmutable $date): string
    {
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
