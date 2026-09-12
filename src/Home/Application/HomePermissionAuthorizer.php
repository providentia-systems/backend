<?php

declare(strict_types=1);

namespace Providentia\Home\Application;

use Providentia\Identity\Application\AuthenticatedIdentity;

/** The authorization policy is selected by the application entry point. */
interface HomePermissionAuthorizer
{
    /** @return array<string, mixed> Authorized scope; not necessarily a membership. */
    public function requirePermission(
        AuthenticatedIdentity $identity,
        string $homeId,
        string $permission,
    ): array;
}
