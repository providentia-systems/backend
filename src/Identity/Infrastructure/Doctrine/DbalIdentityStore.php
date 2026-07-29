<?php

declare(strict_types=1);

namespace Providentia\Identity\Infrastructure\Doctrine;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Providentia\Identity\Application\IdentityStore;

final class DbalIdentityStore implements IdentityStore
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function findUserByEmail(string $normalizedEmail): ?array
    {
        return $this->one(
            'SELECT * FROM users WHERE normalized_email = :email',
            ['email' => $normalizedEmail],
        );
    }

    public function findUserById(string $userId): ?array
    {
        return $this->one('SELECT * FROM users WHERE id = :id', ['id' => $userId]);
    }

    public function createUser(
        string $id,
        string $email,
        string $passwordHash,
        string $displayName,
        string $locale,
        string $timezone,
        DateTimeImmutable $createdAt,
    ): void {
        $this->connection->insert('users', [
            'id' => $id,
            'email' => $email,
            'normalized_email' => $email,
            'password_hash' => $passwordHash,
            'status' => 'active',
            'email_verified_at' => null,
            'failed_login_count' => 0,
            'locked_until' => null,
            'password_changed_at' => $this->date($createdAt),
            'created_at' => $this->date($createdAt),
            'updated_at' => $this->date($createdAt),
        ]);
        $this->connection->insert('user_profiles', [
            'user_id' => $id,
            'display_name' => $displayName,
            'locale' => mb_substr(trim($locale), 0, 16),
            'timezone' => mb_substr(trim($timezone), 0, 64),
            'created_at' => $this->date($createdAt),
            'updated_at' => $this->date($createdAt),
        ]);
    }

    public function issueOneTimeToken(
        string $id,
        string $userId,
        string $purpose,
        string $tokenHash,
        DateTimeImmutable $expiresAt,
        DateTimeImmutable $createdAt,
    ): void {
        $this->connection->executeStatement(
            'UPDATE auth_one_time_tokens SET consumed_at = :now
             WHERE user_id = :user AND purpose = :purpose AND consumed_at IS NULL',
            ['now' => $this->date($createdAt), 'user' => $userId, 'purpose' => $purpose],
        );
        $this->connection->insert('auth_one_time_tokens', [
            'id' => $id,
            'user_id' => $userId,
            'purpose' => $purpose,
            'token_hash' => $tokenHash,
            'expires_at' => $this->date($expiresAt),
            'consumed_at' => null,
            'created_at' => $this->date($createdAt),
        ]);
    }

    public function consumeOneTimeToken(
        string $purpose,
        string $tokenHash,
        DateTimeImmutable $now,
    ): ?string {
        $row = $this->one(
            'SELECT id, user_id FROM auth_one_time_tokens
             WHERE purpose = :purpose AND token_hash = :hash
               AND consumed_at IS NULL AND expires_at > :now',
            ['purpose' => $purpose, 'hash' => $tokenHash, 'now' => $this->date($now)],
        );
        if ($row === null) {
            return null;
        }
        $updated = $this->connection->executeStatement(
            'UPDATE auth_one_time_tokens SET consumed_at = :now
             WHERE id = :id AND consumed_at IS NULL AND expires_at > :now',
            ['now' => $this->date($now), 'id' => $row['id']],
        );

        return $updated === 1 ? (string) $row['user_id'] : null;
    }

    public function markEmailVerified(string $userId, DateTimeImmutable $at): void
    {
        $this->connection->update('users', [
            'email_verified_at' => $this->date($at),
            'updated_at' => $this->date($at),
        ], ['id' => $userId]);
    }

    public function changePassword(string $userId, string $passwordHash, DateTimeImmutable $at): void
    {
        $this->connection->update('users', [
            'password_hash' => $passwordHash,
            'password_changed_at' => $this->date($at),
            'updated_at' => $this->date($at),
        ], ['id' => $userId]);
    }

    public function createSession(
        string $sessionId,
        string $userId,
        string $deviceId,
        string $deviceName,
        string $platform,
        string $accessHash,
        string $refreshHash,
        string $csrfHash,
        DateTimeImmutable $accessExpiresAt,
        DateTimeImmutable $refreshExpiresAt,
        DateTimeImmutable $createdAt,
    ): void {
        $device = $this->one(
            'SELECT id, user_id FROM devices WHERE id = :id',
            ['id' => $deviceId],
        );
        if ($device !== null && (string) $device['user_id'] !== $userId) {
            throw new \DomainException('A device identifier cannot be reassigned to another account.');
        }
        if ($device === null) {
            $this->connection->insert('devices', [
                'id' => $deviceId,
                'user_id' => $userId,
                'name' => $deviceName,
                'platform' => $platform,
                'last_seen_at' => $this->date($createdAt),
                'revoked_at' => null,
                'created_at' => $this->date($createdAt),
            ]);
        } else {
            $this->connection->update('devices', [
                'name' => $deviceName,
                'platform' => $platform,
                'last_seen_at' => $this->date($createdAt),
                'revoked_at' => null,
            ], ['id' => $deviceId, 'user_id' => $userId]);
        }
        $this->connection->insert('auth_sessions', [
            'id' => $sessionId,
            'user_id' => $userId,
            'device_id' => $deviceId,
            'access_token_hash' => $accessHash,
            'refresh_token_hash' => $refreshHash,
            'csrf_token_hash' => $csrfHash,
            'active_home_id' => null,
            'access_expires_at' => $this->date($accessExpiresAt),
            'refresh_expires_at' => $this->date($refreshExpiresAt),
            'last_seen_at' => $this->date($createdAt),
            'revoked_at' => null,
            'created_at' => $this->date($createdAt),
        ]);
    }

    public function findSessionByAccessHash(string $accessHash, DateTimeImmutable $now): ?array
    {
        return $this->one(
            'SELECT s.* FROM auth_sessions s
             INNER JOIN users u ON u.id = s.user_id
             INNER JOIN devices d ON d.id = s.device_id AND d.user_id = s.user_id
             WHERE s.access_token_hash = :hash AND s.revoked_at IS NULL
               AND d.revoked_at IS NULL AND u.status = :status
               AND s.access_expires_at > :now',
            ['hash' => $accessHash, 'status' => 'active', 'now' => $this->date($now)],
        );
    }

    public function findSessionByRefreshHash(string $refreshHash, DateTimeImmutable $now): ?array
    {
        return $this->one(
            'SELECT s.* FROM auth_sessions s
             INNER JOIN users u ON u.id = s.user_id
             INNER JOIN devices d ON d.id = s.device_id AND d.user_id = s.user_id
             WHERE s.refresh_token_hash = :hash AND s.revoked_at IS NULL
               AND d.revoked_at IS NULL AND u.status = :status
               AND s.refresh_expires_at > :now',
            ['hash' => $refreshHash, 'status' => 'active', 'now' => $this->date($now)],
        );
    }

    public function revokeRefreshReplay(string $refreshHash, DateTimeImmutable $at): bool
    {
        $row = $this->one(
            'SELECT session_id FROM auth_refresh_history WHERE token_hash = :hash',
            ['hash' => $refreshHash],
        );
        if ($row === null) {
            return false;
        }
        $this->connection->update(
            'auth_sessions',
            ['revoked_at' => $this->date($at)],
            ['id' => $row['session_id']],
        );

        return true;
    }

    public function rotateSession(
        string $sessionId,
        string $expectedRefreshHash,
        string $accessHash,
        string $refreshHash,
        string $csrfHash,
        DateTimeImmutable $accessExpiresAt,
        DateTimeImmutable $refreshExpiresAt,
        DateTimeImmutable $at,
    ): bool {
        return $this->connection->transactional(function () use (
            $sessionId,
            $expectedRefreshHash,
            $accessHash,
            $refreshHash,
            $csrfHash,
            $accessExpiresAt,
            $refreshExpiresAt,
            $at,
        ): bool {
            $updated = $this->connection->executeStatement(
                'UPDATE auth_sessions SET
                   access_token_hash = :access,
                   refresh_token_hash = :refresh,
                   csrf_token_hash = :csrf,
                   access_expires_at = :access_expiry,
                   refresh_expires_at = :refresh_expiry,
                   last_seen_at = :seen
                 WHERE id = :id AND refresh_token_hash = :expected AND revoked_at IS NULL',
                [
                    'access' => $accessHash,
                    'refresh' => $refreshHash,
                    'csrf' => $csrfHash,
                    'access_expiry' => $this->date($accessExpiresAt),
                    'refresh_expiry' => $this->date($refreshExpiresAt),
                    'seen' => $this->date($at),
                    'id' => $sessionId,
                    'expected' => $expectedRefreshHash,
                ],
            );
            if ($updated !== 1) {
                $this->connection->update(
                    'auth_sessions',
                    ['revoked_at' => $this->date($at)],
                    ['id' => $sessionId],
                );

                return false;
            }
            $this->connection->insert('auth_refresh_history', [
                'token_hash' => $expectedRefreshHash,
                'session_id' => $sessionId,
                'rotated_at' => $this->date($at),
            ]);

            return true;
        });
    }

    public function listSessions(string $userId): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT s.id, s.device_id AS deviceId, d.name AS deviceName,
                    d.platform, s.active_home_id AS activeHomeId,
                    s.created_at AS createdAt, s.last_seen_at AS lastSeenAt,
                    s.access_expires_at AS accessExpiresAt,
                    s.refresh_expires_at AS refreshExpiresAt,
                    s.revoked_at AS revokedAt
             FROM auth_sessions s INNER JOIN devices d ON d.id = s.device_id
             WHERE s.user_id = :user ORDER BY s.created_at DESC',
            ['user' => $userId],
        );
    }

    public function revokeSession(string $userId, string $sessionId, DateTimeImmutable $at): bool
    {
        return $this->connection->executeStatement(
            'UPDATE auth_sessions SET revoked_at = :at
             WHERE id = :id AND user_id = :user AND revoked_at IS NULL',
            ['at' => $this->date($at), 'id' => $sessionId, 'user' => $userId],
        ) === 1;
    }

    public function revokeAllSessions(string $userId, DateTimeImmutable $at): void
    {
        $this->connection->executeStatement(
            'UPDATE auth_sessions SET revoked_at = :at
             WHERE user_id = :user AND revoked_at IS NULL',
            ['at' => $this->date($at), 'user' => $userId],
        );
    }

    public function setActiveHome(string $sessionId, string $homeId, DateTimeImmutable $at): void
    {
        $this->connection->update('auth_sessions', [
            'active_home_id' => $homeId,
            'last_seen_at' => $this->date($at),
        ], ['id' => $sessionId]);
    }

    public function verifyCsrf(string $sessionId, string $csrfHash): bool
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM auth_sessions
             WHERE id = :id AND csrf_token_hash = :hash AND revoked_at IS NULL',
            ['id' => $sessionId, 'hash' => $csrfHash],
        ) === 1;
    }

    public function platformRoles(string $userId): array
    {
        return array_map(
            static fn (array $row): string => (string) $row['role'],
            $this->connection->fetchAllAssociative(
                'SELECT role FROM user_platform_roles
                 WHERE user_id = :user AND revoked_at IS NULL ORDER BY role',
                ['user' => $userId],
            ),
        );
    }

    public function recordFailedLogin(string $userId, DateTimeImmutable $at): void
    {
        $this->connection->executeStatement(
            'UPDATE users SET
               failed_login_count = failed_login_count + 1,
               locked_until = CASE WHEN failed_login_count >= 4 THEN :locked ELSE locked_until END,
               updated_at = :now
             WHERE id = :id',
            [
                'locked' => $this->date($at->modify('+15 minutes')),
                'now' => $this->date($at),
                'id' => $userId,
            ],
        );
    }

    public function clearFailedLogin(string $userId): void
    {
        $this->connection->update('users', [
            'failed_login_count' => 0,
            'locked_until' => null,
        ], ['id' => $userId]);
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
        return $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
