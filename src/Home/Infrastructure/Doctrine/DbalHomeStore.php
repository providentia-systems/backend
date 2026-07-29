<?php

declare(strict_types=1);

namespace Providentia\Home\Infrastructure\Doctrine;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Providentia\Home\Application\HomeStore;

final class DbalHomeStore implements HomeStore
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function createHome(
        string $id,
        string $ownerUserId,
        string $name,
        string $locale,
        string $currency,
        string $timezone,
        DateTimeImmutable $at,
    ): void {
        $date = $this->date($at);
        $this->connection->insert('homes', [
            'id' => $id,
            'name' => $name,
            'default_locale' => mb_substr($locale, 0, 16),
            'default_currency' => $currency,
            'default_timezone' => mb_substr($timezone, 0, 64),
            'status' => 'active',
            'revision' => 1,
            'created_at' => $date,
            'updated_at' => $date,
        ]);
        $this->connection->insert('home_memberships', [
            'home_id' => $id,
            'user_id' => $ownerUserId,
            'role' => 'owner',
            'status' => 'active',
            'revision' => 1,
            'joined_at' => $date,
            'left_at' => null,
            'updated_at' => $date,
        ]);
    }

    public function listForUser(string $userId): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT h.id, h.name, h.default_locale AS defaultLocale,
                    h.default_currency AS defaultCurrency,
                    h.default_timezone AS defaultTimezone, h.status,
                    h.revision, m.role, m.revision AS membershipRevision
             FROM homes h INNER JOIN home_memberships m ON m.home_id = h.id
             WHERE m.user_id = :user AND m.status = :member_status AND h.status = :home_status
             ORDER BY h.name, h.id',
            ['user' => $userId, 'member_status' => 'active', 'home_status' => 'active'],
        );
    }

    public function findHome(string $homeId): ?array
    {
        return $this->one(
            'SELECT id, name, default_locale AS defaultLocale,
                    default_currency AS defaultCurrency,
                    default_timezone AS defaultTimezone, status, revision,
                    created_at AS createdAt, updated_at AS updatedAt
             FROM homes WHERE id = :id AND status = :status',
            ['id' => $homeId, 'status' => 'active'],
        );
    }

    public function membership(string $homeId, string $userId): ?array
    {
        return $this->one(
            'SELECT home_id, user_id, role, status, revision, joined_at, left_at
             FROM home_memberships WHERE home_id = :home AND user_id = :user',
            ['home' => $homeId, 'user' => $userId],
        );
    }

    public function memberships(string $homeId): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT m.user_id AS userId, p.display_name AS displayName, m.role,
                    m.status, m.revision, m.joined_at AS joinedAt
             FROM home_memberships m
             INNER JOIN user_profiles p ON p.user_id = m.user_id
             WHERE m.home_id = :home AND m.status = :status
             ORDER BY p.display_name, m.user_id',
            ['home' => $homeId, 'status' => 'active'],
        );
    }

    public function createInvitation(
        string $id,
        string $homeId,
        string $inviterUserId,
        string $normalizedEmail,
        string $role,
        string $tokenHash,
        DateTimeImmutable $expiresAt,
        DateTimeImmutable $at,
    ): void {
        $this->connection->insert('home_invitations', [
            'id' => $id,
            'home_id' => $homeId,
            'inviter_user_id' => $inviterUserId,
            'normalized_email' => $normalizedEmail,
            'role' => $role,
            'token_hash' => $tokenHash,
            'status' => 'pending',
            'expires_at' => $this->date($expiresAt),
            'accepted_by_user_id' => null,
            'accepted_at' => null,
            'revoked_at' => null,
            'created_at' => $this->date($at),
        ]);
    }

    public function acceptInvitation(
        string $tokenHash,
        string $userId,
        string $normalizedEmail,
        DateTimeImmutable $at,
    ): ?array {
        $invitation = $this->one(
            'SELECT id, home_id, inviter_user_id, role FROM home_invitations
             WHERE token_hash = :hash AND normalized_email = :email
               AND status = :status AND expires_at > :now',
            [
                'hash' => $tokenHash,
                'email' => $normalizedEmail,
                'status' => 'pending',
                'now' => $this->date($at),
            ],
        );
        if ($invitation === null) {
            return null;
        }
        $updated = $this->connection->executeStatement(
            'UPDATE home_invitations SET status = :accepted,
                    accepted_by_user_id = :user, accepted_at = :now
             WHERE id = :id AND status = :pending AND expires_at > :now
               AND EXISTS (
                   SELECT 1 FROM home_memberships inviter
                   WHERE inviter.home_id = home_invitations.home_id
                     AND inviter.user_id = :inviter
                     AND inviter.status = :active
                     AND (
                         inviter.role = :owner
                         OR (
                             inviter.role = :manager
                             AND home_invitations.role IN (:member, :viewer)
                         )
                     )
               )',
            [
                'accepted' => 'accepted',
                'user' => $userId,
                'now' => $this->date($at),
                'id' => $invitation['id'],
                'pending' => 'pending',
                'inviter' => $invitation['inviter_user_id'],
                'active' => 'active',
                'owner' => 'owner',
                'manager' => 'manager',
                'member' => 'member',
                'viewer' => 'viewer',
            ],
        );
        if ($updated !== 1) {
            return null;
        }
        $existing = $this->membership((string) $invitation['home_id'], $userId);
        if ($existing === null) {
            $this->connection->insert('home_memberships', [
                'home_id' => $invitation['home_id'],
                'user_id' => $userId,
                'role' => $invitation['role'],
                'status' => 'active',
                'revision' => 1,
                'joined_at' => $this->date($at),
                'left_at' => null,
                'updated_at' => $this->date($at),
            ]);
        } elseif ((string) $existing['status'] !== 'active') {
            $this->connection->update('home_memberships', [
                'role' => $invitation['role'],
                'status' => 'active',
                'revision' => (int) $existing['revision'] + 1,
                'joined_at' => $this->date($at),
                'left_at' => null,
                'updated_at' => $this->date($at),
            ], ['home_id' => $invitation['home_id'], 'user_id' => $userId]);
        }

        return [
            'invitationId' => (string) $invitation['id'],
            'homeId' => (string) $invitation['home_id'],
            'role' => (string) $invitation['role'],
        ];
    }

    public function changeMembershipRole(
        string $homeId,
        string $userId,
        string $role,
        int $expectedRevision,
        DateTimeImmutable $at,
    ): bool {
        return $this->connection->executeStatement(
            'UPDATE home_memberships SET role = :role, revision = revision + 1, updated_at = :at
             WHERE home_id = :home AND user_id = :user
               AND status = :status AND revision = :revision',
            [
                'role' => $role,
                'at' => $this->date($at),
                'home' => $homeId,
                'user' => $userId,
                'status' => 'active',
                'revision' => $expectedRevision,
            ],
        ) === 1;
    }

    public function removeMembership(string $homeId, string $userId, DateTimeImmutable $at): bool
    {
        return $this->connection->executeStatement(
            'UPDATE home_memberships SET status = :left, left_at = :at,
                    revision = revision + 1, updated_at = :at
             WHERE home_id = :home AND user_id = :user AND status = :active',
            [
                'left' => 'left',
                'at' => $this->date($at),
                'home' => $homeId,
                'user' => $userId,
                'active' => 'active',
            ],
        ) === 1;
    }

    public function ownerCount(string $homeId): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM home_memberships
             WHERE home_id = :home AND role = :role AND status = :status',
            ['home' => $homeId, 'role' => 'owner', 'status' => 'active'],
        );
    }

    public function transferOwnership(
        string $homeId,
        string $currentOwnerUserId,
        string $nextOwnerUserId,
        int $expectedTargetRevision,
        DateTimeImmutable $at,
    ): bool {
        $targetUpdated = $this->connection->executeStatement(
            'UPDATE home_memberships SET role = :owner, revision = revision + 1, updated_at = :at
             WHERE home_id = :home AND user_id = :target AND status = :active
               AND role <> :owner AND revision = :revision',
            [
                'owner' => 'owner',
                'at' => $this->date($at),
                'home' => $homeId,
                'target' => $nextOwnerUserId,
                'active' => 'active',
                'revision' => $expectedTargetRevision,
            ],
        );
        if ($targetUpdated !== 1) {
            return false;
        }
        $ownerUpdated = $this->connection->executeStatement(
            'UPDATE home_memberships SET role = :manager, revision = revision + 1, updated_at = :at
             WHERE home_id = :home AND user_id = :owner_user
               AND status = :active AND role = :owner',
            [
                'manager' => 'manager',
                'at' => $this->date($at),
                'home' => $homeId,
                'owner_user' => $currentOwnerUserId,
                'active' => 'active',
                'owner' => 'owner',
            ],
        );
        if ($ownerUpdated !== 1) {
            throw new \RuntimeException('Ownership changed during transfer.');
        }

        return true;
    }

    public function recordAudit(
        string $id,
        string $actorUserId,
        string $action,
        string $targetType,
        string $targetId,
        string $homeId,
        string $detailsJson,
        DateTimeImmutable $at,
    ): void {
        $this->connection->insert('audit_events', [
            'id' => $id,
            'home_id' => $homeId,
            'actor_user_id' => $actorUserId,
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'details' => $detailsJson,
            'occurred_at' => $this->date($at),
        ]);
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
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
