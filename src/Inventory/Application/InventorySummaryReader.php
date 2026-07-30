<?php

declare(strict_types=1);

namespace Providentia\Inventory\Application;

interface InventorySummaryReader
{
    /** @return array<string, mixed> */
    public function inventorySummary(string $homeId): array;
}
