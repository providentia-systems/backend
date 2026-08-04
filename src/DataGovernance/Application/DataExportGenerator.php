<?php

declare(strict_types=1);

namespace Providentia\DataGovernance\Application;

interface DataExportGenerator
{
    /** @param array<string, mixed> $request */
    public function generate(array $request): string;
}
