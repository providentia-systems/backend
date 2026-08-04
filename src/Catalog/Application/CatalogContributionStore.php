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

    /** @param array<string, string> $payload */
    public function createContribution(
        string $id,
        string $homeId,
        string $consentReceiptId,
        string $type,
        ?string $sourceEntityId,
        array $payload,
        string $actorUserId,
        DateTimeImmutable $at,
    ): bool;

    /** @return list<array<string, mixed>> */
    public function contributionsForHome(string $homeId, int $limit, int $offset): array;

    /** @return list<array<string, mixed>> */
    public function reviewQueue(string $status, int $limit, int $offset): array;

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
}
