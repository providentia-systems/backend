<?php

declare(strict_types=1);

namespace Providentia\Inventory\Application;

interface InventoryAnalyticsReader
{
    /** @return list<array<string, mixed>> */
    public function inventoryReport(string $homeId): array;
}
