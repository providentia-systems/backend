<?php

declare(strict_types=1);

namespace Providentia\Synchronization\Application;

interface SyncDeploymentInformation
{
    /** @return array<string, mixed> */
    public function information(): array;
}
