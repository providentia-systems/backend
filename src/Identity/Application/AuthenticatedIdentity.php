<?php

declare(strict_types=1);

namespace Providentia\Identity\Application;

final readonly class AuthenticatedIdentity
{
    /**
     * @param list<string> $platformRoles
     * @param list<string> $administratorPermissions
     */
    public function __construct(
        public string $userId,
        public string $sessionId,
        public string $deviceId,
        public ?string $activeHomeId,
        public array $platformRoles,
        public array $administratorPermissions = [],
    ) {
    }
}
