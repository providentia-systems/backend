<?php

declare(strict_types=1);

namespace Providentia\Identity\Infrastructure\Doctrine;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\ParameterType;
use Providentia\Identity\Application\AuthenticationRateLimitStore;

final class DbalAuthenticationRateLimitStore implements AuthenticationRateLimitStore
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function consume(
        string $bucketHash,
        DateTimeImmutable $now,
        int $windowSeconds,
        int $maximumAttempts,
        int $blockSeconds,
    ): bool {
        // Ensure a row exists in its own transaction. A concurrent first
        // writer can win the unique key; the loser rolls back this short
        // transaction cleanly before entering the serialized update below.
        if (
            $this->connection->fetchOne(
                'SELECT bucket_hash FROM authentication_rate_limits WHERE bucket_hash = :hash',
                ['hash' => $bucketHash],
            ) === false
        ) {
            try {
                $this->connection->transactional(function () use ($bucketHash, $now): void {
                    $this->connection->insert('authentication_rate_limits', [
                        'bucket_hash' => $bucketHash,
                        'attempts' => 0,
                        'window_started_at' => $this->date($now),
                        'blocked_until' => null,
                        'updated_at' => $this->date($now),
                    ]);
                });
            } catch (UniqueConstraintViolationException) {
                // Another transaction established the shared bucket.
            }
        }

        return $this->connection->transactional(function () use (
            $bucketHash,
            $now,
            $windowSeconds,
            $maximumAttempts,
            $blockSeconds,
        ): bool {
            // A no-op write takes the row's database write lock portably on
            // SQLite, MySQL/MariaDB, and PostgreSQL before the read/modify/write.
            $this->connection->executeStatement(
                'UPDATE authentication_rate_limits SET updated_at = updated_at
                 WHERE bucket_hash = :hash',
                ['hash' => $bucketHash],
            );
            $row = $this->connection->fetchAssociative(
                'SELECT attempts, window_started_at, blocked_until
                 FROM authentication_rate_limits WHERE bucket_hash = :hash',
                ['hash' => $bucketHash],
            );
            if ($row === false) {
                throw new \RuntimeException('The authentication rate-limit bucket was not established.');
            }
            if (
                $row['blocked_until'] !== null
                && new DateTimeImmutable((string) $row['blocked_until']) > $now
            ) {
                return false;
            }
            $windowStart = new DateTimeImmutable((string) $row['window_started_at']);
            $attempts = $windowStart->modify('+' . $windowSeconds . ' seconds') <= $now
                ? 1
                : (int) $row['attempts'] + 1;
            $blocked = $attempts > $maximumAttempts
                ? $now->modify('+' . $blockSeconds . ' seconds')
                : null;
            $this->connection->update('authentication_rate_limits', [
                'attempts' => $attempts,
                'window_started_at' => $attempts === 1 ? $this->date($now) : $this->date($windowStart),
                'blocked_until' => $blocked === null ? null : $this->date($blocked),
                'updated_at' => $this->date($now),
            ], ['bucket_hash' => $bucketHash]);

            return $blocked === null;
        });
    }

    public function purgeInactive(
        DateTimeImmutable $now,
        DateTimeImmutable $retentionCutoff,
        int $limit,
    ): int {
        $ids = $this->connection->fetchFirstColumn(
            'SELECT bucket_hash FROM authentication_rate_limits
             WHERE updated_at <= :cutoff
               AND (blocked_until IS NULL OR blocked_until <= :now)
             ORDER BY updated_at, bucket_hash
             LIMIT :limit',
            [
                'cutoff' => $this->date($retentionCutoff),
                'now' => $this->date($now),
                'limit' => max(1, min(1000, $limit)),
            ],
            ['limit' => ParameterType::INTEGER],
        );
        $purged = 0;
        foreach ($ids as $id) {
            $purged += (int) $this->connection->executeStatement(
                'DELETE FROM authentication_rate_limits
                 WHERE bucket_hash = :hash AND updated_at <= :cutoff
                   AND (blocked_until IS NULL OR blocked_until <= :now)',
                [
                    'hash' => (string) $id,
                    'cutoff' => $this->date($retentionCutoff),
                    'now' => $this->date($now),
                ],
            );
        }

        return $purged;
    }

    private function date(DateTimeImmutable $date): string
    {
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
