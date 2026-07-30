<?php

declare(strict_types=1);

namespace Providentia\Purchasing\Application;

use DateTimeImmutable;

interface PurchaseAnalyticsReader
{
    /** @return list<array<string, mixed>> */
    public function purchaseFacts(
        string $homeId,
        DateTimeImmutable $from,
        DateTimeImmutable $through,
    ): array;
}
