<?php

declare(strict_types=1);

namespace Providentia\SharedKernel\Application;

interface SecureTokenGenerator
{
    public function generate(int $bytes = 32): string;
}
