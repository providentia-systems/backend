<?php

declare(strict_types=1);

namespace Providentia\Catalog\Application;

use DateTimeImmutable;

interface CatalogMaintenanceStore
{
    /** @return list<array<string, mixed>> */
    public function entities(string $type, int $offset): array;

    /** @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    public function saveEntity(
        string $type,
        string $id,
        array $fields,
        string $status,
        int $expectedRevision,
        string $reason,
        string $actorId,
        DateTimeImmutable $at,
    ): array;
}
