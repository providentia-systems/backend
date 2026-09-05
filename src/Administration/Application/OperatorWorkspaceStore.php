<?php

declare(strict_types=1);

namespace Providentia\Administration\Application;

interface OperatorWorkspaceStore
{
    /** @return list<array<string, mixed>> */
    public function homes(string $search, int $offset): array;

    /** @return array<string, mixed>|null */
    public function home(string $id): ?array;

    /** @return list<array<string, mixed>> */
    public function records(string $homeId, string $collection, int $offset): array;

    /** @return list<array<string, mixed>> */
    public function administrators(): array;

    public function reviewAdministrator(string $userId, string $actorId, string $status, int $revision, string $now): bool;
}
