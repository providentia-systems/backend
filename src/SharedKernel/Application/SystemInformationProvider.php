<?php

declare(strict_types=1);

namespace Providentia\SharedKernel\Application;

interface SystemInformationProvider
{
    /** @return array<string, string> */
    public function information(): array;
}
