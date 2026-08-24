<?php

declare(strict_types=1);

namespace Providentia\Catalog\Application;

use DateTimeImmutable;

interface CatalogGovernanceStore extends CatalogIconPublisher
{
    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|null
     */
    public function conflictFor(string $type, string $normalizedKey, array $payload): ?array;

    /** @param array<string, mixed> $payload */
    public function createProposal(
        string $id,
        string $type,
        string $normalizedKey,
        array $payload,
        string $status,
        ?string $duplicateEntityId,
        string $actorUserId,
        DateTimeImmutable $at,
    ): void;

    /** @return array<string, mixed>|null */
    public function proposal(string $id): ?array;

    /**
     * Standalone proposals are eligible. Contribution-linked proposals are
     * eligible only while their approved source and linked revision remain current.
     */
    public function proposalSourceEligible(string $proposalId): bool;

    /** @return list<array<string, mixed>> */
    public function workbench(string $queue, int $limit, int $offset): array;

    /**
     * @param array<string, mixed> $proposal
     * @return array{entityType: string, entityId: string}
     */
    public function publishProposal(
        array $proposal,
        string $entityId,
        string $actorUserId,
        DateTimeImmutable $at,
    ): array;

    public function decideProposal(
        string $proposalId,
        string $decision,
        string $reason,
        int $expectedRevision,
        ?string $resolvedEntityType,
        ?string $resolvedEntityId,
        string $actorUserId,
        string $revisionId,
        DateTimeImmutable $at,
    ): bool;

    public function resolveConflictKeepingExisting(
        string $conflictId,
        int $expectedRevision,
        string $reason,
        string $actorUserId,
        string $revisionId,
        DateTimeImmutable $at,
    ): bool;

    /**
     * @param list<string> $duplicateIds
     * @return array<string, mixed>
     */
    public function mergePreview(string $survivorId, array $duplicateIds): array;

    /**
     * @param array<string, int> $duplicateRevisions
     * @return array<string, mixed>
     */
    public function applyMerge(
        string $mergeId,
        string $survivorId,
        int $expectedSurvivorRevision,
        array $duplicateRevisions,
        string $reason,
        string $actorUserId,
        DateTimeImmutable $at,
    ): array;

    /** @return array<string, mixed> */
    public function reverseMerge(
        string $mergeId,
        int $expectedRevision,
        string $reason,
        string $actorUserId,
        DateTimeImmutable $at,
    ): array;
}
