<?php

declare(strict_types=1);

namespace Providentia\Catalog\Application;

use DateTimeImmutable;

interface CatalogAuditRecorder
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
