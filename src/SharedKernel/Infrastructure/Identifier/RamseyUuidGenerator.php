<?php

declare(strict_types=1);

namespace Providentia\SharedKernel\Infrastructure\Identifier;

use Providentia\SharedKernel\Application\UuidGenerator;
use Ramsey\Uuid\Uuid;

final class RamseyUuidGenerator implements UuidGenerator
{
    public function generate(): string
    {
        return Uuid::uuid7()->toString();
    }
}
