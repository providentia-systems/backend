<?php

declare(strict_types=1);

namespace Providentia\Identity\Infrastructure\Doctrine;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
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
        return $this->connection->transactional(function () use (
            $bucketHash,
            $now,
            $windowSeconds,
            $maximumAttempts,
            $blockSeconds,
        ): bool {
            $row = $this->connection->fetchAssociative(
                'SELECT attempts, window_started_at, blocked_until
                 FROM authentication_rate_limits WHERE bucket_hash = :hash',
                ['hash' => $bucketHash],
            );
            if ($row === false) {
                $this->connection->insert('authentication_rate_limits', [
                    'bucket_hash' => $bucketHash,
                    'attempts' => 1,
                    'window_started_at' => $this->date($now),
                    'blocked_until' => null,
                    'updated_at' => $this->date($now),
                ]);

                return true;
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

    private function date(DateTimeImmutable $date): string
    {
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
