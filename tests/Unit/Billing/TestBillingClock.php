<?php

declare(strict_types=1);

namespace ProvidentiaTest\Unit\Billing;

use DateTimeImmutable;
use Providentia\SharedKernel\Application\Clock;

final readonly class TestBillingClock implements Clock
{
    public function __construct(private DateTimeImmutable $now)
    {
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }
}
