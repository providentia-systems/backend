<?php

declare(strict_types=1);

namespace Providentia\SharedKernel\Infrastructure\Clock;

use DateTimeImmutable;
use DateTimeZone;
use Providentia\SharedKernel\Application\Clock;

final class SystemClock implements Clock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
