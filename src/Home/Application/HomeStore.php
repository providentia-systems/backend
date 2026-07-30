<?php

declare(strict_types=1);

namespace Providentia\Home\Application;

use DateTimeImmutable;

interface HomeStore extends HomeAuditRecorder
{
    public function createHome(
        string $id,
        string $ownerUserId,
        string $name,
        string $locale,
        string $currency,
        string $timezone,
        DateTimeImmutable $at,
    ): void;

    /** @return list<array<string, mixed>> */
    public function listForUser(string $userId): array;

    /** @return array<string, mixed>|null */
    public function findHome(string $homeId): ?array;

    /** @return array<string, mixed>|null */
    public function membership(string $homeId, string $userId): ?array;

    /** @return list<array<string, mixed>> */
    public function memberships(string $homeId): array;

    public function createInvitation(
        string $id,
        string $homeId,
        string $inviterUserId,
        string $normalizedEmail,
        string $role,
        string $tokenHash,
        DateTimeImmutable $expiresAt,
        DateTimeImmutable $at,
    ): void;

    /** @return array<string, mixed>|null */
    public function acceptInvitation(
        string $tokenHash,
        string $userId,
        string $normalizedEmail,
        DateTimeImmutable $at,
    ): ?array;

    public function changeMembershipRole(
        string $homeId,
        string $userId,
        string $role,
        int $expectedRevision,
        DateTimeImmutable $at,
    ): bool;

    public function removeMembership(string $homeId, string $userId, DateTimeImmutable $at): bool;

    public function ownerCount(string $homeId): int;

    public function transferOwnership(
        string $homeId,
        string $currentOwnerUserId,
        string $nextOwnerUserId,
        int $expectedTargetRevision,
        DateTimeImmutable $at,
    ): bool;

    public function recordAudit(
        string $id,
        string $actorUserId,
        string $action,
        string $targetType,
        string $targetId,
        string $homeId,
        string $detailsJson,
        DateTimeImmutable $at,
    ): void;
}
