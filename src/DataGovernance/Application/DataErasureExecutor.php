<?php

declare(strict_types=1);

namespace Providentia\DataGovernance\Application;

interface DataErasureExecutor
{
    /** @param array<string, mixed> $request */
    public function erase(array $request): void;
}
