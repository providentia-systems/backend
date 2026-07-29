<?php

declare(strict_types=1);

namespace Providentia\SharedKernel\Application\Health;

interface DatabaseReadinessProbe
{
    /** @return array{status: string, detail?: string} */
    public function check(): array;
}

