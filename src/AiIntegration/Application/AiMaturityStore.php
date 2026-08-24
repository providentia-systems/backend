<?php

declare(strict_types=1);

namespace Providentia\AiIntegration\Application;

use DateTimeImmutable;
use Providentia\AiIntegration\Application\Media\EncryptedMediaObject;

interface AiMaturityStore
{
    /** @return list<array<string, mixed>> */
    public function providerProfiles(string $homeId): array;

    /** @return array<string, mixed>|null */
    public function providerProfile(string $homeId, string $profileId): ?array;

    /** @param array<string, mixed> $profile */
    public function saveProviderProfile(array $profile, int $expectedRevision, DateTimeImmutable $at): bool;

    public function revokeProviderProfile(
        string $homeId,
        string $profileId,
        int $expectedRevision,
        string $actorUserId,
        DateTimeImmutable $at,
    ): bool;

    /**
     * Clears every encrypted credential field under the profile revision CAS
     * and records the credential-revocation audit event.
     */
    public function revokeProviderProfileCredential(
        string $auditId,
        string $homeId,
        string $profileId,
        int $expectedRevision,
        string $actorUserId,
        DateTimeImmutable $at,
    ): bool;

    /** @return array<string, mixed>|null */
    public function orchestrationPolicy(string $homeId): ?array;

    /** @param list<string> $extractionProfileIds */
    public function saveOrchestrationPolicy(
        string $homeId,
        array $extractionProfileIds,
        ?string $validationProfileId,
        int $maxAttempts,
        int $maxTotalTokens,
        int $maxEstimatedCostMicros,
        int $expectedRevision,
        string $actorUserId,
        DateTimeImmutable $at,
    ): bool;

    /** @param array<string, mixed> $attempt */
    public function appendExtractionAttempt(
        string $extractionId,
        int $position,
        int $observationIndex,
        array $attempt,
        DateTimeImmutable $at,
    ): void;

    /** @param list<array<string, mixed>> $discrepancies */
    public function appendExtractionDiscrepancies(
        string $extractionId,
        int $observationIndex,
        int $positionOffset,
        array $discrepancies,
        DateTimeImmutable $at,
    ): void;

    public function reviewExtractionDiscrepancy(
        string $homeId,
        string $extractionId,
        int $position,
        string $decision,
        int $expectedRevision,
        string $actorUserId,
        DateTimeImmutable $at,
    ): bool;

    public function hasBlockingExtractionDiscrepancies(string $homeId, string $extractionId): bool;

    public function mediaQuota(string $homeId, int $defaultBytes): int;

    public function mediaUsage(string $homeId): int;

    public function reserveMediaBytes(
        string $homeId,
        int $bytes,
        int $defaultQuotaBytes,
        DateTimeImmutable $at,
    ): bool;

    public function releaseMediaBytes(string $homeId, int $bytes, DateTimeImmutable $at): void;

    /** @return array<string, mixed>|null */
    public function activeMediaByDigest(string $homeId, string $sha256): ?array;

    public function insertMediaWithinQuota(
        string $id,
        string $homeId,
        ?string $sourceAssetId,
        string $retention,
        string $purpose,
        string $mimeType,
        ?string $originalName,
        EncryptedMediaObject $object,
        ?int $durationMs,
        ?int $frameOffsetMs,
        string $processingStatus,
        string $actorUserId,
        ?DateTimeImmutable $expiresAt,
        int $defaultQuotaBytes,
        DateTimeImmutable $at,
    ): bool;

    /** @return array<string, mixed>|null */
    public function media(string $homeId, string $assetId): ?array;

    /** @return list<array<string, mixed>> */
    public function listMedia(string $homeId, int $limit, ?string $beforeId = null): array;

    /** @return list<array<string, mixed>> */
    public function derivedMedia(string $homeId, string $sourceAssetId): array;

    public function markMediaDeleted(string $homeId, string $assetId, DateTimeImmutable $at): bool;

    public function deleteMediaWithinQuota(
        string $homeId,
        string $assetId,
        int $plaintextBytes,
        DateTimeImmutable $at,
    ): bool;

    public function updateMediaRetention(
        string $homeId,
        string $assetId,
        string $retention,
        ?DateTimeImmutable $expiresAt,
        int $expectedRevision,
        DateTimeImmutable $at,
    ): bool;

    public function updateDerivedMediaRetention(
        string $homeId,
        string $sourceAssetId,
        string $retention,
        ?DateTimeImmutable $expiresAt,
        DateTimeImmutable $at,
    ): void;

    /** @return array<string, mixed>|null */
    public function claimQueuedVideo(DateTimeImmutable $at): ?array;

    public function finishVideo(
        string $homeId,
        string $assetId,
        ?int $durationMs,
        ?string $error,
        DateTimeImmutable $at,
    ): void;

    /** @return list<array<string, mixed>> */
    public function expiredMedia(DateTimeImmutable $at, int $limit): array;

    /** @param array<string, mixed> $evidence */
    public function recordObservationDecision(
        string $id,
        string $homeId,
        ?string $extractionId,
        string $type,
        string $leftReference,
        string $rightReference,
        array $evidence,
        string $decision,
        DateTimeImmutable $at,
    ): void;

    /** @return list<array<string, mixed>> */
    public function observationDecisions(string $homeId, string $extractionId): array;

    public function hasPendingObservationDecisions(string $homeId, string $extractionId): bool;

    public function candidateIsConfirmedDuplicate(string $homeId, string $extractionId, int $position): bool;

    public function reviewObservationDecision(
        string $homeId,
        string $id,
        string $decision,
        int $expectedRevision,
        string $actorUserId,
        DateTimeImmutable $at,
    ): bool;
}
