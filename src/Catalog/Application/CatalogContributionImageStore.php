<?php

declare(strict_types=1);

namespace Providentia\Catalog\Application;

use DateTimeImmutable;

interface CatalogContributionImageStore
{
    public function saveQuarantineImage(
        string $contributionId,
        string $digest,
        string $mediaType,
        int $width,
        int $height,
        int $byteSize,
        EncryptedCatalogImage $encrypted,
        DateTimeImmutable $at,
    ): void;

    /** @return array<string, mixed>|null */
    public function quarantineImage(string $contributionId): ?array;

    public function deleteQuarantineImage(string $contributionId): void;

    public function deleteWithdrawnImagesForHome(string $homeId): void;

    /** @return array<string, mixed>|null */
    public function imageForPublication(string $contributionId): ?array;

    /** @return array<string, mixed> */
    public function savePublicAsset(
        string $assetId,
        string $digest,
        string $mediaType,
        int $width,
        int $height,
        int $byteSize,
        EncryptedCatalogImage $encrypted,
        DateTimeImmutable $at,
    ): array;

    public function linkPublication(
        string $contributionId,
        int $contributionRevision,
        string $productId,
        string $iconId,
        int $iconRevision,
        string $publicAssetId,
        string $actorUserId,
        DateTimeImmutable $at,
    ): bool;

    /** @return array<string, mixed>|null */
    public function publication(string $contributionId): ?array;

    /** @return array<string, mixed>|null */
    public function publicAsset(string $digest): ?array;
}
