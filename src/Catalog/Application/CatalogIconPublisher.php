<?php

declare(strict_types=1);

namespace Providentia\Catalog\Application;

use DateTimeImmutable;

interface CatalogIconPublisher
{
    /** @return array{id: string, revision: int} */
    public function putIcon(
        string $id,
        string $targetType,
        string $targetId,
        string $assetDigest,
        string $mediaType,
        string $altText,
        int $width,
        int $height,
        int $byteSize,
        string $provenance,
        int $expectedRevision,
        string $actorUserId,
        string $revisionId,
        DateTimeImmutable $at,
    ): array;
}
