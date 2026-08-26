<?php

declare(strict_types=1);

namespace Providentia\Identity\Infrastructure\Doctrine;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Providentia\Identity\Application\ConcurrentPlatformRoleChange;
use Providentia\Identity\Application\IdentityStore;
use Providentia\Identity\Application\OperatorAccountControl;
use Providentia\Identity\Application\OperatorIdentityDirectory;
use Providentia\Identity\Application\PlatformRoleStore;

final class DbalIdentityStore implements
    IdentityStore,
    OperatorIdentityDirectory,
    OperatorAccountControl,
    PlatformRoleStore
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
        string $displayName,
        string $locale,
        string $timezone,
        DateTimeImmutable $createdAt,
    ): void {
        $this->connection->insert('users', [
            'id' => $id,
            'email' => $email,
            'normalized_email' => $email,
            'status' => 'active',
            'revision' => 1,
            'status_changed_at' => null,
            'suspended_at' => null,
            'closed_at' => null,
            'email_verified_at' => null,
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

    public function claimEmailVerification(string $userId, DateTimeImmutable $at): bool
    {
        return $this->connection->executeStatement(
            'UPDATE users SET email_verified_at = :at, updated_at = :at
             WHERE id = :id AND email_verified_at IS NULL',
            ['at' => $this->date($at), 'id' => $userId],
        ) === 1;
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
        ?DateTimeImmutable $refreshExpiresAt,
        DateTimeImmutable $createdAt,
        string $transport = 'native',
        int $refreshIdleTtlSeconds = 0,
        ?string $installationId = null,
        ?string $activeHomeId = null,
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
                'installation_id' => $installationId,
                'last_seen_at' => $this->date($createdAt),
                'revoked_at' => null,
                'created_at' => $this->date($createdAt),
            ]);
        } else {
            $this->connection->update('devices', [
                'name' => $deviceName,
                'platform' => $platform,
                'installation_id' => $installationId,
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
            'active_home_id' => $activeHomeId,
            'transport' => $transport,
            'refresh_idle_ttl_seconds' => $refreshIdleTtlSeconds,
            'access_expires_at' => $this->date($accessExpiresAt),
            'refresh_expires_at' => $refreshExpiresAt === null ? null : $this->date($refreshExpiresAt),
            'last_seen_at' => $this->date($createdAt),
            'revoked_at' => null,
            'created_at' => $this->date($createdAt),
        ]);
    }

    public function findSessionByAccessHash(string $accessHash, DateTimeImmutable $now): ?array
    {
        return $this->one(
            'SELECT s.*, COALESCE(d.installation_id, s.device_id) AS installation_id
             FROM auth_sessions s
             INNER JOIN users u ON u.id = s.user_id
             INNER JOIN devices d ON d.id = s.device_id AND d.user_id = s.user_id
             WHERE s.access_token_hash = :hash AND s.revoked_at IS NULL
               AND d.revoked_at IS NULL AND u.status = :status
               AND s.access_expires_at > :now
               AND (s.refresh_expires_at IS NULL OR s.refresh_expires_at > :now)',
            ['hash' => $accessHash, 'status' => 'active', 'now' => $this->date($now)],
        );
    }

    public function findSessionByRefreshHash(string $refreshHash, DateTimeImmutable $now): ?array
    {
        return $this->one(
            'SELECT s.*, COALESCE(d.installation_id, s.device_id) AS installation_id
             FROM auth_sessions s
             INNER JOIN users u ON u.id = s.user_id
             INNER JOIN devices d ON d.id = s.device_id AND d.user_id = s.user_id
             WHERE s.refresh_token_hash = :hash AND s.revoked_at IS NULL
               AND d.revoked_at IS NULL AND u.status = :status
               AND (s.refresh_expires_at IS NULL OR s.refresh_expires_at > :now)',
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
        ?DateTimeImmutable $refreshExpiresAt,
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
                    'refresh_expiry' => $refreshExpiresAt === null ? null : $this->date($refreshExpiresAt),
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
        $sessions = $this->connection->fetchAllAssociative(
            'SELECT s.id, s.device_id AS deviceId, d.name AS deviceName,
                    d.platform,
                    CASE WHEN s.transport = :web THEN :web ELSE :native END AS transport,
                    s.active_home_id AS activeHomeId,
                    s.created_at AS createdAt, s.last_seen_at AS lastSeenAt,
                    s.access_expires_at AS accessExpiresAt,
                    s.refresh_expires_at AS refreshExpiresAt,
                    s.refresh_expires_at AS idleExpiresAt,
                    s.revoked_at AS revokedAt
             FROM auth_sessions s INNER JOIN devices d ON d.id = s.device_id
             WHERE s.user_id = :user ORDER BY s.created_at DESC',
            ['user' => $userId, 'web' => 'web', 'native' => 'native'],
        );

        return array_map(function (array $session): array {
            foreach (
                ['createdAt', 'lastSeenAt', 'accessExpiresAt', 'refreshExpiresAt', 'idleExpiresAt'] as $field
            ) {
                $session[$field] = $session[$field] === null
                    ? null
                    : $this->atom((string) $session[$field]);
            }
            $session['revokedAt'] = $session['revokedAt'] === null
                ? null
                : $this->atom((string) $session['revokedAt']);

            return $session;
        }, $sessions);
    }

    public function profile(string $userId): ?array
    {
        return $this->one(
            'SELECT u.id AS userId, u.email, u.email_verified_at AS emailVerifiedAt,
                    u.status, p.display_name AS displayName, p.locale, p.timezone
             FROM users u INNER JOIN user_profiles p ON p.user_id = u.id
             WHERE u.id = :id',
            ['id' => $userId],
        );
    }

    public function latestActiveHomeId(string $userId, DateTimeImmutable $now): ?string
    {
        $value = $this->connection->fetchOne(
            'SELECT s.active_home_id FROM auth_sessions s
             INNER JOIN devices d ON d.id = s.device_id AND d.user_id = s.user_id
             INNER JOIN homes h ON h.id = s.active_home_id AND h.status = :active
             INNER JOIN home_memberships m
                     ON m.home_id = s.active_home_id AND m.user_id = s.user_id
                    AND m.status = :active
             WHERE s.user_id = :user AND s.active_home_id IS NOT NULL
               AND s.revoked_at IS NULL AND d.revoked_at IS NULL
               AND (s.refresh_expires_at IS NULL OR s.refresh_expires_at > :now)
             ORDER BY s.last_seen_at DESC, s.created_at DESC',
            ['user' => $userId, 'active' => 'active', 'now' => $this->date($now)],
        );

        return $value === false ? null : (string) $value;
    }

    public function revokeSession(string $userId, string $sessionId, DateTimeImmutable $at): bool
    {
        return $this->connection->executeStatement(
            'UPDATE auth_sessions SET revoked_at = :at
             WHERE id = :id AND user_id = :user AND revoked_at IS NULL',
            ['at' => $this->date($at), 'id' => $sessionId, 'user' => $userId],
        ) === 1;
    }

    public function revokeSessionByRefreshProof(
        string $refreshHash,
        string $csrfHash,
        DateTimeImmutable $at,
    ): bool {
        $session = $this->one(
            'SELECT id FROM auth_sessions
             WHERE refresh_token_hash = :refresh AND csrf_token_hash = :csrf',
            ['refresh' => $refreshHash, 'csrf' => $csrfHash],
        );
        if ($session === null) {
            return false;
        }
        $this->connection->executeStatement(
            'UPDATE auth_sessions SET revoked_at = :at
             WHERE id = :id AND revoked_at IS NULL',
            [
                'at' => $this->date($at),
                'id' => $session['id'],
            ],
        );

        return true;
    }

    public function revokeSessionByRefreshHash(string $refreshHash, DateTimeImmutable $at): bool
    {
        $sessionId = $this->connection->fetchOne(
            'SELECT id FROM auth_sessions WHERE refresh_token_hash = :hash',
            ['hash' => $refreshHash],
        );
        if ($sessionId === false) {
            $sessionId = $this->connection->fetchOne(
                'SELECT session_id FROM auth_refresh_history WHERE token_hash = :hash',
                ['hash' => $refreshHash],
            );
        }
        if ($sessionId === false) {
            return false;
        }
        $this->connection->executeStatement(
            'UPDATE auth_sessions SET revoked_at = :at WHERE id = :id AND revoked_at IS NULL',
            ['at' => $this->date($at), 'id' => $sessionId],
        );

        return true;
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

    public function clearActiveHome(string $userId, string $homeId, DateTimeImmutable $at): void
    {
        $this->connection->executeStatement(
            'UPDATE auth_sessions SET active_home_id = NULL, last_seen_at = :at
             WHERE user_id = :user AND active_home_id = :home',
            ['at' => $this->date($at), 'user' => $userId, 'home' => $homeId],
        );
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

    public function seedBootstrapAdministrator(
        string $emailGrantId,
        string $auditId,
        string $userId,
        string $normalizedEmail,
        DateTimeImmutable $at,
    ): bool {
        $this->serializeRole('platform_administrator');
        $user = $this->one(
            $this->forUpdate('SELECT revision FROM users WHERE id = :user'),
            ['user' => $userId],
        );
        if ($user === null) {
            return false;
        }
        $emailGrant = $this->one(
            'SELECT status FROM platform_administrator_email_grants WHERE normalized_email = :email',
            ['email' => $normalizedEmail],
        );
        $role = $this->one(
            'SELECT revoked_at FROM user_platform_roles WHERE user_id = :user AND role = :role',
            ['user' => $userId, 'role' => 'platform_administrator'],
        );
        if ($emailGrant !== null || $role !== null) {
            return false;
        }

        $date = $this->date($at);
        $this->connection->insert('platform_administrator_email_grants', [
            'id' => $emailGrantId,
            'normalized_email' => $normalizedEmail,
            'status' => 'active',
            'source' => 'bootstrap_env',
            'revision' => 1,
            'granted_by_user_id' => null,
            'accepted_by_user_id' => $userId,
            'accepted_at' => $date,
            'revoked_at' => null,
            'created_at' => $date,
            'updated_at' => $date,
        ]);
        $this->connection->insert('user_platform_roles', [
            'user_id' => $userId,
            'role' => 'platform_administrator',
            'granted_at' => $date,
            'revoked_at' => null,
            'granted_by_user_id' => null,
            'source' => 'bootstrap_env',
            'revision' => 1,
            'updated_at' => $date,
        ]);
        $this->advanceUserRevision($userId, (int) $user['revision'], $date);
        $this->connection->insert('audit_events', [
            'id' => $auditId,
            'home_id' => null,
            'actor_user_id' => null,
            'action' => 'platform.administrator.bootstrap-granted',
            'target_type' => 'user_platform_role',
            'target_id' => $userId,
            'details' => json_encode([
                'source' => 'bootstrap_env',
                'email' => $normalizedEmail,
                'revision' => 1,
            ], JSON_THROW_ON_ERROR),
            'occurred_at' => $date,
        ]);

        return true;
    }

    public function activatePendingAdministratorGrant(
        string $auditId,
        string $userId,
        string $normalizedEmail,
        DateTimeImmutable $at,
    ): bool {
        $this->serializeRole('platform_administrator');
        $user = $this->one(
            $this->forUpdate('SELECT revision FROM users WHERE id = :user'),
            ['user' => $userId],
        );
        if ($user === null) {
            return false;
        }
        $grant = $this->one(
            'SELECT id, revision, granted_by_user_id FROM platform_administrator_email_grants
             WHERE normalized_email = :email AND status = :status',
            ['email' => $normalizedEmail, 'status' => 'pending'],
        );
        if ($grant === null) {
            return false;
        }
        $date = $this->date($at);
        $updated = $this->connection->executeStatement(
            'UPDATE platform_administrator_email_grants
             SET status = :active, accepted_by_user_id = :user, accepted_at = :at,
                 revoked_at = NULL, revision = revision + 1, updated_at = :at
             WHERE id = :id AND status = :pending AND revision = :revision',
            [
                'active' => 'active',
                'user' => $userId,
                'at' => $date,
                'id' => $grant['id'],
                'pending' => 'pending',
                'revision' => $grant['revision'],
            ],
        );
        if ($updated !== 1) {
            return false;
        }
        $role = $this->one(
            'SELECT revision, revoked_at FROM user_platform_roles WHERE user_id = :user AND role = :role',
            ['user' => $userId, 'role' => 'platform_administrator'],
        );
        $roleChanged = $role === null || $role['revoked_at'] !== null;
        if ($role === null) {
            $roleRevision = 1;
            $this->connection->insert('user_platform_roles', [
                'user_id' => $userId,
                'role' => 'platform_administrator',
                'granted_at' => $date,
                'revoked_at' => null,
                'granted_by_user_id' => $grant['granted_by_user_id'],
                'source' => 'administrator',
                'revision' => $roleRevision,
                'updated_at' => $date,
            ]);
        } elseif ($role['revoked_at'] !== null) {
            $roleRevision = (int) $role['revision'] + 1;
            $this->connection->update('user_platform_roles', [
                'granted_at' => $date,
                'revoked_at' => null,
                'granted_by_user_id' => $grant['granted_by_user_id'],
                'source' => 'administrator',
                'revision' => $roleRevision,
                'updated_at' => $date,
            ], ['user_id' => $userId, 'role' => 'platform_administrator']);
        } else {
            $roleRevision = (int) $role['revision'];
        }
        if ($roleChanged) {
            $this->advanceUserRevision($userId, (int) $user['revision'], $date);
        }
        $this->connection->insert('audit_events', [
            'id' => $auditId,
            'home_id' => null,
            'actor_user_id' => $grant['granted_by_user_id'],
            'action' => 'platform.administrator.granted',
            'target_type' => 'user_platform_role',
            'target_id' => $userId,
            'details' => json_encode([
                'source' => 'administrator',
                'email' => $normalizedEmail,
                'revision' => $roleRevision,
            ], JSON_THROW_ON_ERROR),
            'occurred_at' => $date,
        ]);

        return true;
    }

    public function listPlatformAdministrators(): array
    {
        $active = $this->connection->fetchAllAssociative(
            'SELECT u.id, u.email, u.id AS userId, :active AS status,
                    r.revision, r.granted_by_user_id AS grantedByUserId,
                    r.granted_at AS createdAt, r.granted_at AS activatedAt
             FROM user_platform_roles r INNER JOIN users u ON u.id = r.user_id
             WHERE r.role = :role AND r.revoked_at IS NULL AND u.status = :user_status',
            [
                'active' => 'active',
                'role' => 'platform_administrator',
                'user_status' => 'active',
            ],
        );
        $pending = $this->connection->fetchAllAssociative(
            'SELECT id, normalized_email AS email, NULL AS userId, status,
                    revision, granted_by_user_id AS grantedByUserId,
                    created_at AS createdAt, NULL AS activatedAt
             FROM platform_administrator_email_grants WHERE status = :status',
            ['status' => 'pending'],
        );

        return array_map(function (array $administrator): array {
            $administrator['createdAt'] = $this->atom((string) $administrator['createdAt']);
            $administrator['activatedAt'] = $administrator['activatedAt'] === null
                ? null
                : $this->atom((string) $administrator['activatedAt']);

            return $administrator;
        }, array_merge($active, $pending));
    }

    public function grantPlatformAdministrator(
        string $emailGrantId,
        string $auditId,
        string $actorUserId,
        string $normalizedEmail,
        DateTimeImmutable $at,
    ): array {
        $date = $this->date($at);
        // Serialize administrator grant/revoke decisions across actors.
        $this->connection->executeStatement(
            'UPDATE user_platform_roles SET updated_at = updated_at
             WHERE role = :role AND revoked_at IS NULL',
            ['role' => 'platform_administrator'],
        );
        $user = $this->one(
            $this->forUpdate('SELECT id, revision FROM users WHERE normalized_email = :email
               AND status = :status AND email_verified_at IS NOT NULL'),
            ['email' => $normalizedEmail, 'status' => 'active'],
        );
        $grant = $this->one(
            'SELECT * FROM platform_administrator_email_grants WHERE normalized_email = :email',
            ['email' => $normalizedEmail],
        );
        $existingRole = $user === null ? null : $this->one(
            'SELECT revision, revoked_at, granted_by_user_id, granted_at
             FROM user_platform_roles WHERE user_id = :user AND role = :role',
            ['user' => $user['id'], 'role' => 'platform_administrator'],
        );
        if ($user === null && $grant !== null && (string) $grant['status'] === 'pending') {
            return [
                'changed' => false,
                'id' => (string) $grant['id'],
                'email' => $normalizedEmail,
                'userId' => null,
                'status' => 'pending',
                'revision' => (int) $grant['revision'],
                'grantedByUserId' => $grant['granted_by_user_id'],
                'createdAt' => $this->atom((string) $grant['created_at']),
                'activatedAt' => null,
            ];
        }
        if (
            $user !== null
            && $grant !== null
            && (string) $grant['status'] === 'active'
            && $existingRole !== null
            && $existingRole['revoked_at'] === null
        ) {
            return [
                'changed' => false,
                'id' => (string) $user['id'],
                'email' => $normalizedEmail,
                'userId' => (string) $user['id'],
                'status' => 'active',
                'revision' => (int) $existingRole['revision'],
                'grantedByUserId' => $existingRole['granted_by_user_id'],
                'createdAt' => $this->atom((string) $existingRole['granted_at']),
                'activatedAt' => $this->atom((string) $existingRole['granted_at']),
            ];
        }
        $grantId = $grant === null ? $emailGrantId : (string) $grant['id'];
        $grantRevision = $grant === null ? 1 : (int) $grant['revision'] + 1;
        $grantStatus = $user === null ? 'pending' : 'active';
        if ($grant === null) {
            $this->connection->insert('platform_administrator_email_grants', [
                'id' => $grantId,
                'normalized_email' => $normalizedEmail,
                'status' => $grantStatus,
                'source' => 'administrator',
                'revision' => $grantRevision,
                'granted_by_user_id' => $actorUserId,
                'accepted_by_user_id' => $user['id'] ?? null,
                'accepted_at' => $user === null ? null : $date,
                'revoked_at' => null,
                'created_at' => $date,
                'updated_at' => $date,
            ]);
        } else {
            $this->connection->update('platform_administrator_email_grants', [
                'status' => $grantStatus,
                'source' => 'administrator',
                'revision' => $grantRevision,
                'granted_by_user_id' => $actorUserId,
                'accepted_by_user_id' => $user['id'] ?? null,
                'accepted_at' => $user === null ? null : $date,
                'revoked_at' => null,
                'updated_at' => $date,
            ], ['id' => $grantId]);
        }

        $roleRevision = null;
        $roleChanged = false;
        if ($user !== null) {
            $userId = (string) $user['id'];
            $role = $existingRole;
            if ($role === null) {
                $roleChanged = true;
                $roleRevision = 1;
                $this->connection->insert('user_platform_roles', [
                    'user_id' => $userId,
                    'role' => 'platform_administrator',
                    'granted_at' => $date,
                    'revoked_at' => null,
                    'granted_by_user_id' => $actorUserId,
                    'source' => 'administrator',
                    'revision' => $roleRevision,
                    'updated_at' => $date,
                ]);
            } elseif ($role['revoked_at'] === null) {
                $roleRevision = (int) $role['revision'];
            } else {
                $roleChanged = true;
                $roleRevision = (int) $role['revision'] + 1;
                $this->connection->update('user_platform_roles', [
                    'granted_at' => $date,
                    'revoked_at' => null,
                    'granted_by_user_id' => $actorUserId,
                    'source' => 'administrator',
                    'revision' => $roleRevision,
                    'updated_at' => $date,
                ], ['user_id' => $userId, 'role' => 'platform_administrator']);
            }
            if ($roleChanged) {
                $this->advanceUserRevision($userId, (int) $user['revision'], $date);
            }
        }
        $this->connection->insert('audit_events', [
            'id' => $auditId,
            'home_id' => null,
            'actor_user_id' => $actorUserId,
            'action' => $grantStatus === 'active'
                ? 'platform.administrator.granted'
                : 'platform.administrator.pending',
            'target_type' => 'platform_administrator_email_grant',
            'target_id' => $grantId,
            'details' => json_encode([
                'status' => $grantStatus,
                'email' => $normalizedEmail,
                'revision' => $roleRevision ?? $grantRevision,
            ], JSON_THROW_ON_ERROR),
            'occurred_at' => $date,
        ]);

        return [
            'changed' => true,
            'id' => $user === null ? $grantId : (string) $user['id'],
            'email' => $normalizedEmail,
            'userId' => $user === null ? null : (string) $user['id'],
            'status' => $grantStatus,
            'revision' => $roleRevision ?? $grantRevision,
            'grantedByUserId' => $actorUserId,
            'createdAt' => $grant === null
                ? $at->format(DATE_ATOM)
                : $this->atom((string) $grant['created_at']),
            'activatedAt' => $user === null ? null : $at->format(DATE_ATOM),
        ];
    }

    public function revokePlatformAdministrator(
        string $auditId,
        string $actorUserId,
        string $administratorId,
        int $expectedRevision,
        DateTimeImmutable $at,
    ): string {
        $date = $this->date($at);
        $pending = $this->one(
            'SELECT id, normalized_email, revision FROM platform_administrator_email_grants
             WHERE id = :id AND status = :status',
            ['id' => $administratorId, 'status' => 'pending'],
        );
        if ($pending !== null) {
            if ((int) $pending['revision'] !== $expectedRevision) {
                return 'revision-conflict';
            }
            $updated = $this->connection->executeStatement(
                'UPDATE platform_administrator_email_grants
                 SET status = :revoked, revision = revision + 1,
                     revoked_at = :at, updated_at = :at
                 WHERE id = :id AND status = :pending AND revision = :revision',
                [
                    'revoked' => 'revoked',
                    'at' => $date,
                    'id' => $administratorId,
                    'pending' => 'pending',
                    'revision' => $expectedRevision,
                ],
            );
            if ($updated !== 1) {
                return 'revision-conflict';
            }
            $targetType = 'platform_administrator_email_grant';
            $targetEmail = (string) $pending['normalized_email'];
        } else {
            // Acquire write locks for all active administrator rows before
            // counting, including on databases without SELECT FOR UPDATE.
            $this->connection->executeStatement(
                'UPDATE user_platform_roles SET updated_at = updated_at
                 WHERE role = :role AND revoked_at IS NULL',
                ['role' => 'platform_administrator'],
            );
            $role = $this->one(
                $this->forUpdate('SELECT r.revision, u.normalized_email, u.revision AS user_revision
                 FROM user_platform_roles r INNER JOIN users u ON u.id = r.user_id
                 WHERE r.user_id = :user AND r.role = :role AND r.revoked_at IS NULL'),
                ['user' => $administratorId, 'role' => 'platform_administrator'],
            );
            if ($role === null) {
                return 'not-found';
            }
            if ((int) $role['revision'] !== $expectedRevision) {
                return 'revision-conflict';
            }
            $activeCount = (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM user_platform_roles r
                 INNER JOIN users u ON u.id = r.user_id AND u.status = :active
                 WHERE r.role = :role AND r.revoked_at IS NULL',
                ['active' => 'active', 'role' => 'platform_administrator'],
            );
            if ($activeCount <= 1) {
                return 'last-administrator';
            }
            $updated = $this->connection->executeStatement(
                'UPDATE user_platform_roles
                 SET revoked_at = :at, revision = revision + 1, updated_at = :at
                 WHERE user_id = :user AND role = :role
                   AND revoked_at IS NULL AND revision = :revision',
                [
                    'at' => $date,
                    'user' => $administratorId,
                    'role' => 'platform_administrator',
                    'revision' => $expectedRevision,
                ],
            );
            if ($updated !== 1) {
                return 'revision-conflict';
            }
            $this->advanceUserRevision(
                $administratorId,
                (int) $role['user_revision'],
                $date,
            );
            $this->connection->executeStatement(
                'UPDATE platform_administrator_email_grants
                 SET status = :revoked, revoked_at = :at,
                     revision = revision + 1, updated_at = :at
                 WHERE accepted_by_user_id = :user AND status = :active',
                [
                    'revoked' => 'revoked',
                    'at' => $date,
                    'user' => $administratorId,
                    'active' => 'active',
                ],
            );
            $targetType = 'user_platform_role';
            $targetEmail = (string) $role['normalized_email'];
        }
        $this->connection->insert('audit_events', [
            'id' => $auditId,
            'home_id' => null,
            'actor_user_id' => $actorUserId,
            'action' => 'platform.administrator.revoked',
            'target_type' => $targetType,
            'target_id' => $administratorId,
            'details' => json_encode([
                'email' => $targetEmail,
                'expectedRevision' => $expectedRevision,
                'revision' => $expectedRevision + 1,
            ], JSON_THROW_ON_ERROR),
            'occurred_at' => $date,
        ]);

        return 'revoked';
    }

    public function operatorAccounts(
        string $search,
        ?string $status,
        int $limit,
        int $offset,
        DateTimeImmutable $now,
    ): array {
        $pattern = '%' . mb_strtolower($search) . '%';
        $where = '(:status_empty = :empty OR u.status = :status)
              AND (:search_empty = :empty OR u.normalized_email LIKE :pattern
                   OR LOWER(p.display_name) LIKE :pattern)';
        $filterParameters = [
            'status_empty' => $status ?? '',
            'status' => $status ?? '',
            'search_empty' => $search,
            'empty' => '',
            'pattern' => $pattern,
        ];
        $parameters = array_merge($filterParameters, ['now' => $this->date($now)]);
        $rows = $this->connection->fetchAllAssociative(
            'SELECT u.id AS userId, u.email,
                    CASE WHEN u.email_verified_at IS NULL THEN 0 ELSE 1 END AS emailVerified,
                    p.display_name AS displayName, u.status, u.revision,
                    u.created_at AS createdAt, u.status_changed_at AS statusChangedAt,
                    u.suspended_at AS suspendedAt, u.closed_at AS closedAt,
                    (SELECT COUNT(*) FROM auth_sessions s
                     INNER JOIN devices d ON d.id = s.device_id AND d.user_id = s.user_id
                     WHERE s.user_id = u.id AND s.revoked_at IS NULL
                       AND d.revoked_at IS NULL
                       AND (s.refresh_expires_at IS NULL OR s.refresh_expires_at > :now)) AS activeSessionCount
             FROM users u INNER JOIN user_profiles p ON p.user_id = u.id
             WHERE ' . $where . '
             ORDER BY u.created_at DESC, u.id
             LIMIT ' . $limit . ' OFFSET ' . $offset,
            $parameters,
        );
        $total = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM users u INNER JOIN user_profiles p ON p.user_id = u.id
             WHERE ' . $where,
            $filterParameters,
        );
        $roles = $this->platformRolesForUsers(array_values(array_map(
            static fn (array $row): string => (string) $row['userId'],
            $rows,
        )));
        foreach ($rows as &$row) {
            $row = $this->operatorProjection($row);
            $row['platformRoles'] = $roles[(string) $row['userId']] ?? [];
        }
        unset($row);

        return ['items' => $rows, 'total' => $total];
    }

    public function operatorAccount(string $userId, DateTimeImmutable $now): ?array
    {
        $row = $this->one(
            'SELECT u.id AS userId, u.email,
                    CASE WHEN u.email_verified_at IS NULL THEN 0 ELSE 1 END AS emailVerified,
                    p.display_name AS displayName, u.status, u.revision,
                    u.created_at AS createdAt, u.status_changed_at AS statusChangedAt,
                    u.suspended_at AS suspendedAt, u.closed_at AS closedAt,
                    (SELECT COUNT(*) FROM auth_sessions s
                     INNER JOIN devices d ON d.id = s.device_id AND d.user_id = s.user_id
                     WHERE s.user_id = u.id AND s.revoked_at IS NULL
                       AND d.revoked_at IS NULL
                       AND (s.refresh_expires_at IS NULL OR s.refresh_expires_at > :now)) AS activeSessionCount
             FROM users u INNER JOIN user_profiles p ON p.user_id = u.id
             WHERE u.id = :user',
            ['user' => $userId, 'now' => $this->date($now)],
        );
        if ($row === null) {
            return null;
        }
        $account = $this->operatorProjection($row);
        $account['platformRoles'] = $this->platformRoles($userId);

        return $account;
    }

    public function verifiedAccountByEmail(string $normalizedEmail): ?array
    {
        $account = $this->one(
            'SELECT id AS userId, revision FROM users
             WHERE normalized_email = :email AND status = :active
               AND email_verified_at IS NOT NULL',
            ['email' => $normalizedEmail, 'active' => 'active'],
        );
        if ($account !== null) {
            $account['revision'] = (int) $account['revision'];
        }

        return $account;
    }

    public function updateOperatorAccountStatus(
        string $auditId,
        string $actorUserId,
        string $userId,
        string $status,
        string $reason,
        int $expectedRevision,
        DateTimeImmutable $at,
    ): string {
        // Role mutations take locks in this same role-then-user order. Keeping
        // one order prevents final-administrator checks on different accounts
        // from deadlocking each other.
        $this->serializeRole('platform_administrator');
        $user = $this->one(
            $this->forUpdate('SELECT id, status, revision FROM users WHERE id = :user'),
            ['user' => $userId],
        );
        if ($user === null) {
            return 'not-found';
        }
        if ((int) $user['revision'] !== $expectedRevision) {
            return 'revision-conflict';
        }
        if ((string) $user['status'] === $status) {
            return 'unchanged';
        }
        if ((string) $user['status'] === 'closed') {
            return 'closed-terminal';
        }
        if ($status !== 'active' && (string) $user['status'] === 'active') {
            if ($this->hasActiveAdministratorRole($userId) && $this->activeAdministratorCount() <= 1) {
                return 'last-administrator';
            }
        }
        $date = $this->date($at);
        $updated = $this->connection->update('users', [
            'status' => $status,
            'revision' => $expectedRevision + 1,
            'status_changed_at' => $date,
            'suspended_at' => $status === 'suspended' ? $date : null,
            'closed_at' => $status === 'closed' ? $date : null,
            'updated_at' => $date,
        ], ['id' => $userId, 'revision' => $expectedRevision]);
        if ($updated !== 1) {
            return 'revision-conflict';
        }
        if ($status !== 'active') {
            $this->connection->executeStatement(
                'UPDATE auth_sessions SET revoked_at = :at
                 WHERE user_id = :user AND revoked_at IS NULL',
                ['at' => $date, 'user' => $userId],
            );
            $this->connection->executeStatement(
                'UPDATE devices SET revoked_at = :at
                 WHERE user_id = :user AND revoked_at IS NULL',
                ['at' => $date, 'user' => $userId],
            );
        }
        $this->connection->insert('audit_events', [
            'id' => $auditId,
            'home_id' => null,
            'actor_user_id' => $actorUserId,
            'action' => 'platform.account.status-changed',
            'target_type' => 'user',
            'target_id' => $userId,
            'details' => json_encode([
                'from' => (string) $user['status'],
                'to' => $status,
                'reason' => $reason,
                'revision' => $expectedRevision + 1,
            ], JSON_THROW_ON_ERROR),
            'occurred_at' => $date,
        ]);

        return 'updated';
    }

    public function changePlatformRole(
        string $auditId,
        ?string $actorUserId,
        string $userId,
        string $role,
        bool $grant,
        int $expectedRevision,
        DateTimeImmutable $at,
    ): string {
        $this->serializeRole($role);
        $user = $this->one(
            $this->forUpdate('SELECT id, normalized_email, status, revision FROM users
             WHERE id = :user'),
            ['user' => $userId],
        );
        if ($user === null) {
            return 'not-found';
        }
        if ((int) $user['revision'] !== $expectedRevision) {
            return 'revision-conflict';
        }
        if ((string) $user['status'] === 'closed') {
            return 'closed-account';
        }
        $existing = $this->one(
            'SELECT revision, revoked_at FROM user_platform_roles
             WHERE user_id = :user AND role = :role',
            ['user' => $userId, 'role' => $role],
        );
        $currentlyGranted = $existing !== null && $existing['revoked_at'] === null;
        if ($grant === $currentlyGranted) {
            return 'unchanged';
        }
        if (
            ! $grant
            && $role === 'platform_administrator'
            && (string) $user['status'] === 'active'
            && $existing !== null
            && $existing['revoked_at'] === null
        ) {
            if ($this->activeAdministratorCount() <= 1) {
                return 'last-administrator';
            }
        }
        $date = $this->date($at);
        $updated = $this->connection->executeStatement(
            'UPDATE users SET revision = revision + 1, updated_at = :at
             WHERE id = :user AND revision = :revision',
            ['at' => $date, 'user' => $userId, 'revision' => $expectedRevision],
        );
        if ($updated !== 1) {
            return 'revision-conflict';
        }
        if ($grant) {
            if ($existing === null) {
                $this->connection->insert('user_platform_roles', [
                    'user_id' => $userId,
                    'role' => $role,
                    'granted_at' => $date,
                    'revoked_at' => null,
                    'granted_by_user_id' => $actorUserId,
                    'source' => $actorUserId === null ? 'owner_cli' : 'administrator',
                    'revision' => 1,
                    'updated_at' => $date,
                ]);
            } else {
                $this->connection->update('user_platform_roles', [
                    'granted_at' => $date,
                    'revoked_at' => null,
                    'granted_by_user_id' => $actorUserId,
                    'source' => $actorUserId === null ? 'owner_cli' : 'administrator',
                    'revision' => (int) $existing['revision'] + 1,
                    'updated_at' => $date,
                ], ['user_id' => $userId, 'role' => $role]);
            }
        } elseif ($existing !== null && $existing['revoked_at'] === null) {
            $this->connection->update('user_platform_roles', [
                'revoked_at' => $date,
                'revision' => (int) $existing['revision'] + 1,
                'updated_at' => $date,
            ], ['user_id' => $userId, 'role' => $role]);
        }
        if ($role === 'platform_administrator') {
            $this->synchronizeAdministratorEmailGrant(
                $auditId,
                $userId,
                (string) $user['normalized_email'],
                $actorUserId,
                $grant,
                $date,
            );
        }
        $this->connection->insert('audit_events', [
            'id' => $auditId,
            'home_id' => null,
            'actor_user_id' => $actorUserId,
            'action' => $grant ? 'platform.role.granted' : 'platform.role.revoked',
            'target_type' => 'user_platform_role',
            'target_id' => $userId,
            'details' => json_encode([
                'role' => $role,
                'revision' => $expectedRevision + 1,
                'source' => $actorUserId === null ? 'owner_cli' : 'administrator',
            ], JSON_THROW_ON_ERROR),
            'occurred_at' => $date,
        ]);

        return 'updated';
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function operatorProjection(array $row): array
    {
        $row['emailVerified'] = (bool) $row['emailVerified'];
        $row['revision'] = (int) $row['revision'];
        $row['activeSessionCount'] = (int) $row['activeSessionCount'];
        $row['createdAt'] = $this->atom((string) $row['createdAt']);
        foreach (['statusChangedAt', 'suspendedAt', 'closedAt'] as $date) {
            $row[$date] = $row[$date] === null ? null : $this->atom((string) $row[$date]);
        }

        return $row;
    }

    private function hasActiveAdministratorRole(string $userId): bool
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM user_platform_roles
             WHERE user_id = :user AND role = :role AND revoked_at IS NULL',
            ['user' => $userId, 'role' => 'platform_administrator'],
        ) === 1;
    }

    /**
     * @param list<string> $userIds
     * @return array<string, list<string>>
     */
    private function platformRolesForUsers(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }
        $roles = array_fill_keys($userIds, []);
        $rows = $this->connection->executeQuery(
            'SELECT user_id AS userId, role FROM user_platform_roles
             WHERE user_id IN (:users) AND revoked_at IS NULL
             ORDER BY user_id, role',
            ['users' => array_values(array_unique($userIds))],
            ['users' => ArrayParameterType::STRING],
        )->fetchAllAssociative();
        foreach ($rows as $row) {
            $roles[(string) $row['userId']][] = (string) $row['role'];
        }

        return $roles;
    }

    private function serializeRole(string $role): void
    {
        // The no-op write acquires row locks across supported transactional
        // databases and serializes grant/revoke/safeguard decisions.
        $this->connection->executeStatement(
            'UPDATE user_platform_roles SET updated_at = updated_at
             WHERE role = :role AND revoked_at IS NULL',
            ['role' => $role],
        );
    }

    private function advanceUserRevision(string $userId, int $expectedRevision, string $date): void
    {
        $updated = $this->connection->executeStatement(
            'UPDATE users SET revision = revision + 1, updated_at = :at
             WHERE id = :user AND revision = :revision',
            ['at' => $date, 'user' => $userId, 'revision' => $expectedRevision],
        );
        if ($updated !== 1) {
            throw new ConcurrentPlatformRoleChange(
                'A concurrent platform-role change prevented account revision advancement.',
            );
        }
    }

    private function activeAdministratorCount(): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM user_platform_roles r
             INNER JOIN users u ON u.id = r.user_id AND u.status = :active
             WHERE r.role = :role AND r.revoked_at IS NULL',
            ['active' => 'active', 'role' => 'platform_administrator'],
        );
    }

    private function synchronizeAdministratorEmailGrant(
        string $grantId,
        string $userId,
        string $email,
        ?string $actorUserId,
        bool $grant,
        string $date,
    ): void {
        $existing = $this->one(
            'SELECT id, revision FROM platform_administrator_email_grants
             WHERE normalized_email = :email',
            ['email' => $email],
        );
        if ($existing === null && $grant) {
            $this->connection->insert('platform_administrator_email_grants', [
                'id' => $grantId,
                'normalized_email' => $email,
                'status' => 'active',
                'source' => $actorUserId === null ? 'owner_cli' : 'administrator',
                'revision' => 1,
                'granted_by_user_id' => $actorUserId,
                'accepted_by_user_id' => $userId,
                'accepted_at' => $date,
                'revoked_at' => null,
                'created_at' => $date,
                'updated_at' => $date,
            ]);

            return;
        }
        if ($existing === null) {
            return;
        }
        $this->connection->update('platform_administrator_email_grants', [
            'status' => $grant ? 'active' : 'revoked',
            'source' => $actorUserId === null ? 'owner_cli' : 'administrator',
            'revision' => (int) $existing['revision'] + 1,
            'granted_by_user_id' => $actorUserId,
            'accepted_by_user_id' => $grant ? $userId : null,
            'accepted_at' => $grant ? $date : null,
            'revoked_at' => $grant ? null : $date,
            'updated_at' => $date,
        ], ['id' => $existing['id']]);
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

    private function atom(string $date): string
    {
        return (new DateTimeImmutable($date, new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s\Z');
    }

    private function forUpdate(string $sql): string
    {
        if ($this->connection->getDatabasePlatform() instanceof SQLitePlatform) {
            return $sql;
        }

        return $sql . ' FOR UPDATE';
    }
}
