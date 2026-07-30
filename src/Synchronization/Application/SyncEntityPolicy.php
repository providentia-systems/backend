<?php

declare(strict_types=1);

namespace Providentia\Synchronization\Application;

/**
 * Validates the mutable client-owned fields for one synchronized entity type.
 */
interface SyncEntityPolicy
{
    public function entityType(): string;

    /** @param array<string, mixed> $payload */
    public function validatePut(array $payload): void;
}
