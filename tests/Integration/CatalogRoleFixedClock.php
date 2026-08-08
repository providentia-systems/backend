<?php

declare(strict_types=1);

namespace ProvidentiaTest\Integration;

use DateTimeImmutable;
use Providentia\SharedKernel\Application\Clock;

final class CatalogRoleFixedClock implements Clock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-08-08T12:00:00+00:00');
    }
}
