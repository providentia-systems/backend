<?php

declare(strict_types=1);

namespace Providentia\Inventory\Application;

use DateTimeImmutable;

interface InventoryMovementGateway
{
    /** @return array<string, mixed> */
    public function recordApprovedInbound(
        string $actorUserId,
        string $homeId,
        string $homeProductId,
        string $quantity,
        string $sourceType,
        string $sourceId,
        string $reason,
        DateTimeImmutable $occurredAt,
    ): array;
}
