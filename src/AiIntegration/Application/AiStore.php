<?php

declare(strict_types=1);

namespace Providentia\AiIntegration\Application;

use DateTimeImmutable;

interface AiStore
{
    /** @return array<string, mixed>|null */
    public function settings(string $homeId): ?array;

    public function saveSettings(
        string $homeId,
        string $mode,
        ?string $provider,
        ?string $model,
        int $expectedRevision,
        string $actorUserId,
        DateTimeImmutable $at,
    ): bool;

    /** @return array<string, mixed>|null */
    public function credential(string $homeId, string $provider): ?array;

    public function saveCredential(
        string $id,
        string $homeId,
        string $provider,
        string $ciphertext,
        string $nonce,
        int $keyVersion,
        string $lastFour,
        string $actorUserId,
        DateTimeImmutable $at,
    ): void;

    public function removeCredential(string $homeId, string $provider, DateTimeImmutable $at): bool;

    public function targetExists(string $homeId, string $kind, ?string $targetId): bool;

    public function startExtraction(
        string $id,
        string $homeId,
        string $kind,
        ?string $targetId,
        string $provider,
        string $model,
        string $mimeType,
        string $digest,
        int $byteCount,
        int $promptTemplateVersion,
        string $actorUserId,
        DateTimeImmutable $at,
    ): void;

    /**
     * @param array<string, mixed> $result
     * @param array{inputTokens: int|null, outputTokens: int|null, totalTokens: int|null} $usage
     */
    public function completeExtraction(
        string $id,
        string $homeId,
        array $result,
        array $usage,
        int $processingMs,
        DateTimeImmutable $at,
    ): void;

    public function failExtraction(
        string $id,
        string $homeId,
        string $code,
        string $safeDetail,
        DateTimeImmutable $at,
    ): void;

    /** @return array<string, mixed>|null */
    public function extraction(string $homeId, string $id): ?array;

    public function reviewCandidate(
        string $homeId,
        string $extractionId,
        int $position,
        string $decision,
        int $expectedRevision,
        string $actorUserId,
        DateTimeImmutable $at,
    ): bool;
}
