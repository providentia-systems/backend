<?php

declare(strict_types=1);

namespace Providentia\Home\Application;

use DateTimeImmutable;

interface HomeAuditRecorder
{
    public function recordAudit(
        string $id,
        string $actorUserId,
        string $action,
        string $targetType,
        string $targetId,
        ?string $homeId,
        string $detailsJson,
        DateTimeImmutable $at,
    ): void;
}
