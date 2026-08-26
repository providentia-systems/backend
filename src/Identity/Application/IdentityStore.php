<?php

declare(strict_types=1);

namespace Providentia\Identity\Application;

use DateTimeImmutable;

interface IdentityStore
{
    /** @return array<string, mixed>|null */
    public function findUserByEmail(string $normalizedEmail): ?array;

    /** @return array<string, mixed>|null */
    public function findUserById(string $userId): ?array;

    public function createUser(
        string $id,
        string $email,
        string $displayName,
        string $locale,
        string $timezone,
        DateTimeImmutable $createdAt,
    ): void;

    public function issueOneTimeToken(
        string $id,
        string $userId,
        string $purpose,
        string $tokenHash,
        DateTimeImmutable $expiresAt,
        DateTimeImmutable $createdAt,
    ): void;

    public function consumeOneTimeToken(
        string $purpose,
        string $tokenHash,
        DateTimeImmutable $now,
    ): ?string;

    public function markEmailVerified(string $userId, DateTimeImmutable $at): void;

    public function claimEmailVerification(string $userId, DateTimeImmutable $at): bool;

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
        string $transport = 'native',
        int $refreshIdleTtlSeconds = 2592000,
        ?string $installationId = null,
        ?string $activeHomeId = null,
    ): void;

    /** @return array<string, mixed>|null */
    public function findSessionByAccessHash(string $accessHash, DateTimeImmutable $now): ?array;

    /** @return array<string, mixed>|null */
    public function findSessionByRefreshHash(string $refreshHash, DateTimeImmutable $now): ?array;

    public function revokeRefreshReplay(string $refreshHash, DateTimeImmutable $at): bool;

    public function rotateSession(
        string $sessionId,
        string $expectedRefreshHash,
        string $accessHash,
        string $refreshHash,
        string $csrfHash,
        DateTimeImmutable $accessExpiresAt,
        DateTimeImmutable $refreshExpiresAt,
        DateTimeImmutable $at,
    ): bool;

    /** @return list<array<string, mixed>> */
    public function listSessions(string $userId): array;

    /** @return array<string, mixed>|null */
    public function profile(string $userId): ?array;

    public function latestActiveHomeId(string $userId, DateTimeImmutable $now): ?string;

    public function revokeSession(string $userId, string $sessionId, DateTimeImmutable $at): bool;

    public function revokeSessionByRefreshProof(
        string $refreshHash,
        string $csrfHash,
        DateTimeImmutable $at,
    ): bool;

    public function revokeSessionByRefreshHash(string $refreshHash, DateTimeImmutable $at): bool;

    public function revokeAllSessions(string $userId, DateTimeImmutable $at): void;

    public function setActiveHome(string $sessionId, string $homeId, DateTimeImmutable $at): void;

    public function clearActiveHome(string $userId, string $homeId, DateTimeImmutable $at): void;

    public function verifyCsrf(string $sessionId, string $csrfHash): bool;

    /** @return list<string> */
    public function platformRoles(string $userId): array;

    public function seedBootstrapAdministrator(
        string $emailGrantId,
        string $auditId,
        string $userId,
        string $normalizedEmail,
        DateTimeImmutable $at,
    ): bool;

    public function activatePendingAdministratorGrant(
        string $auditId,
        string $userId,
        string $normalizedEmail,
        DateTimeImmutable $at,
    ): bool;

    /** @return list<array<string, mixed>> */
    public function listPlatformAdministrators(): array;

    /** @return array<string, mixed> */
    public function grantPlatformAdministrator(
        string $emailGrantId,
        string $auditId,
        string $actorUserId,
        string $normalizedEmail,
        DateTimeImmutable $at,
    ): array;

    /**
     * @return 'revoked'|'not-found'|'revision-conflict'|'last-administrator'
     */
    public function revokePlatformAdministrator(
        string $auditId,
        string $actorUserId,
        string $administratorId,
        int $expectedRevision,
        DateTimeImmutable $at,
    ): string;

}
