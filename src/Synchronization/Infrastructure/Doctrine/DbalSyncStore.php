<?php

declare(strict_types=1);

namespace Providentia\Synchronization\Infrastructure\Doctrine;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Providentia\Synchronization\Application\SyncStore;
use Providentia\SharedKernel\Application\UuidGenerator;
use Providentia\SharedKernel\Application\Health\SyncMetricsProbe;

final class DbalSyncStore implements SyncStore, SyncMetricsProbe
{
    public function __construct(
        private readonly Connection $connection,
        private readonly UuidGenerator $ids,
    ) {
    }

    public function apply(
        string $homeId,
        string $userId,
        string $deviceId,
        array $operation,
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
                    'operationId' => $operation['operationId'],
                    'status' => 'authorization_failure',
                    'detail' => 'The current membership cannot write this synchronized resource.',
                ];
            }
            $existingOperation = $this->one(
                'SELECT home_id, user_id, device_id, request_hash, response_json FROM client_operations
                 WHERE operation_id = :id',
                ['id' => $operation['operationId']],
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
                    'operationId' => $operation['operationId'],
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
                    'type' => $operation['entityType'],
                    'entity' => $operation['entityId'],
                ],
            );
            $currentRevision = $document === null ? 0 : (int) $document['revision'];
            if ($currentRevision !== (int) $operation['baseRevision']) {
                $response = [
                    'operationId' => $operation['operationId'],
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
            $deleted = $operation['operationType'] === 'delete';
            $payload = $deleted ? [] : $operation['payload'];
            $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            if ($document === null) {
                $this->connection->insert('sync_documents', [
                    'home_id' => $homeId,
                    'entity_type' => $operation['entityType'],
                    'entity_id' => $operation['entityId'],
                    'revision' => $revision,
                    'payload_schema_version' => $operation['payloadSchemaVersion'],
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
                        'schema_version' => $operation['payloadSchemaVersion'],
                        'payload' => $payloadJson,
                        'deleted' => $deleted ? $this->date($at) : null,
                        'user' => $userId,
                        'updated' => $this->date($at),
                        'home' => $homeId,
                        'type' => $operation['entityType'],
                        'entity' => $operation['entityId'],
                        'expected_revision' => $currentRevision,
                    ],
                );
                if ($updated !== 1) {
                    return [
                        'operationId' => $operation['operationId'],
                        'status' => 'retryable_failure',
                        'code' => 'concurrent_write',
                    ];
                }
            }

            $this->connection->insert('change_log', [
                'home_id' => $homeId,
                'entity_type' => $operation['entityType'],
                'entity_id' => $operation['entityId'],
                'operation_type' => $deleted ? 'delete' : 'put',
                'revision' => $revision,
                'payload_schema_version' => $operation['payloadSchemaVersion'],
                'payload_json' => $payloadJson,
                'changed_by_user_id' => $userId,
                'changed_at' => $this->date($at),
            ]);
            $cursor = (int) $this->connection->lastInsertId();
            if ($deleted) {
                $this->replaceTombstone(
                    $homeId,
                    (string) $operation['entityType'],
                    (string) $operation['entityId'],
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
                'target_type' => $operation['entityType'],
                'target_id' => $operation['entityId'],
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
                    'entityType' => $operation['entityType'],
                    'entityId' => $operation['entityId'],
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
                'operationId' => $operation['operationId'],
                'status' => 'accepted',
                'entityType' => $operation['entityType'],
                'entityId' => $operation['entityId'],
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

    public function snapshot(string $homeId, int $limit): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT entity_type, entity_id, revision, payload_schema_version,
                    payload_json, updated_at
             FROM sync_documents
             WHERE home_id = :home AND deleted_at IS NULL
             ORDER BY entity_type, entity_id LIMIT ' . max(1, $limit),
            ['home' => $homeId],
        );

        return array_map(static fn (array $row): array => [
            'entityType' => (string) $row['entity_type'],
            'entityId' => (string) $row['entity_id'],
            'revision' => (int) $row['revision'],
            'representationSchemaVersion' => (int) $row['payload_schema_version'],
            'representation' => array_merge(
                ['id' => (string) $row['entity_id'], 'revision' => (int) $row['revision']],
                (array) json_decode((string) $row['payload_json'], true, 64, JSON_THROW_ON_ERROR),
            ),
            'serverTimestamp' => (string) $row['updated_at'],
        ], $rows);
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

    /** @param array<string, mixed> $operation @param array<string, mixed> $response */
    private function recordOperation(
        string $homeId,
        string $userId,
        string $deviceId,
        array $operation,
        string $requestHash,
        array $response,
        DateTimeImmutable $at,
    ): void {
        $this->connection->insert('client_operations', [
            'operation_id' => $operation['operationId'],
            'home_id' => $homeId,
            'user_id' => $userId,
            'device_id' => $deviceId,
            'entity_type' => $operation['entityType'],
            'entity_id' => $operation['entityId'],
            'operation_type' => $operation['operationType'],
            'base_revision' => $operation['baseRevision'],
            'payload_schema_version' => $operation['payloadSchemaVersion'],
            'request_hash' => $requestHash,
            'status' => $response['status'],
            'response_json' => json_encode($response, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'client_timestamp' => $operation['clientTimestamp'],
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
            // Until product policy defines an offline-support window, tombstones are not compacted.
            'retain_until' => null,
        ]);
    }

    /** @param array<string, mixed> $params @return array<string, mixed>|null */
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
