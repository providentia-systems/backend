<?php

declare(strict_types=1);

namespace ProvidentiaTest\Integration;

use Psr\Log\AbstractLogger;
use Stringable;

final class QueryCountLogger extends AbstractLogger
{
    public int $count = 0;

    /** @param array<string, mixed> $context */
    public function log($level, string | Stringable $message, array $context = []): void
    {
        unset($level, $message, $context);
        $this->count++;
    }

    public function reset(): void
    {
        $this->count = 0;
    }
}
