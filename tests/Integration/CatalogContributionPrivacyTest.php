<?php

declare(strict_types=1);

namespace ProvidentiaTest\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Providentia\Catalog\Infrastructure\Doctrine\DbalCatalogContributionStore;
use Providentia\Catalog\Infrastructure\Doctrine\DbalCatalogGovernanceStore;
use Providentia\Inventory\Infrastructure\Doctrine\DbalCatalogMergeHomeProductGateway;

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
        $this->insertContribution('approved', 'approved-image', 'product_image');
        $this->insertContribution('approved', 'approved-unknown', 'unsupported');

        $rows = $this->store->published(null, 50, 0);

        self::assertCount(1, $rows);
        self::assertSame('store_price', $rows[0]['contributionType']);
        self::assertSame(['contributionType', 'payload', 'publishedAt'], array_keys($rows[0]));
        $this->assertNoAttribution($rows[0]);
    }

    public function testClientSubmissionIdentifierMakesRetriesIdempotentAndHomeBound(): void
    {
        $payload = ['canonicalName' => 'Rolled oats'];
        $created = $this->store->createContribution(
            'submission-1',
            'home-private',
            'receipt-private',
            'product_identity',
            'source-private',
            $payload,
            'user-private',
            new \DateTimeImmutable('2026-08-04T12:00:00+00:00'),
        );
        $replayed = $this->store->createContribution(
            'submission-1',
            'home-private',
            'receipt-private',
            'product_identity',
            'source-private',
            $payload,
            'user-private',
            new \DateTimeImmutable('2026-08-04T12:05:00+00:00'),
        );
        self::assertSame('created', $created['outcome']);
        self::assertSame('replayed', $replayed['outcome']);
        self::assertSame($created['record'], $replayed['record']);

        $this->connection->insert('catalog_contribution_consents', [
            'home_id' => 'other-home',
            'share_product_identity' => 1,
            'share_product_images' => 1,
            'share_store_prices' => 1,
            'notice_version' => 'catalog-sharing-v1',
            'revision' => 1,
            'updated_by_user_id' => 'other-user',
            'updated_at' => '2026-08-04 12:00:00',
        ]);
        $this->connection->insert('catalog_consent_receipts', [
            'id' => 'other-receipt',
            'home_id' => 'other-home',
            'consent_revision' => 1,
            'share_product_identity' => 1,
            'share_product_images' => 1,
            'share_store_prices' => 1,
            'notice_version' => 'catalog-sharing-v1',
            'recorded_by_user_id' => 'other-user',
            'recorded_at' => '2026-08-04 12:00:00',
        ]);
        $crossHome = $this->store->createContribution(
            'submission-1',
            'other-home',
            'other-receipt',
            'product_identity',
            'source-private',
            $payload,
            'other-user',
            new \DateTimeImmutable('2026-08-04T12:05:00+00:00'),
        );
        self::assertSame('conflict', $crossHome['outcome']);
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

        self::assertSame('withdrawn', $this->contributionStatus('identity-approved'));
        self::assertSame('approved', $this->contributionStatus('image-approved'));
        self::assertSame('withdrawn', $this->contributionStatus('price-approved'));
        self::assertSame(
            [],
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
        self::assertSame('withdrawn', $this->contributionStatus('identity-approved'));
        self::assertSame('withdrawn', $this->contributionStatus('price-approved'));
        self::assertSame(
            [],
            array_column($this->store->published(null, 50, 0), 'contributionType'),
        );
    }

    public function testProposalLinkIsDurableRevisionBoundAndPrivacySafe(): void
    {
        $this->insertContribution('approved', 'identity-approved', 'product_identity');
        $this->connection->insert('categories', [
            'id' => 'category-1',
            'canonical_name' => 'Breakfast',
        ]);
        $this->connection->insert('catalog_proposals', [
            'id' => 'proposal-1',
            'moderation_status' => 'pending',
        ]);

        $this->store->linkContributionProposal(
            'identity-approved',
            2,
            'proposal-1',
            'category-1',
            'curator-1',
            new \DateTimeImmutable('2026-08-24T12:00:00+00:00'),
        );

        $source = $this->store->contributionForProposal('identity-approved');
        self::assertNotNull($source);
        self::assertSame(2, $source['linkedContributionRevision']);
        self::assertSame('proposal-1', $source['proposalId']);
        self::assertSame('pending', $source['proposalStatus']);
        self::assertSame('category-1', $source['publishedCategoryId']);
        self::assertSame('Breakfast', $source['publishedCategoryName']);
        self::assertSame(['canonicalName' => 'Oats'], $source['payload']);
        $queue = $this->store->reviewQueue('approved', 50, 0);
        self::assertCount(1, $queue);
        self::assertSame(2, $queue[0]['linkedContributionRevision']);
        self::assertSame('proposal-1', $queue[0]['proposalId']);
        self::assertSame('pending', $queue[0]['proposalStatus']);
        self::assertSame('category-1', $queue[0]['publishedCategoryId']);
        self::assertSame('Breakfast', $queue[0]['publishedCategoryName']);
        self::assertSame('2026-08-24 12:00:00.000000', $queue[0]['linkedAt']);
        $this->assertNoAttribution($queue[0]);

        $governance = new DbalCatalogGovernanceStore(
            $this->connection,
            new SequenceUuidGenerator(),
            new DbalCatalogMergeHomeProductGateway($this->connection),
        );
        self::assertTrue($governance->proposalSourceEligible('proposal-1'));
        $this->connection->update('catalog_contributions', [
            'moderation_status' => 'withdrawn',
            'revision' => 3,
        ], ['id' => 'identity-approved']);
        self::assertFalse($governance->proposalSourceEligible('proposal-1'));
    }

    public function testApprovedImageQueueRecoversAttributionFreePublicationStateAfterRestart(): void
    {
        $this->insertContribution('approved', 'image-approved', 'product_image');
        $this->connection->insert('products', [
            'id' => 'product-1',
            'canonical_name' => 'Rolled oats',
        ]);
        $this->connection->insert('catalog_contribution_image_publications', [
            'contribution_id' => 'image-approved',
            'contribution_revision' => 2,
            'product_id' => 'product-1',
            'icon_id' => 'icon-1',
            'icon_revision' => 3,
            'public_asset_id' => 'asset-1',
            'published_by_user_id' => 'curator-private',
            'published_at' => '2026-08-24 12:00:00',
        ]);

        $row = $this->store->reviewQueue('approved', 50, 0)[0];

        self::assertSame('product-1', $row['imagePublicationProductId']);
        self::assertSame('Rolled oats', $row['imagePublicationProductName']);
        self::assertSame(3, $row['imagePublicationIconRevision']);
        self::assertArrayNotHasKey('publishedByUserId', $row);
        $this->assertNoAttribution($row);
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
            'CREATE TABLE catalog_proposals (
                id TEXT PRIMARY KEY, moderation_status TEXT NOT NULL
            )',
            'CREATE TABLE categories (
                id TEXT PRIMARY KEY, canonical_name TEXT NOT NULL
            )',
            'CREATE TABLE catalog_contribution_proposals (
                contribution_id TEXT PRIMARY KEY, contribution_revision INTEGER NOT NULL,
                proposal_id TEXT NOT NULL UNIQUE, published_category_id TEXT NOT NULL,
                linked_by_user_id TEXT NULL, linked_at TEXT NOT NULL
            )',
            'CREATE TABLE products (
                id TEXT PRIMARY KEY, canonical_name TEXT NOT NULL
            )',
            'CREATE TABLE catalog_contribution_image_publications (
                contribution_id TEXT PRIMARY KEY, contribution_revision INTEGER NOT NULL,
                product_id TEXT NOT NULL, icon_id TEXT NOT NULL, icon_revision INTEGER NOT NULL,
                public_asset_id TEXT NOT NULL, published_by_user_id TEXT NULL,
                published_at TEXT NOT NULL
            )',
            'CREATE TABLE audit_events (
                id TEXT PRIMARY KEY, home_id TEXT NULL, actor_user_id TEXT NULL,
                action TEXT NOT NULL, target_type TEXT NOT NULL, target_id TEXT NOT NULL,
                details TEXT NOT NULL, occurred_at TEXT NOT NULL
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

    private function contributionStatus(string $id): string
    {
        return (string) $this->connection->fetchOne(
            'SELECT moderation_status FROM catalog_contributions WHERE id = :id',
            ['id' => $id],
        );
    }
}
