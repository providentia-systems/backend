<?php

declare(strict_types=1);

namespace Providentia\SharedKernel\Infrastructure\Doctrine;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Providentia\SharedKernel\Application\Async\AsyncMessage;
use Providentia\SharedKernel\Application\Async\OutboxStore;

final class DoctrineOutboxStore implements OutboxStore
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function append(AsyncMessage $message): void
    {
        $this->connection->insert('outbox_messages', [
            'id' => $message->id,
            'message_type' => $message->type,
            'queue_name' => $message->queue,
            'payload' => json_encode($message->payload, JSON_THROW_ON_ERROR),
            'occurred_at' => $message->occurredAt->format('Y-m-d H:i:s.u'),
            'available_at' => $message->occurredAt->format('Y-m-d H:i:s.u'),
            'published_at' => null,
            'attempts' => 0,
            'last_error' => null,
            'status' => 'pending',
        ]);
    }

    public function claimBatch(int $limit): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, message_type, queue_name, payload, occurred_at, status
             FROM outbox_messages
             WHERE status IN (:pending, :publishing) AND available_at <= :now
             ORDER BY occurred_at ASC
             LIMIT ' . max(1, $limit),
            ['pending' => 'pending', 'publishing' => 'publishing', 'now' => $this->utcNow()],
        );

        $messages = [];
        foreach ($rows as $row) {
            $updated = $this->connection->executeStatement(
                'UPDATE outbox_messages
                 SET status = :publishing, attempts = attempts + 1, available_at = :lease_until
                 WHERE id = :id AND status = :expected_status AND available_at <= :now',
                [
                    'publishing' => 'publishing',
                    'expected_status' => $row['status'],
                    'id' => $row['id'],
                    'now' => $this->utcNow(),
                    'lease_until' => (new DateTimeImmutable('+60 seconds', new DateTimeZone('UTC')))
                        ->format('Y-m-d H:i:s.u'),
                ],
            );
            if ($updated !== 1) {
                continue;
            }

            /** @var array<string, mixed> $payload */
            $payload = json_decode((string) $row['payload'], true, 512, JSON_THROW_ON_ERROR);
            $messages[] = new AsyncMessage(
                (string) $row['id'],
                (string) $row['message_type'],
                $payload,
                new DateTimeImmutable((string) $row['occurred_at'], new DateTimeZone('UTC')),
                (string) $row['queue_name'],
            );
        }

        return $messages;
    }

    public function markPublished(string $messageId): void
    {
        $this->connection->update('outbox_messages', [
            'status' => 'published',
            'published_at' => $this->utcNow(),
            'last_error' => null,
        ], ['id' => $messageId]);
    }

    public function markFailed(string $messageId, string $reason, int $maxAttempts): void
    {
        $attempts = (int) $this->connection->fetchOne(
            'SELECT attempts FROM outbox_messages WHERE id = :id',
            ['id' => $messageId],
        );
        $dead = $attempts >= $maxAttempts;

        $this->connection->update('outbox_messages', [
            'status' => $dead ? 'failed' : 'pending',
            'available_at' => (new DateTimeImmutable(
                '+' . min(300, 2 ** max(1, $attempts)) . ' seconds',
                new DateTimeZone('UTC'),
            ))
                ->format('Y-m-d H:i:s.u'),
            'last_error' => mb_substr($reason, 0, 2000),
        ], ['id' => $messageId]);

        if ($dead) {
            $this->connection->executeStatement(
                'INSERT INTO async_failed_messages
                    (id, source_message_id, failed_at, reason, resolved_at)
                 VALUES (:id, :source, :failed, :reason, NULL)',
                [
                    'id' => $messageId,
                    'source' => $messageId,
                    'failed' => $this->utcNow(),
                    'reason' => mb_substr($reason, 0, 2000),
                ],
            );
        }
    }

    public function metrics(): array
    {
        $pending = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM outbox_messages WHERE status IN ('pending', 'publishing')",
        );
        $failed = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM outbox_messages WHERE status = 'failed'",
        );
        $oldest = $this->connection->fetchOne(
            "SELECT MIN(occurred_at) FROM outbox_messages WHERE status IN ('pending', 'publishing')",
        );
        $age = $oldest === false || $oldest === null
            ? 0.0
            : max(0.0, (float) (time() - (new DateTimeImmutable((string) $oldest))->getTimestamp()));

        return ['pending' => $pending, 'failed' => $failed, 'oldest_pending_seconds' => $age];
    }

    private function utcNow(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
    }
}
