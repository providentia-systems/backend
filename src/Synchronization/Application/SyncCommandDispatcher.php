<?php

declare(strict_types=1);

namespace Providentia\Synchronization\Application;

use Providentia\Identity\Application\AuthenticatedIdentity;

interface SyncCommandDispatcher
{
    /** @return array<string, mixed> */
    public function dispatch(
        AuthenticatedIdentity $identity,
        string $homeId,
        SyncCommand $command,
    ): array;
}
