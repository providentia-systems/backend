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
        string $passwordHash,
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

    public function changePassword(string $userId, string $passwordHash, DateTimeImmutable $at): void;

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

    public function revokeSession(string $userId, string $sessionId, DateTimeImmutable $at): bool;

    public function revokeAllSessions(string $userId, DateTimeImmutable $at): void;

    public function setActiveHome(string $sessionId, string $homeId, DateTimeImmutable $at): void;

    public function verifyCsrf(string $sessionId, string $csrfHash): bool;

    /** @return list<string> */
    public function platformRoles(string $userId): array;

    public function recordFailedLogin(string $userId, DateTimeImmutable $at): void;

    public function clearFailedLogin(string $userId): void;
}
