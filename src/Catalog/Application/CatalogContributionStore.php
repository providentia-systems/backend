<?php

declare(strict_types=1);

namespace Providentia\Catalog\Application;

use DateTimeImmutable;

interface CatalogContributionStore
{
    /** @return array<string, mixed>|null */
    public function consent(string $homeId): ?array;

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
    ): bool;

    /**
     * @param array<string, string> $payload
     * @return array{outcome: 'created'|'replayed'|'conflict', record?: array<string, mixed>}
     */
    public function createContribution(
        string $id,
        string $homeId,
        string $consentReceiptId,
        string $type,
        ?string $sourceEntityId,
        array $payload,
        string $actorUserId,
        DateTimeImmutable $at,
    ): array;

    /** @return list<array<string, mixed>> */
    public function contributionsForHome(string $homeId, int $limit, int $offset): array;

    /** @return list<array<string, mixed>> */
    public function reviewQueue(string $status, int $limit, int $offset): array;

    /**
     * Return the public projection of approved contributions only.
     *
     * Implementations must not select household, contributor, consent-receipt,
     * source-fingerprint, or reviewer attribution.
     *
     * @return list<array<string, mixed>>
     */
    public function published(?string $type, int $limit, int $offset): array;

    /** @return array<string, mixed>|null */
    public function contribution(string $id): ?array;

    public function decide(
        string $id,
        string $decision,
        string $reason,
        int $expectedRevision,
        string $reviewerUserId,
        DateTimeImmutable $at,
    ): bool;

    /**
     * Locks the contribution before creating or replaying its proposal link.
     *
     * @return array<string, mixed>|null
     */
    public function contributionForProposal(string $id): ?array;

    public function linkContributionProposal(
        string $contributionId,
        int $contributionRevision,
        string $proposalId,
        string $publishedCategoryId,
        string $actorUserId,
        DateTimeImmutable $at,
    ): bool;
}
