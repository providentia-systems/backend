<?php

declare(strict_types=1);

namespace Providentia\Home\Application;

use Providentia\Identity\Application\AuthenticatedIdentity;
use Providentia\SharedKernel\Application\Problem;

final class HomeAuthorization
{
    public const OWNER = 'owner';
    public const MANAGER = 'manager';
    public const MEMBER = 'member';
    public const VIEWER = 'viewer';

    public function __construct(private readonly HomeStore $homes)
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
