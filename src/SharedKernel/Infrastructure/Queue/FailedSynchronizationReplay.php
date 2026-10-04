<?php

declare(strict_types=1);

namespace Providentia\SharedKernel\Infrastructure\Queue;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use LogicException;
use Providentia\SharedKernel\Application\Async\AsyncMessage;
use Providentia\SharedKernel\Application\Async\AsyncMessageBus;
use Throwable;
use UnexpectedValueException;

/** Replays retained notifications, never the household commands that produced them. */
final readonly class FailedSynchronizationReplay
{
    private const TYPE = 'synchronization.record-changed.v2';
    private const FAILURE = 'No handler is registered for synchronization.record-changed.v2';

    public function __construct(
        private Connection $connection,
        private ?AsyncMessageBus $bus = null,
    ) {
    }

    /**
     * The default is read-only and does not require a connection to a broker.
     * Original IDs preserve consumer deduplication on repeated or interrupted runs.
     * Failure rows are resolved only by the consumer after successful processing.
     *
     * @return array{ready: int, queued: int, blocked: int, items: list<array{id: string, status: string}>}
     */
    public function replay(int $limit = 100, bool $apply = false): array
    {
        if ($limit < 1 || $limit > 1000) {
            throw new InvalidArgumentException('Recovery limit must be between 1 and 1000.');
        }
        if ($apply && $this->bus === null) {
            throw new LogicException('Applying recovery requires the configured message bus.');
        }
        $ids = $this->connection->fetchFirstColumn(
            'SELECT DISTINCT source_message_id FROM async_failed_messages
             WHERE resolved_at IS NULL AND reason = :reason
             ORDER BY source_message_id LIMIT ' . $limit,
            ['reason' => self::FAILURE],
        );
        $result = ['ready' => 0, 'queued' => 0, 'blocked' => 0, 'items' => []];
        foreach ($ids as $id) {
            $id = (string) $id;
            $row = $this->connection->fetchAssociative(
                'SELECT id, message_type, queue_name, payload, occurred_at
                 FROM outbox_messages WHERE id = :id',
                ['id' => $id],
            );
            if ($row === false) {
                ++$result['blocked'];
                $result['items'][] = ['id' => $id, 'status' => 'missing-source'];
                continue;
            }
            try {
                $message = $this->originalMessage($row);
            } catch (Throwable) {
                ++$result['blocked'];
                $result['items'][] = ['id' => $id, 'status' => 'invalid-source'];
                continue;
            }
            ++$result['ready'];
            if (! $apply) {
                $result['items'][] = ['id' => $id, 'status' => 'ready'];
                continue;
            }
            try {
                $this->bus?->publish($message);
                ++$result['queued'];
                $result['items'][] = ['id' => $id, 'status' => 'queued'];
            } catch (Throwable) {
                ++$result['blocked'];
                $result['items'][] = ['id' => $id, 'status' => 'publish-failed'];
            }
        }

        return $result;
    }

    /** @param array<string, mixed> $row */
    private function originalMessage(array $row): AsyncMessage
    {
        if ($row['message_type'] !== self::TYPE || ! is_string($row['payload'])) {
            throw new UnexpectedValueException('The retained source is not a v2 notification.');
        }
        $payload = json_decode($row['payload'], true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($payload)) {
            throw new UnexpectedValueException('The retained payload is invalid.');
        }
        foreach (['homeId', 'entityType', 'entityId'] as $field) {
            if (! is_string($payload[$field] ?? null) || trim($payload[$field]) === '') {
                throw new UnexpectedValueException('The retained payload is invalid.');
            }
        }
        foreach (['revision', 'cursor'] as $field) {
            if (! is_int($payload[$field] ?? null) || $payload[$field] < 1) {
                throw new UnexpectedValueException('The retained payload is invalid.');
            }
        }
        if (! is_string($row['occurred_at']) || trim($row['occurred_at']) === '') {
            throw new UnexpectedValueException('The original occurrence time is missing.');
        }
        /** @var array<string, mixed> $payload */
        return new AsyncMessage(
            (string) $row['id'],
            self::TYPE,
            $payload,
            new DateTimeImmutable($row['occurred_at'], new DateTimeZone('UTC')),
            (string) $row['queue_name'],
        );
    }
}
