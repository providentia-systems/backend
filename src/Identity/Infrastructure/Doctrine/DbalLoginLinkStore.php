<?php

declare(strict_types=1);

namespace Providentia\Identity\Infrastructure\Doctrine;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Providentia\Identity\Application\LoginLinkStore;

final class DbalLoginLinkStore implements LoginLinkStore
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function create(array $request): void
    {
        $this->connection->insert('auth_login_link_requests', $request);
    }

    public function find(string $requestId): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM auth_login_link_requests WHERE id = :id',
            ['id' => $requestId],
        );

        return $row === false ? null : $row;
    }

    public function findByPollChallenge(string $pollChallenge): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM auth_login_link_requests WHERE poll_challenge = :challenge',
            ['challenge' => $pollChallenge],
        );

        return $row === false ? null : $row;
    }

    public function lockEmail(string $normalizedEmail): void
    {
        // Every approval request for an email participates in the same write
        // lock, including before a users row exists. This serializes first
        // verification and default-home creation across concurrent requests.
        $this->connection->executeStatement(
            'UPDATE auth_login_link_requests SET updated_at = updated_at
             WHERE normalized_email = :email',
            ['email' => $normalizedEmail],
        );
    }

    public function reserveApproval(
        string $requestId,
        string $approvalTokenHash,
        DateTimeImmutable $approvedAt,
        DateTimeImmutable $exchangeExpiresAt,
    ): bool {
        return $this->connection->executeStatement(
            'UPDATE auth_login_link_requests
             SET status = :next, approval_token_hash = NULL, approved_at = :approved,
                 exchange_expires_at = :exchange_expiry, updated_at = :approved
             WHERE id = :id AND status = :pending AND approval_token_hash = :approval
               AND expires_at > :approved',
            [
                'next' => 'approving',
                'approved' => $this->date($approvedAt),
                'exchange_expiry' => $this->date($exchangeExpiresAt),
                'id' => $requestId,
                'pending' => 'pending',
                'approval' => $approvalTokenHash,
            ],
        ) === 1;
    }

    public function completeApproval(
        string $requestId,
        string $userId,
        ?string $onboardingHomeId,
        DateTimeImmutable $at,
    ): void {
        $updated = $this->connection->executeStatement(
            'UPDATE auth_login_link_requests
             SET status = :approved, user_id = :user, onboarding_home_id = :home, updated_at = :at
             WHERE id = :id AND status = :approving',
            [
                'approved' => 'approved',
                'user' => $userId,
                'home' => $onboardingHomeId,
                'at' => $this->date($at),
                'id' => $requestId,
                'approving' => 'approving',
            ],
        );
        if ($updated !== 1) {
            throw new \RuntimeException('The login-link approval transition was lost.');
        }
    }

    public function deny(string $requestId, string $approvalTokenHash, DateTimeImmutable $at): bool
    {
        return $this->connection->executeStatement(
            'UPDATE auth_login_link_requests
             SET status = :denied, approval_token_hash = NULL, denied_at = :at, updated_at = :at
             WHERE id = :id AND status = :pending AND approval_token_hash = :approval
               AND expires_at > :at',
            [
                'denied' => 'denied',
                'at' => $this->date($at),
                'id' => $requestId,
                'pending' => 'pending',
                'approval' => $approvalTokenHash,
            ],
        ) === 1;
    }

    public function expire(string $requestId, DateTimeImmutable $at): void
    {
        $this->connection->executeStatement(
            'UPDATE auth_login_link_requests SET status = :expired,
                    approval_token_hash = NULL, updated_at = :at
             WHERE id = :id AND (
                 (status = :pending AND expires_at <= :at)
                 OR (status = :approved AND exchange_expires_at <= :at)
             )',
            [
                'expired' => 'expired',
                'at' => $this->date($at),
                'id' => $requestId,
                'pending' => 'pending',
                'approved' => 'approved',
            ],
        );
    }

    public function cancel(string $requestId, DateTimeImmutable $at): bool
    {
        return $this->connection->executeStatement(
            'UPDATE auth_login_link_requests SET status = :cancelled,
                    approval_token_hash = NULL, cancelled_at = :at, updated_at = :at
             WHERE id = :id AND status IN (:pending, :approved)',
            [
                'cancelled' => 'cancelled',
                'at' => $this->date($at),
                'id' => $requestId,
                'pending' => 'pending',
                'approved' => 'approved',
            ],
        ) === 1;
    }

    public function recordFailedProof(string $requestId, DateTimeImmutable $at): int
    {
        $this->connection->executeStatement(
            'UPDATE auth_login_link_requests
             SET failed_proof_attempts = failed_proof_attempts + 1,
                 status = CASE WHEN failed_proof_attempts >= 4 THEN :cancelled ELSE status END,
                 cancelled_at = CASE WHEN failed_proof_attempts >= 4 THEN :at ELSE cancelled_at END,
                 updated_at = :at
             WHERE id = :id AND status IN (:pending, :approved)',
            [
                'cancelled' => 'cancelled',
                'at' => $this->date($at),
                'id' => $requestId,
                'pending' => 'pending',
                'approved' => 'approved',
            ],
        );

        return (int) $this->connection->fetchOne(
            'SELECT failed_proof_attempts FROM auth_login_link_requests WHERE id = :id',
            ['id' => $requestId],
        );
    }

    public function reserveExchange(string $requestId, DateTimeImmutable $at): bool
    {
        return $this->connection->executeStatement(
            'UPDATE auth_login_link_requests
             SET status = :exchanging, exchanged_at = :at, updated_at = :at
             WHERE id = :id AND status = :approved AND exchange_expires_at > :at',
            [
                'exchanging' => 'exchanging',
                'at' => $this->date($at),
                'id' => $requestId,
                'approved' => 'approved',
            ],
        ) === 1;
    }

    public function failExchange(string $requestId, DateTimeImmutable $at): bool
    {
        return $this->connection->executeStatement(
            'UPDATE auth_login_link_requests
             SET status = :cancelled, cancelled_at = :at, updated_at = :at
             WHERE id = :id AND status = :exchanging',
            [
                'cancelled' => 'cancelled',
                'at' => $this->date($at),
                'id' => $requestId,
                'exchanging' => 'exchanging',
            ],
        ) === 1;
    }

    public function completeExchange(string $requestId, string $sessionId, DateTimeImmutable $at): void
    {
        $updated = $this->connection->executeStatement(
            'UPDATE auth_login_link_requests
             SET status = :exchanged, issued_session_id = :session, updated_at = :at
             WHERE id = :id AND status = :exchanging',
            [
                'exchanged' => 'exchanged',
                'session' => $sessionId,
                'at' => $this->date($at),
                'id' => $requestId,
                'exchanging' => 'exchanging',
            ],
        );
        if ($updated !== 1) {
            throw new \RuntimeException('The login-link exchange transition was lost.');
        }
    }

    public function purgeExpired(
        DateTimeImmutable $at,
        DateTimeImmutable $retentionCutoff,
        int $limit,
    ): array {
        $now = $this->date($at);
        $expired = (int) $this->connection->executeStatement(
            'UPDATE auth_login_link_requests
             SET status = :expired, approval_token_hash = NULL, updated_at = :at
             WHERE (status = :pending AND expires_at <= :at)
                OR (status = :approved AND exchange_expires_at <= :at)',
            [
                'expired' => 'expired',
                'at' => $now,
                'pending' => 'pending',
                'approved' => 'approved',
            ],
        );
        $ids = $this->connection->fetchFirstColumn(
            'SELECT id FROM auth_login_link_requests
             WHERE status IN (:expired, :denied, :cancelled, :exchanged)
               AND COALESCE(
                   exchanged_at,
                   denied_at,
                   cancelled_at,
                   exchange_expires_at,
                   expires_at,
                   updated_at
               ) <= :cutoff
             ORDER BY updated_at, id
             LIMIT :limit',
            [
                'expired' => 'expired',
                'denied' => 'denied',
                'cancelled' => 'cancelled',
                'exchanged' => 'exchanged',
                'cutoff' => $this->date($retentionCutoff),
                'limit' => max(1, min(1000, $limit)),
            ],
            ['limit' => ParameterType::INTEGER],
        );
        $purged = 0;
        foreach ($ids as $id) {
            $purged += (int) $this->connection->executeStatement(
                'DELETE FROM auth_login_link_requests
                 WHERE id = :id AND status IN (:expired, :denied, :cancelled, :exchanged)',
                [
                    'id' => (string) $id,
                    'expired' => 'expired',
                    'denied' => 'denied',
                    'cancelled' => 'cancelled',
                    'exchanged' => 'exchanged',
                ],
            );
        }

        return ['expired' => $expired, 'purged' => $purged];
    }

    private function date(DateTimeImmutable $date): string
    {
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
