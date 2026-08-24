<?php

declare(strict_types=1);

namespace Providentia\Home\Infrastructure\Adapter;

use DateTimeImmutable;
use Providentia\Catalog\Application\CatalogAuditRecorder;
use Providentia\Home\Application\HomeAuditRecorder;

final readonly class CatalogAuditRecorderAdapter implements CatalogAuditRecorder
{
    public function __construct(private HomeAuditRecorder $audit)
    {
    }

    public function recordAudit(
        string $id,
        string $actorUserId,
        string $action,
        string $targetType,
        string $targetId,
        ?string $homeId,
        string $detailsJson,
        DateTimeImmutable $at,
    ): void {
        $this->audit->recordAudit(
            $id,
            $actorUserId,
            $action,
            $targetType,
            $targetId,
            $homeId,
            $detailsJson,
            $at,
        );
    }
}
