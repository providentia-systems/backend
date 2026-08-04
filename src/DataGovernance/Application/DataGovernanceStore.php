<?php

declare(strict_types=1);

namespace Providentia\DataGovernance\Application;

use DateTimeImmutable;

interface DataGovernanceStore
{
    /** @return list<string> */
    public function ownedHomeIds(string $userId): array;

    /** @param list<array{category: string, treatment: string, reason: string}> $disclosure */
    public function createRequest(
        string $id,
        string $requestKind,
        string $scopeType,
        string $scopeFingerprint,
        ?string $subjectUserId,
        ?string $homeId,
        string $requestedByUserId,
        array $disclosure,
        DateTimeImmutable $at,
    ): void;

    /** @return array<string, mixed>|null */
    public function request(string $id): ?array;

    /** @return list<array<string, mixed>> */
    public function requestsForUser(string $userId, int $limit, int $offset): array;

    /** @return list<array<string, mixed>> */
    public function requestsForHome(string $homeId, int $limit, int $offset): array;

    public function cancel(string $id, int $expectedRevision, DateTimeImmutable $at): bool;

    /** @return array<string, mixed>|null */
    public function nextQueuedRequest(): ?array;

    public function completeExport(
        string $id,
        int $expectedRevision,
        DataArtifact $artifact,
        DateTimeImmutable $expiresAt,
        DateTimeImmutable $at,
    ): bool;

    public function setDownloadToken(
        string $id,
        int $expectedRevision,
        string $tokenHash,
        DateTimeImmutable $expiresAt,
        DateTimeImmutable $at,
    ): bool;

    /** @return array<string, mixed>|null */
    public function consumeDownload(string $id, string $tokenHash, DateTimeImmutable $at): ?array;

    public function transition(
        string $id,
        string $fromStatus,
        string $toStatus,
        int $expectedRevision,
        ?string $artifactReference,
        ?DateTimeImmutable $artifactExpiresAt,
        ?string $failureReason,
        DateTimeImmutable $at,
    ): bool;
}
