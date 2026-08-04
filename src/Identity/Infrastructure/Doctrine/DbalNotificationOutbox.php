<?php

declare(strict_types=1);

namespace Providentia\Identity\Infrastructure\Doctrine;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Providentia\Identity\Application\NotificationOutbox;
use Providentia\Identity\Application\NotificationPayloadCipher;
use RuntimeException;

final class DbalNotificationOutbox implements NotificationOutbox
{
    public function __construct(
        private readonly Connection $connection,
        private readonly NotificationPayloadCipher $cipher,
    ) {
    }

    public function enqueue(
        string $id,
        string $template,
        string $recipient,
        array $context,
        DateTimeImmutable $availableAt,
    ): void {
        $payload = $this->cipher->encrypt(
            json_encode($context, JSON_THROW_ON_ERROR),
            $this->associatedData($id, $template, $recipient),
        );
        $this->connection->insert('notification_outbox', [
            'id' => $id,
            'template' => $template,
            'recipient' => $recipient,
            'encrypted_payload' => $payload['ciphertext'],
            'nonce' => $payload['nonce'],
            'key_version' => $payload['keyVersion'],
            'status' => 'pending',
            'available_at' => $this->date($availableAt),
            'lease_until' => null,
            'attempts' => 0,
            'last_error' => null,
            'sent_at' => null,
            'dead_at' => null,
            'created_at' => $this->date($availableAt),
            'updated_at' => $this->date($availableAt),
        ]);
    }

    public function lease(int $limit, DateTimeImmutable $now, DateTimeImmutable $leaseUntil): array
    {
        return $this->connection->transactional(function () use ($limit, $now, $leaseUntil): array {
            $rows = $this->connection->fetchAllAssociative(
                'SELECT * FROM notification_outbox
                 WHERE status IN (:pending, :sending) AND available_at <= :now
                   AND (status = :pending OR lease_until < :now)
                 ORDER BY available_at, id',
                ['pending' => 'pending', 'sending' => 'sending', 'now' => $this->date($now)],
            );
            $messages = [];
            foreach (array_slice($rows, 0, max(1, $limit)) as $row) {
                $claimed = $this->connection->executeStatement(
                    'UPDATE notification_outbox
                     SET status = :sending, lease_until = :lease, updated_at = :now
                     WHERE id = :id AND status IN (:pending, :previous_sending)
                       AND (status = :pending OR lease_until < :now)',
                    [
                        'sending' => 'sending',
                        'lease' => $this->date($leaseUntil),
                        'now' => $this->date($now),
                        'id' => $row['id'],
                        'pending' => 'pending',
                        'previous_sending' => 'sending',
                    ],
                );
                if ($claimed !== 1) {
                    continue;
                }
                $plaintext = $this->cipher->decrypt(
                    (string) $row['encrypted_payload'],
                    (string) $row['nonce'],
                    (int) $row['key_version'],
                    $this->associatedData(
                        (string) $row['id'],
                        (string) $row['template'],
                        (string) $row['recipient'],
                    ),
                );
                $context = json_decode($plaintext, true, 32, JSON_THROW_ON_ERROR);
                if (! is_array($context)) {
                    throw new RuntimeException('The notification payload is not an object.');
                }
                foreach ($context as $key => $value) {
                    if (! is_string($key) || (! is_scalar($value) && $value !== null)) {
                        throw new RuntimeException('The notification payload contains an unsupported value.');
                    }
                }
                /** @var array<string, scalar|null> $context */
                $messages[] = [
                    'id' => (string) $row['id'],
                    'template' => (string) $row['template'],
                    'recipient' => (string) $row['recipient'],
                    'context' => $context,
                ];
            }

            return $messages;
        });
    }

    public function complete(string $id, DateTimeImmutable $at): void
    {
        $this->connection->update('notification_outbox', [
            'status' => 'sent',
            'lease_until' => null,
            'sent_at' => $this->date($at),
            'updated_at' => $this->date($at),
        ], ['id' => $id, 'status' => 'sending']);
    }

    public function fail(string $id, string $failureClass, DateTimeImmutable $at, int $maxAttempts): void
    {
        $attempts = (int) $this->connection->fetchOne(
            'SELECT attempts FROM notification_outbox WHERE id = :id',
            ['id' => $id],
        ) + 1;
        $dead = $attempts >= max(1, $maxAttempts);
        $delaySeconds = min(3600, 2 ** min(11, $attempts));
        $this->connection->update('notification_outbox', [
            'status' => $dead ? 'dead' : 'pending',
            'available_at' => $this->date($at->modify('+' . $delaySeconds . ' seconds')),
            'lease_until' => null,
            'attempts' => $attempts,
            'last_error' => mb_substr($failureClass, 0, 191),
            'dead_at' => $dead ? $this->date($at) : null,
            'updated_at' => $this->date($at),
        ], ['id' => $id, 'status' => 'sending']);
    }

    private function associatedData(string $id, string $template, string $recipient): string
    {
        return $id . "\0" . $template . "\0" . $recipient;
    }

    private function date(DateTimeImmutable $date): string
    {
        return $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
