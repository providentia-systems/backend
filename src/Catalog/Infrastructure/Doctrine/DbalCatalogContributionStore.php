<?php

declare(strict_types=1);

namespace Providentia\Catalog\Infrastructure\Doctrine;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Providentia\Catalog\Application\CatalogContributionStore;

final class DbalCatalogContributionStore implements CatalogContributionStore
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function consent(string $homeId): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT c.home_id AS homeId,
                    c.share_product_identity AS shareProductIdentity,
                    c.share_product_images AS shareProductImages,
                    c.share_store_prices AS shareStorePrices,
                    c.notice_version AS noticeVersion, c.revision,
                    r.id AS receiptId, c.updated_at AS updatedAt
             FROM catalog_contribution_consents c
             INNER JOIN catalog_consent_receipts r
               ON r.home_id = c.home_id AND r.consent_revision = c.revision
             WHERE c.home_id = :home',
            ['home' => $homeId],
        );

        return $row === false ? null : $row;
    }

    public function saveConsent(
        string $receiptId,
        string $homeId,
        bool $shareProductIdentity,
        bool $shareProductImages,
        bool $shareStorePrices,
        string $noticeVersion,
        int $expectedRevision,
        string $actorUserId,
        DateTimeImmutable $at,
    ): bool {
        $date = $this->date($at);
        $revision = $expectedRevision + 1;
        if ($expectedRevision === 0) {
            try {
                $this->connection->insert('catalog_contribution_consents', [
                    'home_id' => $homeId,
                    'share_product_identity' => $shareProductIdentity ? 1 : 0,
                    'share_product_images' => $shareProductImages ? 1 : 0,
                    'share_store_prices' => $shareStorePrices ? 1 : 0,
                    'notice_version' => $noticeVersion,
                    'revision' => 1,
                    'updated_by_user_id' => $actorUserId,
                    'updated_at' => $date,
                ]);
            } catch (UniqueConstraintViolationException) {
                return false;
            }
        } else {
            $updated = $this->connection->executeStatement(
                'UPDATE catalog_contribution_consents
                 SET share_product_identity = :identity, share_product_images = :images,
                     share_store_prices = :prices, notice_version = :notice,
                     revision = revision + 1, updated_by_user_id = :actor, updated_at = :at
                 WHERE home_id = :home AND revision = :revision',
                [
                    'identity' => $shareProductIdentity ? 1 : 0,
                    'images' => $shareProductImages ? 1 : 0,
                    'prices' => $shareStorePrices ? 1 : 0,
                    'notice' => $noticeVersion,
                    'actor' => $actorUserId,
                    'at' => $date,
                    'home' => $homeId,
                    'revision' => $expectedRevision,
                ],
            );
            if ($updated !== 1) {
                return false;
            }
        }
        $this->connection->insert('catalog_consent_receipts', [
            'id' => $receiptId,
            'home_id' => $homeId,
            'consent_revision' => $revision,
            'share_product_identity' => $shareProductIdentity ? 1 : 0,
            'share_product_images' => $shareProductImages ? 1 : 0,
            'share_store_prices' => $shareStorePrices ? 1 : 0,
            'notice_version' => $noticeVersion,
            'recorded_by_user_id' => $actorUserId,
            'recorded_at' => $date,
        ]);
        $this->connection->executeStatement(
            'UPDATE catalog_contributions
             SET moderation_status = :withdrawn, revision = revision + 1, updated_at = :at
             WHERE home_id = :home AND moderation_status = :pending
               AND (
                   (contribution_type = :identity_type AND :identity_enabled = 0)
                   OR (contribution_type = :image_type AND :images_enabled = 0)
                   OR (contribution_type = :price_type AND :prices_enabled = 0)
               )',
            [
                'withdrawn' => 'withdrawn',
                'at' => $date,
                'home' => $homeId,
                'pending' => 'pending',
                'identity_type' => 'product_identity',
                'identity_enabled' => $shareProductIdentity ? 1 : 0,
                'image_type' => 'product_image',
                'images_enabled' => $shareProductImages ? 1 : 0,
                'price_type' => 'store_price',
                'prices_enabled' => $shareStorePrices ? 1 : 0,
            ],
        );

        return true;
    }

    public function createContribution(
        string $id,
        string $homeId,
        string $consentReceiptId,
        string $type,
        ?string $sourceEntityId,
        array $payload,
        string $actorUserId,
        DateTimeImmutable $at,
    ): bool {
        $date = $this->date($at);
        return $this->connection->executeStatement(
            'INSERT INTO catalog_contributions
                (id, home_id, consent_receipt_id, contribution_type, source_fingerprint,
                 payload_json, moderation_status, revision, submitted_by_user_id,
                 reviewed_by_user_id, review_reason, reviewed_at, created_at, updated_at)
             SELECT :id, :home, :receipt, :type, :source, :payload, :pending, 1, :actor,
                    NULL, NULL, NULL, :at, :at
             FROM catalog_contribution_consents c
             INNER JOIN catalog_consent_receipts r
               ON r.id = :receipt AND r.home_id = c.home_id AND r.consent_revision = c.revision
             WHERE c.home_id = :home
               AND (
                   (:type = :identity_type AND c.share_product_identity = 1)
                   OR (:type = :image_type AND c.share_product_images = 1)
                   OR (:type = :price_type AND c.share_store_prices = 1)
               )',
            [
                'id' => $id,
                'home' => $homeId,
                'receipt' => $consentReceiptId,
                'type' => $type,
                'source' => $sourceEntityId === null ? null : hash('sha256', $homeId . ':' . $sourceEntityId),
                'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
                'pending' => 'pending',
                'actor' => $actorUserId,
                'at' => $date,
                'identity_type' => 'product_identity',
                'image_type' => 'product_image',
                'price_type' => 'store_price',
            ],
        ) === 1;
    }

    public function contributionsForHome(string $homeId, int $limit, int $offset): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT id, contribution_type AS contributionType, payload_json AS payload,
                    moderation_status AS status, revision, review_reason AS reviewReason,
                    reviewed_at AS reviewedAt, created_at AS createdAt, updated_at AS updatedAt
             FROM catalog_contributions
             WHERE home_id = :home
            ORDER BY created_at DESC, id DESC LIMIT :limit OFFSET :offset',
            ['home' => $homeId, 'limit' => $limit, 'offset' => $offset],
            ['limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER],
        );
    }

    public function reviewQueue(string $status, int $limit, int $offset): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT c.id, c.contribution_type AS contributionType, c.payload_json AS payload,
                    c.moderation_status AS status, c.revision,
                    r.notice_version AS consentNoticeVersion,
                    r.consent_revision AS consentRevision, c.created_at AS createdAt
             FROM catalog_contributions c
             INNER JOIN catalog_consent_receipts r ON r.id = c.consent_receipt_id
             WHERE c.moderation_status = :status
            ORDER BY c.created_at, c.id LIMIT :limit OFFSET :offset',
            ['status' => $status, 'limit' => $limit, 'offset' => $offset],
            ['limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER],
        );
    }

    public function contribution(string $id): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT id, contribution_type AS contributionType,
                    moderation_status AS status, revision
             FROM catalog_contributions WHERE id = :id',
            ['id' => $id],
        );

        return $row === false ? null : $row;
    }

    public function decide(
        string $id,
        string $decision,
        string $reason,
        int $expectedRevision,
        string $reviewerUserId,
        DateTimeImmutable $at,
    ): bool {
        return $this->connection->executeStatement(
            'UPDATE catalog_contributions
             SET moderation_status = :decision, review_reason = :reason,
                 reviewed_by_user_id = :reviewer, reviewed_at = :at,
                 revision = revision + 1, updated_at = :at
             WHERE id = :id AND moderation_status = :pending AND revision = :revision',
            [
                'decision' => $decision,
                'reason' => $reason,
                'reviewer' => $reviewerUserId,
                'at' => $this->date($at),
                'id' => $id,
                'pending' => 'pending',
                'revision' => $expectedRevision,
            ],
        ) === 1;
    }

    private function date(DateTimeImmutable $date): string
    {
        return $date->format('Y-m-d H:i:s.u');
    }
}
