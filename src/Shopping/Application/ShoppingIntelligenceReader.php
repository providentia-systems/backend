<?php

declare(strict_types=1);

namespace Providentia\Shopping\Application;

use DateTimeImmutable;

interface ShoppingIntelligenceReader
{
    /** @return list<array<string, mixed>> */
    public function latestEstimates(string $homeId): array;

    /** @return list<array<string, mixed>> */
    public function latestSuggestions(string $homeId, DateTimeImmutable $asOf): array;

    /** @return list<array<string, mixed>> */
    public function latestPriceComparisons(string $homeId): array;
}
