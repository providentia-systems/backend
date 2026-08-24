<?php

declare(strict_types=1);

namespace Providentia\Catalog\Infrastructure\Doctrine;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\SQLitePlatform;
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
        $disabledTypes = [];
        if (! $shareProductIdentity) {
            $disabledTypes[] = 'product_identity';
        }
        if (! $shareProductImages) {
            $disabledTypes[] = 'product_image';
        }
        if (! $shareStorePrices) {
            $disabledTypes[] = 'store_price';
        }
        foreach ($disabledTypes as $disabledType) {
            $this->connection->executeStatement(
                'UPDATE catalog_contributions
                 SET moderation_status = :withdrawn, revision = revision + 1, updated_at = :at
                 WHERE home_id = :home AND moderation_status IN (:pending, :approved)
                   AND contribution_type = :type',
                [
                    'withdrawn' => 'withdrawn',
                    'at' => $date,
                    'home' => $homeId,
                    'pending' => 'pending',
                    'approved' => 'approved',
                    'type' => $disabledType,
                ],
            );
        }

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
    ): array {
        $date = $this->date($at);
        $sourceFingerprint = $sourceEntityId === null
            ? null
            : hash('sha256', $homeId . ':' . $sourceEntityId);
        $payloadJson = json_encode($payload, JSON_THROW_ON_ERROR);
        try {
            $inserted = $this->connection->executeStatement(
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
                    'source' => $sourceFingerprint,
                    'payload' => $payloadJson,
                    'pending' => 'pending',
                    'actor' => $actorUserId,
                    'at' => $date,
                    'identity_type' => 'product_identity',
                    'image_type' => 'product_image',
                    'price_type' => 'store_price',
                ],
            );
        } catch (UniqueConstraintViolationException) {
            return $this->submissionReplay(
                $id,
                $homeId,
                $consentReceiptId,
                $type,
                $sourceFingerprint,
                $payload,
            );
        }
        if ($inserted !== 1) {
            return ['outcome' => 'conflict'];
        }

        return [
            'outcome' => 'created',
            'record' => [
                'id' => $id,
                'contributionType' => $type,
                'payload' => $payload,
                'status' => 'pending',
                'revision' => 1,
                'createdAt' => $at->format(DATE_ATOM),
            ],
        ];
    }

    public function contributionsForHome(string $homeId, int $limit, int $offset): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, contribution_type AS contributionType, payload_json AS payload,
                    moderation_status AS status, revision, review_reason AS reviewReason,
                    reviewed_at AS reviewedAt, created_at AS createdAt, updated_at AS updatedAt
             FROM catalog_contributions
             WHERE home_id = :home
            ORDER BY created_at DESC, id DESC LIMIT :limit OFFSET :offset',
            ['home' => $homeId, 'limit' => $limit, 'offset' => $offset],
            ['limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER],
        );
        foreach ($rows as &$row) {
            $payload = json_decode((string) $row['payload'], true, 32, JSON_THROW_ON_ERROR);
            $row['payload'] = is_array($payload) && ! array_is_list($payload) ? $payload : [];
            $row['revision'] = (int) $row['revision'];
            $row['createdAt'] = $this->atom((string) $row['createdAt']);
            $row['updatedAt'] = $this->atom((string) $row['updatedAt']);
            if ($row['reviewedAt'] === null) {
                unset($row['reviewedAt']);
            } else {
                $row['reviewedAt'] = $this->atom((string) $row['reviewedAt']);
            }
            if ($row['reviewReason'] === null) {
                unset($row['reviewReason']);
            }
        }
        unset($row);

        return $rows;
    }

    public function reviewQueue(string $status, int $limit, int $offset): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT c.id, c.contribution_type AS contributionType, c.payload_json AS payload,
                    c.moderation_status AS status, c.revision,
                    r.notice_version AS consentNoticeVersion,
                    r.consent_revision AS consentRevision, c.created_at AS createdAt,
                    l.contribution_revision AS linkedContributionRevision,
                    l.proposal_id AS proposalId,
                    p.moderation_status AS proposalStatus,
                    l.published_category_id AS publishedCategoryId,
                    category.canonical_name AS publishedCategoryName,
                    l.linked_at AS linkedAt,
                    image_publication.contribution_revision AS imagePublicationContributionRevision,
                    image_publication.product_id AS imagePublicationProductId,
                    image_product.canonical_name AS imagePublicationProductName,
                    image_publication.icon_id AS imagePublicationIconId,
                    image_publication.icon_revision AS imagePublicationIconRevision,
                    image_publication.published_at AS imagePublishedAt
             FROM catalog_contributions c
             INNER JOIN catalog_consent_receipts r ON r.id = c.consent_receipt_id
             LEFT JOIN catalog_contribution_proposals l ON l.contribution_id = c.id
             LEFT JOIN catalog_proposals p ON p.id = l.proposal_id
             LEFT JOIN categories category ON category.id = l.published_category_id
             LEFT JOIN catalog_contribution_image_publications image_publication
               ON image_publication.contribution_id = c.id
             LEFT JOIN products image_product ON image_product.id = image_publication.product_id
             WHERE c.moderation_status = :status
            ORDER BY c.created_at, c.id LIMIT :limit OFFSET :offset',
            ['status' => $status, 'limit' => $limit, 'offset' => $offset],
            ['limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER],
        );
        foreach ($rows as &$row) {
            if ($row['proposalId'] === null) {
                unset(
                    $row['linkedContributionRevision'],
                    $row['proposalId'],
                    $row['proposalStatus'],
                    $row['publishedCategoryId'],
                    $row['publishedCategoryName'],
                    $row['linkedAt'],
                );
            } elseif (isset($row['linkedContributionRevision'])) {
                $row['linkedContributionRevision'] = (int) $row['linkedContributionRevision'];
            }
            if ($row['imagePublicationProductId'] === null) {
                unset(
                    $row['imagePublicationContributionRevision'],
                    $row['imagePublicationProductId'],
                    $row['imagePublicationProductName'],
                    $row['imagePublicationIconId'],
                    $row['imagePublicationIconRevision'],
                    $row['imagePublishedAt'],
                );
            } else {
                $row['imagePublicationContributionRevision'] = (int) $row['imagePublicationContributionRevision'];
                $row['imagePublicationIconRevision'] = (int) $row['imagePublicationIconRevision'];
            }
        }
        unset($row);

        return $rows;
    }

    public function published(?string $type, int $limit, int $offset): array
    {
        $typeFilter = $type === null ? '' : ' AND contribution_type = :type';
        $parameters = [
            'approved' => 'approved',
            'identity' => 'product_identity',
            'price' => 'store_price',
            'limit' => $limit,
            'offset' => $offset,
        ];
        if ($type !== null) {
            $parameters['type'] = $type;
        }

        return $this->connection->fetchAllAssociative(
            'SELECT contribution_type AS contributionType, payload_json AS payload,
                    reviewed_at AS publishedAt
             FROM catalog_contributions
             WHERE moderation_status = :approved
               AND contribution_type IN (:identity, :price)'
            . $typeFilter
            . ' ORDER BY reviewed_at DESC, id DESC LIMIT :limit OFFSET :offset',
            $parameters,
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

    public function contributionForProposal(string $id): ?array
    {
        $sql = 'SELECT c.id, c.contribution_type AS contributionType,
                       c.payload_json AS payloadJson,
                       c.moderation_status AS status, c.revision,
                       l.contribution_revision AS linkedContributionRevision,
                       l.proposal_id AS proposalId,
                       l.published_category_id AS publishedCategoryId,
                       l.linked_at AS linkedAt,
                       p.moderation_status AS proposalStatus,
                       category.canonical_name AS publishedCategoryName
                FROM catalog_contributions c
                LEFT JOIN catalog_contribution_proposals l ON l.contribution_id = c.id
                LEFT JOIN catalog_proposals p ON p.id = l.proposal_id
                LEFT JOIN categories category ON category.id = l.published_category_id
                WHERE c.id = :id';
        if (! $this->connection->getDatabasePlatform() instanceof SQLitePlatform) {
            $sql .= ' FOR UPDATE';
        }
        $row = $this->connection->fetchAssociative($sql, ['id' => $id]);
        if ($row === false) {
            return null;
        }
        $payload = json_decode((string) $row['payloadJson'], true, 32, JSON_THROW_ON_ERROR);
        $row['payload'] = is_array($payload) && ! array_is_list($payload) ? $payload : [];
        $row['revision'] = (int) $row['revision'];
        $row['linkedContributionRevision'] = $row['linkedContributionRevision'] === null
            ? null
            : (int) $row['linkedContributionRevision'];
        unset($row['payloadJson']);

        return $row;
    }

    public function linkContributionProposal(
        string $contributionId,
        int $contributionRevision,
        string $proposalId,
        string $publishedCategoryId,
        string $actorUserId,
        DateTimeImmutable $at,
    ): bool {
        $date = $this->date($at);
        try {
            $this->connection->insert('catalog_contribution_proposals', [
                'contribution_id' => $contributionId,
                'contribution_revision' => $contributionRevision,
                'proposal_id' => $proposalId,
                'published_category_id' => $publishedCategoryId,
                'linked_by_user_id' => $actorUserId,
                'linked_at' => $date,
            ]);
        } catch (UniqueConstraintViolationException) {
            return false;
        }
        return true;
    }

    private function date(DateTimeImmutable $date): string
    {
        return $date->format('Y-m-d H:i:s.u');
    }

    private function atom(string $date): string
    {
        return (new DateTimeImmutable($date))->format(DATE_ATOM);
    }

    /**
     * @param array<string, string> $payload
     * @return array{outcome: 'replayed', record: array<string, mixed>}
     *     |array{outcome: 'conflict'}
     */
    private function submissionReplay(
        string $id,
        string $homeId,
        string $consentReceiptId,
        string $type,
        ?string $sourceFingerprint,
        array $payload,
    ): array {
        $row = $this->connection->fetchAssociative(
            'SELECT c.id, c.home_id AS homeId, c.consent_receipt_id AS consentReceiptId,
                    c.contribution_type AS contributionType,
                    c.source_fingerprint AS sourceFingerprint,
                    c.payload_json AS payloadJson, c.moderation_status AS status,
                    c.revision, c.created_at AS createdAt
             FROM catalog_contributions c WHERE c.id = :id',
            ['id' => $id],
        );
        if ($row === false) {
            return ['outcome' => 'conflict'];
        }
        $existingPayload = json_decode((string) $row['payloadJson'], true, 32, JSON_THROW_ON_ERROR);
        if (
            (string) $row['homeId'] !== $homeId
            || (string) $row['consentReceiptId'] !== $consentReceiptId
            || (string) $row['contributionType'] !== $type
            || $row['sourceFingerprint'] !== $sourceFingerprint
            || $existingPayload !== $payload
        ) {
            return ['outcome' => 'conflict'];
        }

        return [
            'outcome' => 'replayed',
            'record' => [
                'id' => (string) $row['id'],
                'contributionType' => (string) $row['contributionType'],
                'payload' => $existingPayload,
                'status' => (string) $row['status'],
                'revision' => (int) $row['revision'],
                'createdAt' => (new DateTimeImmutable((string) $row['createdAt']))->format(DATE_ATOM),
            ],
        ];
    }
}
