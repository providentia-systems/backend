<?php

declare(strict_types=1);

namespace Providentia\Identity\Application;

use DateTimeImmutable;

interface LoginLinkStore
{
    /** @param array<string, scalar|null> $request */
    public function create(array $request): void;

    /** @return array<string, mixed>|null */
    public function find(string $requestId): ?array;

    /** @return array<string, mixed>|null */
    public function findByPollChallenge(string $pollChallenge): ?array;

    public function lockEmail(string $normalizedEmail): void;

    public function reserveApproval(
        string $requestId,
        string $approvalTokenHash,
        DateTimeImmutable $approvedAt,
        DateTimeImmutable $exchangeExpiresAt,
    ): bool;

    public function completeApproval(
        string $requestId,
        string $userId,
        ?string $onboardingHomeId,
        DateTimeImmutable $at,
    ): void;

    public function deny(string $requestId, string $approvalTokenHash, DateTimeImmutable $at): bool;

    public function expire(string $requestId, DateTimeImmutable $at): void;

    public function cancel(string $requestId, DateTimeImmutable $at): bool;

    public function recordFailedProof(string $requestId, DateTimeImmutable $at): int;

    public function reserveExchange(string $requestId, DateTimeImmutable $at): bool;

    public function failExchange(string $requestId, DateTimeImmutable $at): bool;

    public function completeExchange(string $requestId, string $sessionId, DateTimeImmutable $at): void;

    /** @return array{expired: int, purged: int} */
    public function purgeExpired(
        DateTimeImmutable $at,
        DateTimeImmutable $retentionCutoff,
        int $limit,
    ): array;
}
