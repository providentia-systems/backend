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

    public function updateHome(
        string $homeId,
        string $name,
        string $locale,
        string $currency,
        string $timezone,
        int $expectedRevision,
        DateTimeImmutable $at,
    ): bool;

    /** @return list<array<string, mixed>> */
    public function listForUser(string $userId): array;

    /** @return array<string, mixed>|null */
    public function findHome(string $homeId): ?array;

    /** @return array<string, mixed>|null */
    public function membership(string $homeId, string $userId): ?array;

    /** @return list<array<string, mixed>> */
    public function memberships(string $homeId): array;

    /**
     * Return null when a home has no persisted policy for the role yet. This
     * preserves the legacy role defaults during rolling deployments.
     */
    public function permissionDecision(string $homeId, string $role, string $permission): ?bool;

    /** @return list<array{role: string, revision: int, permissions: list<string>}> */
    public function permissionPolicies(string $homeId): array;

    /** @param list<string> $permissions */
    public function replaceRolePermissions(
        string $homeId,
        string $role,
        array $permissions,
        int $expectedRevision,
        string $updatedByUserId,
        DateTimeImmutable $at,
    ): bool;

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

    /** @return list<array<string, mixed>> */
    public function invitations(string $homeId): array;

    /** @return list<array<string, mixed>> */
    public function pendingInvitationsForEmail(string $normalizedEmail, DateTimeImmutable $at): array;

    /** @return array<string, mixed>|null */
    public function invitation(string $homeId, string $invitationId): ?array;

    public function revokeInvitation(
        string $homeId,
        string $invitationId,
        int $expectedRevision,
        string $revokedByUserId,
        DateTimeImmutable $at,
    ): bool;

    /** @return array<string, mixed>|null */
    public function acceptInvitation(
        string $tokenHash,
        string $userId,
        string $normalizedEmail,
        DateTimeImmutable $at,
    ): ?array;

    /** @return array<string, mixed> */
    public function acceptInvitationById(
        string $invitationId,
        string $userId,
        string $normalizedEmail,
        int $expectedRevision,
        DateTimeImmutable $at,
    ): array;

    public function changeMembershipRole(
        string $homeId,
        string $userId,
        string $role,
        int $expectedRevision,
        DateTimeImmutable $at,
    ): bool;

    public function removeMembership(string $homeId, string $userId, DateTimeImmutable $at): bool;

    public function ownerCount(string $homeId): int;

    public function createOwnershipTransfer(
        string $id,
        string $homeId,
        string $proposedByUserId,
        string $targetUserId,
        int $expectedTargetRevision,
        DateTimeImmutable $stepUpVerifiedAt,
        DateTimeImmutable $expiresAt,
        DateTimeImmutable $at,
    ): void;

    /** @return array<string, mixed>|null */
    public function ownershipTransfer(string $homeId, string $transferId): ?array;

    /** @return list<array<string, mixed>> */
    public function ownershipTransfers(string $homeId, ?string $participantUserId): array;

    public function transitionOwnershipTransfer(
        string $homeId,
        string $transferId,
        int $expectedRevision,
        string $status,
        DateTimeImmutable $at,
    ): bool;

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
