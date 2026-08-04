<?php

declare(strict_types=1);

namespace Providentia\Synchronization\Infrastructure\Doctrine;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Providentia\SharedKernel\Application\ChangeFeedWriter;
use Providentia\SharedKernel\Application\UuidGenerator;

final readonly class DbalChangeFeedWriter implements ChangeFeedWriter
{
    public function __construct(
        private Connection $connection,
        private UuidGenerator $ids,
    ) {
    }

    public function put(
        string $homeId,
        string $actorUserId,
        string $entityType,
        string $entityId,
        int $revision,
        array $representation,
        DateTimeImmutable $at,
    ): int {
        if ($revision < 1) {
            throw new \InvalidArgumentException('A change-feed revision must be positive.');
        }
        $payload = json_encode($representation, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $timestamp = $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $this->connection->insert('change_log', [
            'home_id' => $homeId,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'operation_type' => 'put',
            'revision' => $revision,
            'payload_schema_version' => 1,
            'payload_json' => $payload,
            'changed_by_user_id' => $actorUserId,
            'changed_at' => $timestamp,
        ]);
        $cursor = (int) $this->connection->lastInsertId();
        $this->connection->insert('outbox_messages', [
            'id' => $this->ids->generate(),
            'message_type' => 'synchronization.record-changed.v2',
            'queue_name' => 'providentia.default',
            'payload' => json_encode([
                'homeId' => $homeId,
                'entityType' => $entityType,
                'entityId' => $entityId,
                'revision' => $revision,
                'cursor' => $cursor,
            ], JSON_THROW_ON_ERROR),
            'occurred_at' => $timestamp,
            'available_at' => $timestamp,
            'published_at' => null,
            'attempts' => 0,
            'last_error' => null,
            'status' => 'pending',
        ]);

        return $cursor;
    }
}
