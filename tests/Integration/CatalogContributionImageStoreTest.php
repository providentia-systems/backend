<?php

declare(strict_types=1);

namespace ProvidentiaTest\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Providentia\Catalog\Infrastructure\Doctrine\DbalCatalogContributionImageStore;
use Providentia\Catalog\Infrastructure\Security\SodiumCatalogImageCipher;

final class CatalogContributionImageStoreTest extends TestCase
{
    private Connection $connection;
    private DbalCatalogContributionImageStore $store;
    private SodiumCatalogImageCipher $cipher;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        foreach ($this->schema() as $statement) {
            $this->connection->executeStatement($statement);
        }
        $this->store = new DbalCatalogContributionImageStore($this->connection);
        $this->cipher = new SodiumCatalogImageCipher(base64_encode(str_repeat('k', 32)), 1);
    }

    public function testQuarantineAndPublicAssetsAreEncryptedWithoutHouseholdAttribution(): void
    {
        $bytes = 'sanitized-webp-private-bytes';
        $digest = hash('sha256', $bytes);
        $this->insertContribution('contribution-1');
        $encrypted = $this->cipher->encrypt(
            $bytes,
            'catalog-contribution-image:contribution-1:' . $digest,
        );

        $this->store->saveQuarantineImage(
            'contribution-1',
            $digest,
            'image/webp',
            640,
            480,
            strlen($bytes),
            $encrypted,
            new \DateTimeImmutable('2026-08-24T12:00:00+00:00'),
        );

        $storedCiphertext = $this->blob($this->connection->fetchOne(
            'SELECT ciphertext FROM catalog_contribution_images WHERE contribution_id = :id',
            ['id' => 'contribution-1'],
        ));
        self::assertNotSame($bytes, $storedCiphertext);
        self::assertStringNotContainsString($bytes, $storedCiphertext);
        $row = $this->store->quarantineImage('contribution-1');
        self::assertNotNull($row);
        self::assertSame($bytes, $this->cipher->decrypt(
            new \Providentia\Catalog\Application\EncryptedCatalogImage(
                $row['ciphertext'],
                $row['nonce'],
                $row['keyVersion'],
            ),
            'catalog-contribution-image:contribution-1:' . $digest,
        ));

        $asset = $this->store->savePublicAsset(
            'asset-1',
            $digest,
            'image/webp',
            640,
            480,
            strlen($bytes),
            $this->cipher->encrypt($bytes, 'catalog-public-asset:asset-1:' . $digest),
            new \DateTimeImmutable('2026-08-24T12:05:00+00:00'),
        );
        self::assertSame('asset-1', $asset['id']);
        $columns = array_column(
            $this->connection->fetchAllAssociative('PRAGMA table_info(catalog_public_assets)'),
            'name',
        );
        foreach (['home_id', 'user_id', 'contribution_id', 'source_fingerprint'] as $privateColumn) {
            self::assertNotContains($privateColumn, $columns);
        }
    }

    public function testSequentialContributionsCanPublishNewRevisionsOfTheSameIcon(): void
    {
        $this->insertContribution('contribution-1');
        $this->insertContribution('contribution-2');
        $this->connection->insert('products', ['id' => 'product-1', 'canonical_name' => 'Rolled oats']);

        self::assertTrue($this->store->linkPublication(
            'contribution-1',
            2,
            'product-1',
            'icon-1',
            1,
            'asset-1',
            'curator-1',
            new \DateTimeImmutable('2026-08-24T12:00:00+00:00'),
        ));
        self::assertTrue($this->store->linkPublication(
            'contribution-2',
            2,
            'product-1',
            'icon-1',
            2,
            'asset-2',
            'curator-1',
            new \DateTimeImmutable('2026-08-24T12:05:00+00:00'),
        ));
        self::assertFalse($this->store->linkPublication(
            'contribution-2',
            2,
            'product-1',
            'icon-1',
            2,
            'asset-2',
            'curator-1',
            new \DateTimeImmutable('2026-08-24T12:05:00+00:00'),
        ));
        self::assertSame(2, (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM catalog_contribution_image_publications WHERE icon_id = :icon',
            ['icon' => 'icon-1'],
        ));
    }

    /** @return list<string> */
    private function schema(): array
    {
        return [
            'CREATE TABLE catalog_contributions (
                id TEXT PRIMARY KEY, contribution_type TEXT NOT NULL,
                moderation_status TEXT NOT NULL, revision INTEGER NOT NULL,
                payload_json TEXT NOT NULL
            )',
            'CREATE TABLE catalog_contribution_images (
                contribution_id TEXT PRIMARY KEY, asset_digest TEXT NOT NULL,
                media_type TEXT NOT NULL, width INTEGER NOT NULL, height INTEGER NOT NULL,
                byte_size INTEGER NOT NULL, ciphertext BLOB NOT NULL, nonce BLOB NOT NULL,
                key_version INTEGER NOT NULL, created_at TEXT NOT NULL
            )',
            'CREATE TABLE catalog_public_assets (
                id TEXT PRIMARY KEY, asset_digest TEXT NOT NULL UNIQUE,
                media_type TEXT NOT NULL, width INTEGER NOT NULL, height INTEGER NOT NULL,
                byte_size INTEGER NOT NULL, ciphertext BLOB NOT NULL, nonce BLOB NOT NULL,
                key_version INTEGER NOT NULL, created_at TEXT NOT NULL
            )',
            'CREATE TABLE catalog_contribution_image_publications (
                contribution_id TEXT PRIMARY KEY, contribution_revision INTEGER NOT NULL,
                product_id TEXT NOT NULL, icon_id TEXT NOT NULL, icon_revision INTEGER NOT NULL,
                public_asset_id TEXT NOT NULL, published_by_user_id TEXT NULL,
                published_at TEXT NOT NULL, UNIQUE (icon_id, icon_revision)
            )',
            'CREATE TABLE products (id TEXT PRIMARY KEY, canonical_name TEXT NOT NULL)',
            'CREATE TABLE audit_events (
                id TEXT PRIMARY KEY, home_id TEXT NULL, actor_user_id TEXT NULL,
                action TEXT NOT NULL, target_type TEXT NOT NULL, target_id TEXT NOT NULL,
                details TEXT NOT NULL, occurred_at TEXT NOT NULL
            )',
        ];
    }

    private function insertContribution(string $id): void
    {
        $this->connection->insert('catalog_contributions', [
            'id' => $id,
            'contribution_type' => 'product_image',
            'moderation_status' => 'approved',
            'revision' => 2,
            'payload_json' => '{}',
        ]);
    }

    private function blob(mixed $value): string
    {
        if (is_resource($value)) {
            $contents = stream_get_contents($value);

            return is_string($contents) ? $contents : '';
        }

        return is_string($value) ? $value : '';
    }
}
