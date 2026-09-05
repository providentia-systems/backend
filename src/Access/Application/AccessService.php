<?php

declare(strict_types=1);

namespace Providentia\Access\Application;

use Providentia\Access\Domain\FeatureCatalog;
use Providentia\Identity\Application\AuthenticatedIdentity;
use Providentia\SharedKernel\Application\Problem;
use Providentia\SharedKernel\Application\TransactionManager;
use Providentia\SharedKernel\Application\UuidGenerator;

final class AccessService
{
    public function __construct(
        private readonly AccessStore $store,
        private readonly TransactionManager $transactions,
        private readonly UuidGenerator $ids,
    ) {
    }

    /** @return array<string, mixed> */
    public function effective(string $scope, string $subjectId): array
    {
        $assignment = $this->store->assignment($scope, $subjectId);
        return [
            'scope' => $scope, 'subjectId' => $subjectId,
            'groupId' => $assignment['groupId'] ?? null,
            'groupName' => $assignment['name'] ?? null,
            'revision' => (int) ($assignment['revision'] ?? 0),
            'groupRevision' => (int) ($assignment['groupRevision'] ?? 0),
            'features' => $assignment['features'] ?? [],
            'limits' => $assignment['limits'] ?? [],
            'delegablePermissions' => $assignment['delegablePermissions'] ?? [],
            'rolePermissions' => $assignment['rolePermissions'] ?? [],
        ];
    }

    public function allows(string $scope, string $subjectId, string $feature): bool
    {
        if (! in_array($feature, FeatureCatalog::features($scope), true)) {
            return false;
        }
        return ($this->effective($scope, $subjectId)['features'][$feature] ?? false) === true;
    }

    public function requireAdmin(AuthenticatedIdentity $identity, string $permission): void
    {
        if (! $this->allows(FeatureCatalog::ADMIN, $identity->userId, $permission)) {
            throw new Problem(403, 'Permission required', 'Your administrator group does not permit this action.');
        }
    }

    /** @return list<array<string, mixed>> */
    public function groups(AuthenticatedIdentity $identity, ?string $scope): array
    {
        $this->requireAdmin($identity, 'groups.manage');
        return $this->store->groups($scope);
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function saveGroup(AuthenticatedIdentity $identity, ?string $id, array $input): array
    {
        $this->requireAdmin($identity, 'groups.manage');
        $scope = (string) ($input['scope'] ?? '');
        $name = trim((string) ($input['name'] ?? ''));
        $description = trim((string) ($input['description'] ?? ''));
        if (FeatureCatalog::features($scope) === [] || $name === '' || mb_strlen($name) > 120
            || mb_strlen($description) > 1000) {
            throw new Problem(422, 'Invalid group', 'Provide a supported scope and a name of up to 120 characters.');
        }
        $features = $this->booleanMap($input['features'] ?? [], FeatureCatalog::features($scope));
        $limits = $input['limits'] ?? [];
        if (! is_array($limits)) {
            throw new Problem(422, 'Invalid limits', 'Limits must be a map.');
        }
        foreach ($limits as $key => $value) {
            if (! in_array($key, FeatureCatalog::limits($scope), true) || ! is_int($value) || $value < -1) {
                throw new Problem(422, 'Invalid limit', 'Use a known limit and a nonnegative integer, or -1 for unlimited.');
            }
        }
        $delegable = $input['delegablePermissions'] ?? [];
        if (! is_array($delegable) || ! array_is_list($delegable)) {
            throw new Problem(422, 'Invalid delegation', 'Delegable permissions must be a list.');
        }
        foreach ($delegable as $permission) {
            if ($scope !== FeatureCatalog::HOME || ! is_string($permission)
                || ! in_array($permission, FeatureCatalog::features($scope), true)) {
                throw new Problem(422, 'Invalid delegation', 'Only known home permissions can be delegated.');
            }
        }
        $rolePermissions = $input['rolePermissions'] ?? [];
        if (! is_array($rolePermissions)) {
            throw new Problem(422, 'Invalid role defaults', 'Provide role permission lists.');
        }
        foreach ($rolePermissions as $role => $permissions) {
            if ($scope !== FeatureCatalog::HOME || ! in_array($role, ['manager', 'member', 'viewer'], true) || ! is_array($permissions) || ! array_is_list($permissions)) {
                throw new Problem(422, 'Invalid role defaults', 'Only home manager, member and viewer defaults are supported.');
            }
            foreach ($permissions as $permission) {
                if (! is_string($permission) || ! in_array($permission, FeatureCatalog::features(FeatureCatalog::HOME), true)) {
                    throw new Problem(422, 'Invalid role permission', 'Choose a known home permission.');
                }
            }
        }
        $revision = (int) ($input['expectedRevision'] ?? 0);
        $id ??= $this->ids->generate();
        $previous = $this->store->group($id);
        if (($previous['protected'] ?? false) || ($previous !== null && $previous['scope'] !== $scope)) {
            throw new Problem(409, 'Protected group', 'The system-owner group and group scope cannot be changed.');
        }
        if ($scope === FeatureCatalog::ADMIN) {
            $this->requireAdmin($identity, 'administrators.manage');
            foreach ($features as $permission => $enabled) {
                if ($enabled) {
                    $this->requireAdmin($identity, $permission);
                }
            }
        }
        $group = [
            'id' => $id, 'scope' => $scope, 'name' => $name, 'description' => $description,
            'features' => $features, 'limits' => $limits,
            'delegablePermissions' => array_values(array_unique($delegable)), 'protected' => false,
            'revision' => $revision + 1, 'rolePermissions' => $rolePermissions,
        ];
        $this->transactions->transactional(function () use ($identity, $group, $revision): void {
            if (! $this->store->saveGroup($group, $revision)) {
                throw new Problem(409, 'Revision conflict', 'Reload the group before saving.');
            }
            $this->store->audit($identity->userId, 'group.saved', (string) $group['scope'], (string) $group['id'], $group);
        });
        return $group;
    }

    public function assign(
        AuthenticatedIdentity $identity,
        string $scope,
        string $subjectId,
        string $groupId,
        int $expectedRevision,
    ): void {
        $permission = match ($scope) {
            FeatureCatalog::ACCOUNT => 'accounts.assign', FeatureCatalog::HOME => 'homes.assign',
            FeatureCatalog::ADMIN => 'administrators.manage',
            default => throw new Problem(422, 'Invalid scope', 'The group scope is not supported.'),
        };
        $this->requireAdmin($identity, $permission);
        $group = $this->store->group($groupId);
        $existing = $this->store->assignment($scope, $subjectId);
        if ($group === null || $group['scope'] !== $scope || ($group['protected'] ?? false)
            || ($existing['groupId'] ?? null) === FeatureCatalog::SYSTEM_OWNER) {
            throw new Problem(422, 'Invalid assignment', 'Choose an editable group of the correct scope.');
        }
        if ($scope === FeatureCatalog::ADMIN) {
            foreach ($group['features'] as $feature => $enabled) {
                if ($enabled === true) {
                    $this->requireAdmin($identity, (string) $feature);
                }
            }
        }
        $this->transactions->transactional(function () use ($identity, $scope, $subjectId, $groupId, $expectedRevision): void {
            $this->store->lockSubject($scope, $subjectId);
            if (! $this->store->assign($scope, $subjectId, $groupId, $expectedRevision)) {
                throw new Problem(409, 'Revision conflict', 'Reload the assignment before changing it.');
            }
            $this->store->audit($identity->userId, 'group.assigned', $scope, $subjectId, ['groupId' => $groupId]);
        });
    }

    /** Called only inside the creation transaction; does not replace an existing assignment. */
    public function initialize(string $scope, string $subjectId, string $groupId): void
    {
        $group = $this->store->group($groupId);
        if ($group === null || $group['scope'] !== $scope) {
            throw new Problem(503, 'Default group unavailable', 'The operator must configure a valid default group.');
        }
        if (! $this->store->assign($scope, $subjectId, $groupId, 0)) {
            throw new Problem(409, 'Assignment exists', 'This subject already has a group.');
        }
    }

    public function serialize(string $scope, string $subjectId): void
    {
        $this->store->lockSubject($scope, $subjectId);
    }

    /** Call inside the same transaction as the insertion. Limits never remove existing resources. */
    public function requireCapacity(string $scope, string $subjectId, string $resource, bool $alreadyInserted = false): void
    {
        if (! in_array($resource, FeatureCatalog::limits($scope), true)) {
            throw new \LogicException('Unknown resource limit.');
        }
        $this->store->lockSubject($scope, $subjectId);
        $maximum = (int) ($this->effective($scope, $subjectId)['limits'][$resource] ?? 0);
        if ($maximum !== -1 && $this->store->resourceCount($scope, $subjectId, $resource) >= $maximum + ($alreadyInserted ? 1 : 0)) {
            throw new Problem(409, 'Allowance reached', 'The current group does not allow another ' . $resource . '.');
        }
    }

    /** @return array<string, mixed> */
    public function memberPolicy(string $homeId, string $userId): array
    {
        return $this->store->memberPolicy($homeId, $userId) ?? [
            'homeId' => $homeId, 'userId' => $userId, 'revision' => 0, 'permissions' => [],
        ];
    }

    /** @param list<string> $inherited */
    public function homePermission(string $homeId, string $userId, string $permission, array $inherited): bool
    {
        if (! $this->allows(FeatureCatalog::HOME, $homeId, $permission)) {
            return false;
        }
        $delegable = $this->effective(FeatureCatalog::HOME, $homeId)['delegablePermissions'];
        $override = in_array($permission, $delegable, true) ? ($this->memberPolicy($homeId, $userId)['permissions'][$permission] ?? null) : null;
        return is_bool($override) ? $override : in_array($permission, $inherited, true);
    }

    /** @param array<string, mixed> $permissions */
    public function saveMemberPolicy(
        AuthenticatedIdentity $actor,
        string $homeId,
        string $userId,
        array $permissions,
        int $expectedRevision,
    ): void {
        $allowed = $this->effective(FeatureCatalog::HOME, $homeId)['delegablePermissions'];
        $normalized = $this->booleanMap($permissions, $allowed);
        $this->transactions->transactional(function () use ($actor, $homeId, $userId, $normalized, $expectedRevision): void {
            $this->store->lockSubject(FeatureCatalog::HOME, $homeId);
            if (! $this->store->saveMemberPolicy($homeId, $userId, $normalized, $expectedRevision)) {
                throw new Problem(409, 'Revision conflict', 'Reload the member permissions before saving.');
            }
            $this->store->audit($actor->userId, 'member.permissions.saved', FeatureCatalog::HOME, $homeId, [
                'userId' => $userId, 'permissions' => $normalized,
            ]);
        });
    }

    /** @param mixed $value @param list<string> $known @return array<string, bool> */
    private function booleanMap(mixed $value, array $known): array
    {
        if (! is_array($value)) {
            throw new Problem(422, 'Invalid permissions', 'Permissions must be a map.');
        }
        foreach ($value as $key => $enabled) {
            if (! is_string($key) || ! in_array($key, $known, true) || ! is_bool($enabled)) {
                throw new Problem(422, 'Invalid permission', 'Only permitted boolean feature values can be changed.');
            }
        }
        return $value;
    }
}
