<?php

declare(strict_types=1);

namespace Providentia\Purchasing\Application;

interface PurchaseSummaryReader
{
    /** @return array<string, mixed> */
    public function summary(string $homeId, int $recentDays): array;
}
