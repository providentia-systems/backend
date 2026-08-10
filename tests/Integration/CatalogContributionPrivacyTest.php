<?php

declare(strict_types=1);

namespace ProvidentiaTest\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Providentia\Catalog\Infrastructure\Doctrine\DbalCatalogContributionStore;

final class CatalogContributionPrivacyTest extends TestCase
{
    private Connection $connection;
    private DbalCatalogContributionStore $store;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        foreach ($this->schema() as $statement) {
            $this->connection->executeStatement($statement);
        }
        $this->connection->insert('catalog_consent_receipts', [
            'id' => 'receipt-private',
            'home_id' => 'home-private',
            'consent_revision' => 4,
            'share_product_identity' => 1,
            'share_product_images' => 1,
            'share_store_prices' => 1,
            'notice_version' => 'catalog-sharing-v1',
            'recorded_by_user_id' => 'user-private',
            'recorded_at' => '2026-08-04 11:00:00',
        ]);
        $this->connection->insert('catalog_contribution_consents', [
            'home_id' => 'home-private',
            'share_product_identity' => 1,
            'share_product_images' => 1,
            'share_store_prices' => 1,
            'notice_version' => 'catalog-sharing-v1',
            'revision' => 4,
            'updated_by_user_id' => 'user-private',
            'updated_at' => '2026-08-04 11:00:00',
        ]);
        $this->store = new DbalCatalogContributionStore($this->connection);
    }

    public function testModeratorQueueNeverSelectsHouseholdOrUserAttribution(): void
    {
        $this->insertContribution('pending', 'pending-identity', 'product_identity');

        $rows = $this->store->reviewQueue('pending', 50, 0);

        self::assertCount(1, $rows);
        self::assertSame(
            [
                'id',
                'contributionType',
                'payload',
                'status',
                'revision',
                'consentNoticeVersion',
                'consentRevision',
                'createdAt',
            ],
            array_keys($rows[0]),
        );
        $this->assertNoAttribution($rows[0]);
    }

    public function testPublishedProjectionContainsOnlyApprovedSupportedFacts(): void
    {
        $this->insertContribution('approved', 'approved-price', 'store_price');
        $this->insertContribution('pending', 'pending-price', 'store_price');
        $this->insertContribution('rejected', 'rejected-price', 'store_price');
        $this->insertContribution('withdrawn', 'withdrawn-price', 'store_price');
        $this->insertContribution('approved', 'approved-unknown', 'unsupported');

        $rows = $this->store->published(null, 50, 0);

        self::assertCount(1, $rows);
        self::assertSame('store_price', $rows[0]['contributionType']);
        self::assertSame(['contributionType', 'payload', 'publishedAt'], array_keys($rows[0]));
        $this->assertNoAttribution($rows[0]);
    }

    public function testPublishedProjectionAppliesTypeAndPaginationBounds(): void
    {
        $this->insertContribution('approved', 'identity-1', 'product_identity', '2026-08-04 12:00:00');
        $this->insertContribution('approved', 'identity-2', 'product_identity', '2026-08-04 12:01:00');
        $this->insertContribution('approved', 'price-1', 'store_price', '2026-08-04 12:02:00');

        $rows = $this->store->published('product_identity', 1, 1);

        self::assertCount(1, $rows);
        self::assertSame('2026-08-04 12:00:00', $rows[0]['publishedAt']);
    }

    public function testDisablingAConsentCategoryUnpublishesOnlyThatCategoriesApprovedFacts(): void
    {
        $this->insertContribution('approved', 'identity-approved', 'product_identity');
        $this->insertContribution('approved', 'image-approved', 'product_image');
        $this->insertContribution('approved', 'price-approved', 'store_price');

        self::assertTrue($this->store->saveConsent(
            'receipt-withdrawal',
            'home-private',
            false,
            true,
            false,
            'catalog-sharing-v1',
            4,
            'user-private',
            new \DateTimeImmutable('2026-08-04T13:00:00+00:00'),
        ));

        self::assertSame('withdrawn', $this->status('identity-approved'));
        self::assertSame('approved', $this->status('image-approved'));
        self::assertSame('withdrawn', $this->status('price-approved'));
        self::assertSame(
            ['product_image'],
            array_column($this->store->published(null, 50, 0), 'contributionType'),
        );

        self::assertTrue($this->store->saveConsent(
            'receipt-reenabled',
            'home-private',
            true,
            true,
            true,
            'catalog-sharing-v1',
            5,
            'user-private',
            new \DateTimeImmutable('2026-08-04T14:00:00+00:00'),
        ));
        self::assertSame('withdrawn', $this->status('identity-approved'));
        self::assertSame('withdrawn', $this->status('price-approved'));
        self::assertSame(
            ['product_image'],
            array_column($this->store->published(null, 50, 0), 'contributionType'),
        );
    }

    /** @return list<string> */
    private function schema(): array
    {
        return [
            'CREATE TABLE catalog_contribution_consents (
                home_id TEXT PRIMARY KEY, share_product_identity INTEGER NOT NULL,
                share_product_images INTEGER NOT NULL, share_store_prices INTEGER NOT NULL,
                notice_version TEXT NOT NULL, revision INTEGER NOT NULL,
                updated_by_user_id TEXT NULL, updated_at TEXT NOT NULL
            )',
            'CREATE TABLE catalog_consent_receipts (
                id TEXT PRIMARY KEY, home_id TEXT NULL, consent_revision INTEGER NOT NULL,
                share_product_identity INTEGER NOT NULL, share_product_images INTEGER NOT NULL,
                share_store_prices INTEGER NOT NULL, notice_version TEXT NOT NULL,
                recorded_by_user_id TEXT NULL, recorded_at TEXT NOT NULL,
                UNIQUE (home_id, consent_revision)
            )',
            'CREATE TABLE catalog_contributions (
                id TEXT PRIMARY KEY, home_id TEXT NULL, consent_receipt_id TEXT NOT NULL,
                contribution_type TEXT NOT NULL, source_fingerprint TEXT NULL,
                payload_json TEXT NOT NULL, moderation_status TEXT NOT NULL,
                revision INTEGER NOT NULL, submitted_by_user_id TEXT NULL,
                reviewed_by_user_id TEXT NULL, review_reason TEXT NULL,
                reviewed_at TEXT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL
            )',
        ];
    }

    private function insertContribution(
        string $status,
        string $id,
        string $type,
        string $reviewedAt = '2026-08-04 12:00:00',
    ): void {
        $this->connection->insert('catalog_contributions', [
            'id' => $id,
            'home_id' => 'home-private',
            'consent_receipt_id' => 'receipt-private',
            'contribution_type' => $type,
            'source_fingerprint' => hash('sha256', 'home-private:source-private'),
            'payload_json' => json_encode(['canonicalName' => 'Oats'], JSON_THROW_ON_ERROR),
            'moderation_status' => $status,
            'revision' => 2,
            'submitted_by_user_id' => 'user-private',
            'reviewed_by_user_id' => 'reviewer-private',
            'review_reason' => 'Safe public fact',
            'reviewed_at' => $status === 'pending' ? null : $reviewedAt,
            'created_at' => '2026-08-04 11:00:00',
            'updated_at' => $reviewedAt,
        ]);
    }

    /** @param array<string, mixed> $row */
    private function assertNoAttribution(array $row): void
    {
        foreach (
            [
                'homeId',
                'home_id',
                'submittedByUserId',
                'submitted_by_user_id',
                'reviewedByUserId',
                'reviewed_by_user_id',
                'recordedByUserId',
                'consentReceiptId',
                'sourceFingerprint',
            ] as $privateKey
        ) {
            self::assertArrayNotHasKey($privateKey, $row);
        }
    }

    private function status(string $id): string
    {
        return (string) $this->connection->fetchOne(
            'SELECT moderation_status FROM catalog_contributions WHERE id = :id',
            ['id' => $id],
        );
    }
}
