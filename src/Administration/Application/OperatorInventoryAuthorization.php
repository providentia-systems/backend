<?php

declare(strict_types=1);

namespace Providentia\Administration\Application;

use Providentia\Access\Application\AccessService;
use Providentia\Home\Application\HomePermission;
use Providentia\Home\Application\HomePermissionAuthorizer;
use Providentia\Identity\Application\AuthenticatedIdentity;
use Providentia\SharedKernel\Application\Problem;

/** Used only by the dedicated operator inventory entry point. */
final class OperatorInventoryAuthorization implements HomePermissionAuthorizer
{
    public function __construct(
        private readonly AccessService $access,
        private readonly OperatorWorkspaceStore $homes,
    ) {
    }

    public function requirePermission(
        AuthenticatedIdentity $identity,
        string $homeId,
        string $permission,
    ): array {
        $feature = match ($permission) {
            HomePermission::INVENTORY_READ,
            HomePermission::PURCHASES_READ,
            HomePermission::SHOPPING_READ => 'homes.read',
            HomePermission::INVENTORY_WRITE,
            HomePermission::PURCHASES_WRITE,
            HomePermission::SHOPPING_WRITE,
            HomePermission::SHOPPING_MANAGE => 'homes.manage',
            default => throw new \LogicException('Unsupported operator inventory permission.'),
        };
        $this->access->requireAdmin($identity, $feature);

        return $this->homes->home($homeId)
            ?? throw new Problem(404, 'Home unavailable', 'The home does not exist.');
    }
}
