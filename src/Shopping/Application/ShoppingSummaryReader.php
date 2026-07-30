<?php

declare(strict_types=1);

namespace Providentia\Shopping\Application;

interface ShoppingSummaryReader
{
    /** @return array<string, mixed> */
    public function shoppingSummary(string $homeId): array;
}
