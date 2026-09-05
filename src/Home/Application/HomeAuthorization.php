<?php

declare(strict_types=1);

namespace Providentia\Home\Application;

use Providentia\Identity\Application\AuthenticatedIdentity;
use Providentia\Access\Application\AccessService;
use Providentia\Access\Domain\FeatureCatalog;
use Providentia\SharedKernel\Application\Problem;

final class HomeAuthorization
{
    public const OWNER = 'owner';
    public const MANAGER = 'manager';
    public const MEMBER = 'member';
    public const VIEWER = 'viewer';

    public function __construct(private readonly HomeStore $homes, private readonly AccessService $access)
    {
    }

    /**
     * @param list<string> $allowedRoles
     * @return array<string, mixed>
     */
    public function requireRole(
        AuthenticatedIdentity $identity,
        string $homeId,
        array $allowedRoles,
    ): array {
        $membership = $this->homes->membership($homeId, $identity->userId);
        if (
            $membership === null
            || (string) $membership['status'] !== 'active'
            || ! in_array((string) $membership['role'], $allowedRoles, true)
        ) {
            throw new Problem(404, 'Not found', 'The requested resource is unavailable.');
        }

        return $membership;
    }

    /** @return array<string, mixed> */
    public function requirePermission(
        AuthenticatedIdentity $identity,
        string $homeId,
        string $permission,
    ): array {
        if (! HomePermission::isKnown($permission)) {
            throw new \LogicException('Unknown home permission: ' . $permission);
        }
        $membership = $this->homes->membership($homeId, $identity->userId);
        if ($membership === null || (string) $membership['status'] !== 'active') {
            throw new Problem(404, 'Not found', 'The requested resource is unavailable.');
        }
        $role = (string) $membership['role'];
        $defaults = $this->access->effective(FeatureCatalog::HOME, $homeId);
        $rolePermissions = $role === self::OWNER ? HomePermission::all() : ($defaults['rolePermissions'][$role] ?? []);
        $decision = in_array($permission, $defaults['delegablePermissions'], true)
            ? $this->homes->permissionDecision($homeId, $role, $permission) : null;
        $inherited = ($decision ?? in_array($permission, $rolePermissions, true)) ? [$permission] : [];
        $allowed = $role === self::OWNER
            ? $this->access->allows(FeatureCatalog::HOME, $homeId, $permission)
            : $this->access->homePermission($homeId, $identity->userId, $permission, $inherited);
        if (! $allowed) {
            throw new Problem(404, 'Not found', 'The requested resource is unavailable.');
        }

        return $membership;
    }

    /** @return list<string> */
    public function effectivePermissions(AuthenticatedIdentity $identity, string $homeId): array
    {
        $this->requireMember($identity, $homeId);
        $permissions = [];
        foreach (HomePermission::all() as $permission) {
            try {
                $this->requirePermission($identity, $homeId, $permission);
                $permissions[] = $permission;
            } catch (Problem $problem) {
                if ($problem->status !== 404) {
                    throw $problem;
                }
            }
        }
        return $permissions;
    }

    /** @return array<string, mixed> */
    public function requireMember(AuthenticatedIdentity $identity, string $homeId): array
    {
        return $this->requireRole($identity, $homeId, [
            self::OWNER,
            self::MANAGER,
            self::MEMBER,
            self::VIEWER,
        ]);
    }
}
