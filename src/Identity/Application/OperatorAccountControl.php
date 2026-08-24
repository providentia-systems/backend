<?php

declare(strict_types=1);

namespace Providentia\Identity\Application;

use DateTimeImmutable;

interface OperatorAccountControl
{
    /** @return 'updated'|'unchanged'|'not-found'|'revision-conflict'|'closed-terminal'|'last-administrator' */
    public function updateOperatorAccountStatus(
        string $auditId,
        string $actorUserId,
        string $userId,
        string $status,
        string $reason,
        int $expectedRevision,
        DateTimeImmutable $at,
    ): string;
}
