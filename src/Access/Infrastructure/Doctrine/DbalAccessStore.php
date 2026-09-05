<?php

declare(strict_types=1);

namespace Providentia\Access\Infrastructure\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Providentia\Access\Application\AccessStore;
use Providentia\Access\Domain\FeatureCatalog;
use Providentia\SharedKernel\Application\Clock;
use Providentia\SharedKernel\Application\Problem;
use Providentia\SharedKernel\Application\UuidGenerator;

final class DbalAccessStore implements AccessStore
{
    public function __construct(
        private readonly Connection $connection,
        private readonly Clock $clock,
        private readonly UuidGenerator $ids,
    ) {
    }

    public function groups(?string $scope = null): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT * FROM access_groups' . ($scope === null ? '' : ' WHERE scope = :scope') . ' ORDER BY scope, name',
            $scope === null ? [] : ['scope' => $scope],
        );
        return array_map($this->decodeGroup(...), $rows);
    }

    public function group(string $id): ?array
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM access_groups WHERE id = :id', ['id' => $id]);
        return $row === false ? null : $this->decodeGroup($row);
    }

    public function saveGroup(array $group, int $expectedRevision): bool
    {
        $values = [
            'name' => $group['name'], 'description' => $group['description'],
            'features_json' => json_encode($group['features'], JSON_THROW_ON_ERROR),
            'limits_json' => json_encode($group['limits'], JSON_THROW_ON_ERROR),
            'delegable_json' => json_encode($group['delegablePermissions'], JSON_THROW_ON_ERROR),
            'role_permissions_json' => json_encode($group['rolePermissions'], JSON_THROW_ON_ERROR),
            'revision' => $expectedRevision + 1, 'updated_at' => $this->date(),
        ];
        if ($expectedRevision === 0) {
            try {
                $this->connection->insert('access_groups', [
                    ...$values, 'id' => $group['id'], 'scope' => $group['scope'], 'protected' => 0,
                ]);
                return true;
            } catch (UniqueConstraintViolationException) {
                return false;
            }
        }
        return $this->connection->update('access_groups', $values, [
            'id' => $group['id'], 'revision' => $expectedRevision, 'protected' => 0,
        ]) === 1;
    }

    public function assignment(string $scope, string $subjectId): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT g.*, a.group_id, a.revision AS assignment_revision
             FROM access_assignments a INNER JOIN access_groups g ON g.id = a.group_id
             WHERE a.scope = :scope AND a.subject_id = :subject AND g.scope = :scope
               AND (:scope <> :admin_scope OR EXISTS (SELECT 1 FROM administrator_requests r WHERE r.user_id = a.subject_id AND r.status = :approved)
                    OR EXISTS (SELECT 1 FROM system_owner_bootstrap b WHERE b.user_id = a.subject_id AND a.group_id = :owner_group))',
            ['scope' => $scope, 'subject' => $subjectId, 'admin_scope' => FeatureCatalog::ADMIN, 'approved' => 'approved', 'owner_group' => FeatureCatalog::SYSTEM_OWNER],
        );
        if ($row === false) {
            return null;
        }
        return [
            ...$this->decodeGroup($row), 'groupId' => $row['group_id'],
            'groupRevision' => (int) $row['revision'], 'revision' => (int) $row['assignment_revision'],
        ];
    }

    public function assign(string $scope, string $subjectId, string $groupId, int $expectedRevision): bool
    {
        if ($expectedRevision === 0) {
            try {
                $this->connection->insert('access_assignments', [
                    'scope' => $scope, 'subject_id' => $subjectId, 'group_id' => $groupId,
                    'revision' => 1, 'updated_at' => $this->date(),
                ]);
                return true;
            } catch (UniqueConstraintViolationException) {
                return false;
            }
        }
        return $this->connection->update('access_assignments', [
            'group_id' => $groupId, 'revision' => $expectedRevision + 1, 'updated_at' => $this->date(),
        ], ['scope' => $scope, 'subject_id' => $subjectId, 'revision' => $expectedRevision]) === 1;
    }

    public function lockSubject(string $scope, string $subjectId): void
    {
        if (! $this->connection->isTransactionActive()) {
            throw new \LogicException('Access capacity must be checked within a transaction.');
        }
        $table = $scope === FeatureCatalog::HOME ? 'homes' : 'users';
        // A harmless write serializes across SQLite/MySQL/MariaDB, including their snapshot reads.
        $this->connection->executeStatement('UPDATE ' . $table . ' SET id = id WHERE id = :id', ['id' => $subjectId]);
        if (! $this->connection->fetchOne('SELECT id FROM ' . $table . ' WHERE id = :id', ['id' => $subjectId])) {
            throw new Problem(404, 'Not found', 'The requested account or home is unavailable.');
        }
    }

    public function resourceCount(string $scope, string $subjectId, string $resource): int
    {
        $params = ['id' => $subjectId];
        $sql = match ($resource) {
            'homes.owned' => "SELECT COUNT(*) FROM home_memberships m INNER JOIN homes h ON h.id = m.home_id
                WHERE m.user_id = :id AND m.role = 'owner' AND m.status = 'active' AND h.status = 'active'",
            'homes.joined' => "SELECT COUNT(*) FROM home_memberships m INNER JOIN homes h ON h.id = m.home_id
                WHERE m.user_id = :id AND m.role <> 'owner' AND m.status = 'active' AND h.status = 'active'",
            'members.total' => "SELECT COUNT(*) FROM home_memberships WHERE home_id = :id AND status = 'active'",
            'members.owners', 'members.managers', 'members.members' =>
                "SELECT COUNT(*) FROM home_memberships WHERE home_id = :id AND status = 'active' AND role = :role",
            'categories.total' => "SELECT COUNT(*) FROM home_categories WHERE home_id = :id AND status = 'active'",
            'products.total' => "SELECT COUNT(*) FROM home_products WHERE home_id = :id AND status = 'active'",
            'locations.total' => "SELECT COUNT(*) FROM home_locations WHERE home_id = :id AND status = 'active'",
            default => throw new \LogicException('Unsupported access resource.'),
        };
        if (in_array($resource, ['members.owners', 'members.managers', 'members.members'], true)) {
            $params['role'] = match ($resource) {
                'members.owners' => 'owner', 'members.managers' => 'manager', default => 'member',
            };
        }
        if (! $this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\SQLitePlatform) {
            $sql .= ' FOR UPDATE';
        }
        return (int) $this->connection->fetchOne($sql, $params);
    }

    public function memberPolicy(string $homeId, string $userId): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM member_permission_overrides WHERE home_id = :home AND user_id = :user',
            ['home' => $homeId, 'user' => $userId],
        );
        if ($row === false) {
            return null;
        }
        return [
            'homeId' => $homeId, 'userId' => $userId, 'revision' => (int) $row['revision'],
            'permissions' => json_decode((string) $row['permissions_json'], true, 32, JSON_THROW_ON_ERROR),
        ];
    }

    public function saveMemberPolicy(string $homeId, string $userId, array $permissions, int $expectedRevision): bool
    {
        $values = [
            'permissions_json' => json_encode($permissions, JSON_THROW_ON_ERROR),
            'revision' => $expectedRevision + 1, 'updated_at' => $this->date(),
        ];
        if ($expectedRevision === 0) {
            try {
                $this->connection->insert('member_permission_overrides', [
                    ...$values, 'home_id' => $homeId, 'user_id' => $userId,
                ]);
                return true;
            } catch (UniqueConstraintViolationException) {
                return false;
            }
        }
        return $this->connection->update('member_permission_overrides', $values, [
            'home_id' => $homeId, 'user_id' => $userId, 'revision' => $expectedRevision,
        ]) === 1;
    }

    public function audit(?string $actorId, string $action, string $scope, string $subjectId, array $details): void
    {
        $this->connection->insert('platform_audit_events', [
            'id' => $this->ids->generate(), 'actor_user_id' => $actorId, 'action' => $action,
            'scope' => $scope, 'subject_id' => $subjectId,
            'details_json' => json_encode($details, JSON_THROW_ON_ERROR), 'created_at' => $this->date(),
        ]);
    }

    public function auditEvents(int $limit, int $offset): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT id, actor_user_id AS actorUserId, action, scope, subject_id AS subjectId,
                    details_json AS details, created_at AS createdAt
             FROM platform_audit_events ORDER BY created_at DESC, id DESC LIMIT ' . max(1, min(100, $limit))
             . ' OFFSET ' . max(0, $offset),
        );
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function decodeGroup(array $row): array
    {
        return [
            'id' => $row['id'], 'scope' => $row['scope'], 'name' => $row['name'],
            'description' => $row['description'], 'revision' => (int) $row['revision'],
            'protected' => (bool) $row['protected'],
            'rolePermissions' => json_decode((string) $row['role_permissions_json'], true, 32, JSON_THROW_ON_ERROR),
            'features' => json_decode((string) $row['features_json'], true, 32, JSON_THROW_ON_ERROR),
            'limits' => json_decode((string) $row['limits_json'], true, 32, JSON_THROW_ON_ERROR),
            'delegablePermissions' => json_decode((string) $row['delegable_json'], true, 32, JSON_THROW_ON_ERROR),
        ];
    }

    private function date(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s.u');
    }
}
