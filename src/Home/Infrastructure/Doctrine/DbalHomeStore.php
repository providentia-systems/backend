<?php

declare(strict_types=1);

namespace Providentia\Home\Infrastructure\Doctrine;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Providentia\Home\Application\HomeAuthorization;
use Providentia\Home\Application\HomePermission;
use Providentia\Home\Application\HomeStore;
use Providentia\Home\Application\OperatorHomeAccessReader;

final class DbalHomeStore implements HomeStore, OperatorHomeAccessReader
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

    public function updateHome(
        ?string $homeId,
        string $name,
        string $locale,
        string $currency,
        string $timezone,
        int $expectedRevision,
        DateTimeImmutable $at,
    ): bool {
        return $this->connection->executeStatement(
            'UPDATE homes SET name = :name, default_locale = :locale,
                    default_currency = :currency, default_timezone = :timezone,
                    revision = revision + 1, updated_at = :at
             WHERE id = :id AND status = :status AND revision = :revision',
            [
                'name' => $name,
                'locale' => $locale,
                'currency' => $currency,
                'timezone' => $timezone,
                'at' => $this->date($at),
                'id' => $homeId,
                'status' => 'active',
                'revision' => $expectedRevision,
            ],
        ) === 1;
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

    public function operatorHomeAccess(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }
        $rows = $this->connection->executeQuery(
            'SELECT m.user_id AS userId, h.id AS homeId, h.name,
                    m.role AS membershipRole, m.status AS membershipStatus
             FROM home_memberships m INNER JOIN homes h ON h.id = m.home_id
             WHERE m.user_id IN (:users)
             ORDER BY m.user_id, h.name, h.id',
            ['users' => array_values(array_unique($userIds))],
            ['users' => ArrayParameterType::STRING],
        )->fetchAllAssociative();
        $access = array_fill_keys($userIds, []);
        foreach ($rows as $row) {
            $userId = (string) $row['userId'];
            $access[$userId][] = [
                'homeId' => (string) $row['homeId'],
                'name' => (string) $row['name'],
                'membershipRole' => (string) $row['membershipRole'],
                'membershipStatus' => (string) $row['membershipStatus'],
            ];
        }

        return $access;
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

    public function permissionDecision(string $homeId, string $role, string $permission): ?bool
    {
        $policy = $this->connection->fetchOne(
            'SELECT revision FROM home_role_policies WHERE home_id = :home AND role = :role',
            ['home' => $homeId, 'role' => $role],
        );
        if ($policy === false) {
            return null;
        }

        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM home_role_permission_grants
             WHERE home_id = :home AND role = :role AND permission = :permission',
            ['home' => $homeId, 'role' => $role, 'permission' => $permission],
        ) === 1;
    }

    public function permissionPolicies(string $homeId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT p.role, p.revision, g.permission
             FROM home_role_policies p
             LEFT JOIN home_role_permission_grants g
               ON g.home_id = p.home_id AND g.role = p.role
             WHERE p.home_id = :home
             ORDER BY p.role, g.permission',
            ['home' => $homeId],
        );
        /** @var array<string, array{role: string, revision: int, permissions: list<string>}> $policies */
        $policies = [];
        foreach ($rows as $row) {
            $role = (string) $row['role'];
            $policies[$role] ??= [
                'role' => $role,
                'revision' => (int) $row['revision'],
                'permissions' => [],
            ];
            if ($row['permission'] !== null) {
                $policies[$role]['permissions'][] = (string) $row['permission'];
            }
        }

        /** @var list<array{role: string, revision: int, permissions: list<string>}> $result */
        $result = array_values($policies);

        return $result;
    }

    public function replaceRolePermissions(
        string $homeId,
        string $role,
        array $permissions,
        int $expectedRevision,
        string $updatedByUserId,
        DateTimeImmutable $at,
    ): bool {
        $date = $this->date($at);
        if ($expectedRevision === 0) {
            try {
                $this->connection->insert('home_role_policies', [
                    'home_id' => $homeId,
                    'role' => $role,
                    'revision' => 1,
                    'updated_by_user_id' => $updatedByUserId,
                    'updated_at' => $date,
                ]);
            } catch (UniqueConstraintViolationException) {
                return false;
            }
        } else {
            $updated = $this->connection->executeStatement(
                'UPDATE home_role_policies
                 SET revision = revision + 1, updated_by_user_id = :actor, updated_at = :at
                 WHERE home_id = :home AND role = :role AND revision = :revision',
                [
                    'actor' => $updatedByUserId,
                    'at' => $date,
                    'home' => $homeId,
                    'role' => $role,
                    'revision' => $expectedRevision,
                ],
            );
            if ($updated !== 1) {
                return false;
            }
        }
        $this->connection->delete('home_role_permission_grants', ['home_id' => $homeId, 'role' => $role]);
        foreach ($permissions as $permission) {
            $this->connection->insert('home_role_permission_grants', [
                'home_id' => $homeId,
                'role' => $role,
                'permission' => $permission,
            ]);
        }

        return true;
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
            'revoked_by_user_id' => null,
            'revision' => 1,
            'created_at' => $this->date($at),
            'updated_at' => $this->date($at),
        ]);
    }

    public function invitations(string $homeId): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT id, home_id AS homeId, inviter_user_id AS inviterUserId,
                    normalized_email AS email, role, status,
                    expires_at AS expiresAt, accepted_by_user_id AS acceptedByUserId,
                    accepted_at AS acceptedAt, revoked_by_user_id AS revokedByUserId,
                    revoked_at AS revokedAt, revision, created_at AS createdAt,
                    updated_at AS updatedAt
             FROM home_invitations
             WHERE home_id = :home
             ORDER BY created_at DESC, id DESC',
            ['home' => $homeId],
        );
    }

    public function pendingInvitationsForEmail(string $normalizedEmail, DateTimeImmutable $at): array
    {
        $invitations = $this->connection->fetchAllAssociative(
            'SELECT i.id, i.home_id AS homeId, h.name AS homeName,
                    i.inviter_user_id AS inviterUserId,
                    inviter_profile.display_name AS inviterDisplayName,
                    i.role, i.status, i.revision, i.expires_at AS expiresAt
             FROM home_invitations i
             INNER JOIN homes h ON h.id = i.home_id AND h.status = :home_status
             INNER JOIN home_memberships inviter
                     ON inviter.home_id = i.home_id
                    AND inviter.user_id = i.inviter_user_id
                    AND inviter.status = :member_status
             LEFT JOIN user_profiles inviter_profile ON inviter_profile.user_id = i.inviter_user_id
             WHERE i.normalized_email = :email AND i.status = :pending
               AND i.expires_at > :at
             ORDER BY i.created_at, i.id',
            [
                'home_status' => 'active',
                'member_status' => 'active',
                'email' => $normalizedEmail,
                'pending' => 'pending',
                'at' => $this->date($at),
            ],
        );

        return array_map(static function (array $invitation): array {
            $invitation['expiresAt'] = (new DateTimeImmutable(
                (string) $invitation['expiresAt'],
                new DateTimeZone('UTC'),
            ))->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM);

            return $invitation;
        }, $invitations);
    }

    public function invitation(string $homeId, string $invitationId): ?array
    {
        return $this->one(
            'SELECT id, home_id AS homeId, inviter_user_id AS inviterUserId,
                    normalized_email AS email, role, status,
                    expires_at AS expiresAt, revision
             FROM home_invitations WHERE home_id = :home AND id = :id',
            ['home' => $homeId, 'id' => $invitationId],
        );
    }

    public function revokeInvitation(
        string $homeId,
        string $invitationId,
        int $expectedRevision,
        string $revokedByUserId,
        DateTimeImmutable $at,
    ): bool {
        return $this->connection->executeStatement(
            'UPDATE home_invitations
             SET status = :revoked, revoked_by_user_id = :actor, revoked_at = :at,
                 revision = revision + 1, updated_at = :at
             WHERE home_id = :home AND id = :id AND status = :pending
               AND revision = :revision',
            [
                'revoked' => 'revoked',
                'actor' => $revokedByUserId,
                'at' => $this->date($at),
                'home' => $homeId,
                'id' => $invitationId,
                'pending' => 'pending',
                'revision' => $expectedRevision,
            ],
        ) === 1;
    }

    public function acceptInvitation(
        string $tokenHash,
        string $userId,
        string $normalizedEmail,
        DateTimeImmutable $at,
    ): ?array {
        $invitation = $this->one(
            'SELECT id, home_id, inviter_user_id, role, revision FROM home_invitations
             WHERE token_hash = :hash AND normalized_email IN (SELECT normalized_email FROM user_emails WHERE user_id = :invitee)
               AND status = :status AND expires_at > :now',
            [
                'hash' => $tokenHash,
                'invitee' => $userId,
                'status' => 'pending',
                'now' => $this->date($at),
            ],
        );
        if ($invitation === null) {
            return null;
        }
        $updated = $this->connection->executeStatement(
            'UPDATE home_invitations SET status = :accepted,
                    accepted_by_user_id = :user, accepted_at = :now,
                    revision = revision + 1, updated_at = :now
             WHERE id = :id AND status = :pending AND expires_at > :now
               AND revision = :revision
               AND EXISTS (
                   SELECT 1 FROM home_memberships inviter
                   WHERE inviter.home_id = home_invitations.home_id
                     AND inviter.user_id = :inviter
                     AND inviter.status = :active
                     AND (
                         inviter.role = :owner
                         OR (
                             home_invitations.role IN (:member, :viewer)
                             AND (
                                 EXISTS (
                                     SELECT 1 FROM home_role_permission_grants grant_permission
                                     WHERE grant_permission.home_id = inviter.home_id
                                       AND grant_permission.role = inviter.role
                                       AND grant_permission.permission = :invite_permission
                                 )
                                 OR (
                                     inviter.role = :manager
                                     AND NOT EXISTS (
                                         SELECT 1 FROM home_role_policies role_policy
                                         WHERE role_policy.home_id = inviter.home_id
                                           AND role_policy.role = inviter.role
                                     )
                                 )
                             )
                         )
                     )
               )',
            [
                'accepted' => 'accepted',
                'user' => $userId,
                'now' => $this->date($at),
                'id' => $invitation['id'],
                'revision' => $invitation['revision'],
                'pending' => 'pending',
                'inviter' => $invitation['inviter_user_id'],
                'active' => 'active',
                'owner' => 'owner',
                'manager' => 'manager',
                'member' => 'member',
                'viewer' => 'viewer',
                'invite_permission' => HomePermission::MEMBERS_INVITE,
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
            'role' => $existing !== null && (string) $existing['status'] === 'active'
                ? (string) $existing['role']
                : (string) $invitation['role'],
        ];
    }

    public function acceptInvitationById(
        string $invitationId,
        string $userId,
        string $normalizedEmail,
        int $expectedRevision,
        DateTimeImmutable $at,
    ): array {
        $invitation = $this->one(
            'SELECT id, home_id, token_hash, role, status, revision,
                    normalized_email, expires_at, accepted_by_user_id
             FROM home_invitations WHERE id = :id',
            ['id' => $invitationId],
        );
        if ($invitation === null || ! $this->connection->fetchOne(
            'SELECT id FROM user_emails WHERE user_id = ? AND normalized_email = ?',
            [$userId, $invitation['normalized_email']],
        )) {
            return ['outcome' => 'not-found'];
        }
        if (
            (string) $invitation['status'] === 'accepted'
            && (string) $invitation['accepted_by_user_id'] === $userId
        ) {
            $membership = $this->membership((string) $invitation['home_id'], $userId);
            if ($membership !== null && (string) $membership['status'] === 'active') {
                return [
                    'outcome' => 'accepted',
                    'changed' => false,
                    'invitationId' => (string) $invitation['id'],
                    'homeId' => (string) $invitation['home_id'],
                    'role' => (string) $membership['role'],
                ];
            }
        }
        if ((string) $invitation['status'] !== 'pending') {
            return ['outcome' => 'not-found'];
        }
        if (
            new DateTimeImmutable(
                (string) $invitation['expires_at'],
                new DateTimeZone('UTC'),
            ) <= $at
        ) {
            $this->connection->executeStatement(
                'UPDATE home_invitations SET status = :expired,
                        revision = revision + 1, updated_at = :at
                 WHERE id = :id AND status = :pending',
                [
                    'expired' => 'expired',
                    'at' => $this->date($at),
                    'id' => $invitationId,
                    'pending' => 'pending',
                ],
            );

            return ['outcome' => 'expired'];
        }
        if ((int) $invitation['revision'] !== $expectedRevision) {
            return ['outcome' => 'revision-conflict'];
        }

        $result = $this->acceptInvitation(
            (string) $invitation['token_hash'],
            $userId,
            $normalizedEmail,
            $at,
        );
        if ($result === null) {
            return ['outcome' => 'revision-conflict'];
        }

        return ['outcome' => 'accepted', 'changed' => true] + $result;
    }

    public function declineInvitation(string $invitationId, string $userId, int $revision, DateTimeImmutable $at): bool
    {
        return $this->connection->executeStatement(
            "UPDATE home_invitations SET status = 'declined', revision = revision + 1, updated_at = :at
             WHERE id = :id AND status = 'pending' AND revision = :revision
               AND normalized_email IN (SELECT normalized_email FROM user_emails WHERE user_id = :user)",
            ['id' => $invitationId, 'user' => $userId, 'revision' => $revision, 'at' => $this->date($at)],
        ) === 1;
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

    public function removeMembershipAtRevision(
        string $homeId,
        string $userId,
        int $expectedRevision,
        DateTimeImmutable $at,
    ): bool {
        return $this->connection->executeStatement(
            'UPDATE home_memberships SET status = :removed, left_at = :at,
                    revision = revision + 1, updated_at = :at
             WHERE home_id = :home AND user_id = :user AND status = :active
               AND revision = :revision',
            [
                'removed' => 'removed',
                'at' => $this->date($at),
                'home' => $homeId,
                'user' => $userId,
                'active' => 'active',
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

    public function createOwnershipTransfer(
        string $id,
        string $homeId,
        string $proposedByUserId,
        string $targetUserId,
        int $expectedTargetRevision,
        DateTimeImmutable $stepUpVerifiedAt,
        DateTimeImmutable $expiresAt,
        DateTimeImmutable $at,
    ): void {
        $date = $this->date($at);
        $this->connection->executeStatement(
            'UPDATE home_ownership_transfers
             SET status = :expired, active_key = NULL, revision = revision + 1, updated_at = :at
             WHERE home_id = :home AND status = :pending AND expires_at <= :at',
            ['expired' => 'expired', 'at' => $date, 'home' => $homeId, 'pending' => 'pending'],
        );
        try {
            $this->connection->insert('home_ownership_transfers', [
                'id' => $id,
                'home_id' => $homeId,
                'proposed_by_user_id' => $proposedByUserId,
                'target_user_id' => $targetUserId,
                'expected_target_revision' => $expectedTargetRevision,
                'status' => 'pending',
                'active_key' => 'active',
                'step_up_verified_at' => $this->date($stepUpVerifiedAt),
                'expires_at' => $this->date($expiresAt),
                'accepted_at' => null,
                'rejected_at' => null,
                'revoked_at' => null,
                'revision' => 1,
                'created_at' => $date,
                'updated_at' => $date,
            ]);
        } catch (UniqueConstraintViolationException) {
            throw new \DomainException('A pending ownership transfer already exists for this home.');
        }
    }

    public function ownershipTransfer(string $homeId, string $transferId): ?array
    {
        return $this->one(
            'SELECT id, home_id AS homeId, proposed_by_user_id AS proposedByUserId,
                    target_user_id AS targetUserId,
                    expected_target_revision AS expectedTargetRevision,
                    status, step_up_verified_at AS stepUpVerifiedAt,
                    expires_at AS expiresAt, accepted_at AS acceptedAt,
                    rejected_at AS rejectedAt, revoked_at AS revokedAt,
                    revision, created_at AS createdAt, updated_at AS updatedAt
             FROM home_ownership_transfers
             WHERE home_id = :home AND id = :id',
            ['home' => $homeId, 'id' => $transferId],
        );
    }

    public function ownershipTransfers(string $homeId, ?string $participantUserId): array
    {
        $where = 'home_id = :home';
        $params = ['home' => $homeId];
        if ($participantUserId !== null) {
            $where .= ' AND (proposed_by_user_id = :participant OR target_user_id = :participant)';
            $params['participant'] = $participantUserId;
        }

        return $this->connection->fetchAllAssociative(
            'SELECT id, home_id AS homeId, proposed_by_user_id AS proposedByUserId,
                    target_user_id AS targetUserId,
                    expected_target_revision AS expectedTargetRevision,
                    status, step_up_verified_at AS stepUpVerifiedAt,
                    expires_at AS expiresAt, accepted_at AS acceptedAt,
                    rejected_at AS rejectedAt, revoked_at AS revokedAt,
                    revision, created_at AS createdAt, updated_at AS updatedAt
             FROM home_ownership_transfers WHERE ' . $where . '
             ORDER BY created_at DESC, id DESC',
            $params,
        );
    }

    public function transitionOwnershipTransfer(
        string $homeId,
        string $transferId,
        int $expectedRevision,
        string $status,
        DateTimeImmutable $at,
    ): bool {
        if (! in_array($status, ['accepted', 'rejected', 'revoked'], true)) {
            throw new \InvalidArgumentException('Unsupported ownership-transfer transition.');
        }
        $timestampColumn = match ($status) {
            'accepted' => 'accepted_at',
            'rejected' => 'rejected_at',
            'revoked' => 'revoked_at',
        };

        return $this->connection->executeStatement(
            'UPDATE home_ownership_transfers
             SET status = :status, active_key = NULL, ' . $timestampColumn . ' = :at,
                 revision = revision + 1, updated_at = :at
             WHERE home_id = :home AND id = :id AND status = :pending
               AND revision = :revision AND expires_at > :at',
            [
                'status' => $status,
                'at' => $this->date($at),
                'home' => $homeId,
                'id' => $transferId,
                'pending' => 'pending',
                'revision' => $expectedRevision,
            ],
        ) === 1;
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
        ?string $homeId,
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

    private function createDefaultPermissionPolicies(
        string $homeId,
        string $ownerUserId,
        DateTimeImmutable $at,
    ): void {
        foreach (
            [
                HomeAuthorization::OWNER,
                HomeAuthorization::MANAGER,
                HomeAuthorization::MEMBER,
                HomeAuthorization::VIEWER,
            ] as $role
        ) {
            $this->connection->insert('home_role_policies', [
                'home_id' => $homeId,
                'role' => $role,
                'revision' => 1,
                'updated_by_user_id' => $ownerUserId,
                'updated_at' => $this->date($at),
            ]);
            foreach (HomePermission::defaultsForRole($role) as $permission) {
                $this->connection->insert('home_role_permission_grants', [
                    'home_id' => $homeId,
                    'role' => $role,
                    'permission' => $permission,
                ]);
            }
        }
    }

    private function date(DateTimeImmutable $date): string
    {
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
