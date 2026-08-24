<?php

declare(strict_types=1);

namespace Providentia\Identity\Application;

use DateTimeImmutable;

interface PlatformRoleStore
{
    /** @return array<string, mixed>|null */
    public function verifiedAccountByEmail(string $normalizedEmail): ?array;

    /**
     * @return 'updated'|'unchanged'|'not-found'|'revision-conflict'|'closed-account'|'last-administrator'
     */
    public function changePlatformRole(
        string $auditId,
        ?string $actorUserId,
        string $userId,
        string $role,
        bool $grant,
        int $expectedRevision,
        DateTimeImmutable $at,
    ): string;
}
