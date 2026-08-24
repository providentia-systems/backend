<?php

declare(strict_types=1);

namespace Providentia\Catalog\Infrastructure\Doctrine;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Providentia\Catalog\Application\CatalogContributionImageStore;
use Providentia\Catalog\Application\EncryptedCatalogImage;

final class DbalCatalogContributionImageStore implements CatalogContributionImageStore
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function saveQuarantineImage(
        string $contributionId,
        string $digest,
        string $mediaType,
        int $width,
        int $height,
        int $byteSize,
        EncryptedCatalogImage $encrypted,
        DateTimeImmutable $at,
    ): void {
        $this->connection->insert('catalog_contribution_images', [
            'contribution_id' => $contributionId,
            'asset_digest' => $digest,
            'media_type' => $mediaType,
            'width' => $width,
            'height' => $height,
            'byte_size' => $byteSize,
            'ciphertext' => $encrypted->ciphertext,
            'nonce' => $encrypted->nonce,
            'key_version' => $encrypted->keyVersion,
            'created_at' => $this->date($at),
        ], [
            'ciphertext' => ParameterType::BINARY,
            'nonce' => ParameterType::BINARY,
        ]);
    }

    public function quarantineImage(string $contributionId): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT i.asset_digest AS assetDigest, i.media_type AS mediaType,
                    i.width, i.height, i.byte_size AS byteSize,
                    i.ciphertext, i.nonce, i.key_version AS keyVersion,
                    c.contribution_type AS contributionType,
                    c.moderation_status AS status, c.revision
             FROM catalog_contribution_images i
             INNER JOIN catalog_contributions c ON c.id = i.contribution_id
             WHERE i.contribution_id = :contribution',
            ['contribution' => $contributionId],
        );

        return $row === false ? null : $this->imageRow($row);
    }

    public function deleteQuarantineImage(string $contributionId): void
    {
        $this->connection->delete(
            'catalog_contribution_images',
            ['contribution_id' => $contributionId],
        );
    }

    public function deleteWithdrawnImagesForHome(string $homeId): void
    {
        $this->connection->executeStatement(
            'DELETE FROM catalog_contribution_images
             WHERE contribution_id IN (
                 SELECT id FROM catalog_contributions
                 WHERE home_id = :home AND moderation_status = :withdrawn
             )',
            ['home' => $homeId, 'withdrawn' => 'withdrawn'],
        );
    }

    public function imageForPublication(string $contributionId): ?array
    {
        $sql = 'SELECT c.id, c.contribution_type AS contributionType,
                       c.moderation_status AS status, c.revision,
                       c.payload_json AS payloadJson,
                       i.asset_digest AS assetDigest, i.media_type AS mediaType,
                       i.width, i.height, i.byte_size AS byteSize,
                       i.ciphertext, i.nonce, i.key_version AS keyVersion,
                       publication.contribution_revision AS publishedContributionRevision,
                       publication.product_id AS publishedProductId,
                       publication.icon_id AS publishedIconId,
                       publication.icon_revision AS publishedIconRevision,
                       publication.public_asset_id AS publicAssetId,
                       publication.published_at AS publishedAt
                FROM catalog_contributions c
                LEFT JOIN catalog_contribution_images i ON i.contribution_id = c.id
                LEFT JOIN catalog_contribution_image_publications publication
                  ON publication.contribution_id = c.id
                WHERE c.id = :contribution';
        if (! $this->connection->getDatabasePlatform() instanceof SQLitePlatform) {
            $sql .= ' FOR UPDATE';
        }
        $row = $this->connection->fetchAssociative($sql, ['contribution' => $contributionId]);
        if ($row === false) {
            return null;
        }
        $payload = json_decode((string) $row['payloadJson'], true, 32, JSON_THROW_ON_ERROR);
        $row['payload'] = is_array($payload) && ! array_is_list($payload) ? $payload : [];
        $row['revision'] = (int) $row['revision'];
        unset($row['payloadJson']);
        if ($row['ciphertext'] !== null) {
            $row = $this->imageRow($row);
        }
        if ($row['publishedContributionRevision'] !== null) {
            $row['publishedContributionRevision'] = (int) $row['publishedContributionRevision'];
            $row['publishedIconRevision'] = (int) $row['publishedIconRevision'];
            $row['publishedAt'] = $this->atom((string) $row['publishedAt']);
        }

        return $row;
    }

    public function savePublicAsset(
        string $assetId,
        string $digest,
        string $mediaType,
        int $width,
        int $height,
        int $byteSize,
        EncryptedCatalogImage $encrypted,
        DateTimeImmutable $at,
    ): array {
        try {
            $this->connection->insert('catalog_public_assets', [
                'id' => $assetId,
                'asset_digest' => $digest,
                'media_type' => $mediaType,
                'width' => $width,
                'height' => $height,
                'byte_size' => $byteSize,
                'ciphertext' => $encrypted->ciphertext,
                'nonce' => $encrypted->nonce,
                'key_version' => $encrypted->keyVersion,
                'created_at' => $this->date($at),
            ], [
                'ciphertext' => ParameterType::BINARY,
                'nonce' => ParameterType::BINARY,
            ]);

            return ['id' => $assetId, 'assetDigest' => $digest];
        } catch (UniqueConstraintViolationException) {
            $existing = $this->connection->fetchAssociative(
                'SELECT id, asset_digest AS assetDigest, media_type AS mediaType,
                        width, height, byte_size AS byteSize
                 FROM catalog_public_assets WHERE asset_digest = :digest',
                ['digest' => $digest],
            );
            if (
                $existing === false
                || (string) $existing['mediaType'] !== $mediaType
                || (int) $existing['width'] !== $width
                || (int) $existing['height'] !== $height
                || (int) $existing['byteSize'] !== $byteSize
            ) {
                throw new \RuntimeException('A catalog public-asset digest collision was detected.');
            }

            return ['id' => (string) $existing['id'], 'assetDigest' => (string) $existing['assetDigest']];
        }
    }

    public function linkPublication(
        string $contributionId,
        int $contributionRevision,
        string $productId,
        string $iconId,
        int $iconRevision,
        string $publicAssetId,
        string $actorUserId,
        DateTimeImmutable $at,
    ): bool {
        $date = $this->date($at);
        try {
            $this->connection->insert('catalog_contribution_image_publications', [
                'contribution_id' => $contributionId,
                'contribution_revision' => $contributionRevision,
                'product_id' => $productId,
                'icon_id' => $iconId,
                'icon_revision' => $iconRevision,
                'public_asset_id' => $publicAssetId,
                'published_by_user_id' => $actorUserId,
                'published_at' => $date,
            ]);
        } catch (UniqueConstraintViolationException) {
            return false;
        }
        return true;
    }

    public function publication(string $contributionId): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT publication.contribution_id AS contributionId,
                    publication.contribution_revision AS contributionRevision,
                    publication.product_id AS productId,
                    product.canonical_name AS productName,
                    publication.icon_id AS iconId,
                    publication.icon_revision AS iconRevision,
                    publication.published_at AS publishedAt
             FROM catalog_contribution_image_publications publication
             INNER JOIN products product ON product.id = publication.product_id
             WHERE publication.contribution_id = :contribution',
            ['contribution' => $contributionId],
        );
        if ($row === false) {
            return null;
        }
        $row['contributionRevision'] = (int) $row['contributionRevision'];
        $row['iconRevision'] = (int) $row['iconRevision'];
        $row['publishedAt'] = $this->atom((string) $row['publishedAt']);

        return $row;
    }

    public function publicAsset(string $digest): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT asset.id, asset.asset_digest AS assetDigest,
                    asset.media_type AS mediaType, asset.width, asset.height,
                    asset.byte_size AS byteSize, asset.ciphertext, asset.nonce,
                    asset.key_version AS keyVersion
             FROM catalog_public_assets asset
             WHERE asset.asset_digest = :digest',
            ['digest' => $digest],
        );

        if ($row === false) {
            return null;
        }
        $row['assetRevision'] = 1;

        return $this->imageRow($row);
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function imageRow(array $row): array
    {
        $row['ciphertext'] = $this->blob($row['ciphertext']);
        $row['nonce'] = $this->blob($row['nonce']);
        $row['keyVersion'] = (int) $row['keyVersion'];
        $row['width'] = (int) $row['width'];
        $row['height'] = (int) $row['height'];
        $row['byteSize'] = (int) $row['byteSize'];
        if (isset($row['revision'])) {
            $row['revision'] = (int) $row['revision'];
        }
        if (isset($row['iconRevision'])) {
            $row['iconRevision'] = (int) $row['iconRevision'];
        }

        return $row;
    }

    private function blob(mixed $value): string
    {
        if (is_resource($value)) {
            $contents = stream_get_contents($value);

            return is_string($contents) ? $contents : '';
        }

        return is_string($value) ? $value : '';
    }

    private function date(DateTimeImmutable $date): string
    {
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    private function atom(string $date): string
    {
        return (new DateTimeImmutable($date, new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('UTC'))
            ->format(DATE_ATOM);
    }
}
