<?php

declare(strict_types=1);

namespace Providentia\SharedKernel\Application;

interface UuidGenerator
{
    public function generate(): string;
}
