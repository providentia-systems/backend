<?php

declare(strict_types=1);

namespace Providentia\Access\Application;

interface AccessStore
{
    /** @return list<array<string, mixed>> */
    public function groups(?string $scope = null): array;
    /** @return array<string, mixed>|null */
    public function group(string $id): ?array;
    /** @param array<string, mixed> $group */
    public function saveGroup(array $group, int $expectedRevision): bool;
    /** @return array<string, mixed>|null */
    public function assignment(string $scope, string $subjectId): ?array;
    public function assign(string $scope, string $subjectId, string $groupId, int $expectedRevision): bool;
    public function lockSubject(string $scope, string $subjectId): void;
    public function resourceCount(string $scope, string $subjectId, string $resource): int;
    /** @return array<string, mixed>|null */
    public function memberPolicy(string $homeId, string $userId): ?array;
    /** @param array<string, bool> $permissions */
    public function saveMemberPolicy(string $homeId, string $userId, array $permissions, int $expectedRevision): bool;
    /** @param array<string, mixed> $details */
    public function audit(?string $actorId, string $action, string $scope, string $subjectId, array $details): void;
    /** @return list<array<string, mixed>> */
    public function auditEvents(int $limit, int $offset): array;
}
