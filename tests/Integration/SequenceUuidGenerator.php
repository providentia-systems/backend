<?php

declare(strict_types=1);

namespace ProvidentiaTest\Integration;

use Providentia\SharedKernel\Application\UuidGenerator;

final class SequenceUuidGenerator implements UuidGenerator
{
    private int $next = 1;

    public function generate(): string
    {
        return sprintf('01912345-6789-7abc-8def-%012d', $this->next++);
    }
}
