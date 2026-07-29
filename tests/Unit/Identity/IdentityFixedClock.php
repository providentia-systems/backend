<?php

declare(strict_types=1);

namespace ProvidentiaTest\Unit\Identity;

use DateTimeImmutable;
use Providentia\SharedKernel\Application\Clock;

final class IdentityFixedClock implements Clock
{
    public function __construct(private readonly DateTimeImmutable $time)
    {
    }

    public function now(): DateTimeImmutable
    {
        return $this->time;
    }
}
