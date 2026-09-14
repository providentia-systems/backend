<?php

declare(strict_types=1);

namespace Providentia\Synchronization\Application;

interface SyncRepresentationNormalizer
{
    /**
     * Normalize a legacy representation without rewriting the append-only log.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function normalize(string $homeId, string $entityType, string $entityId, array $payload): array;
}
