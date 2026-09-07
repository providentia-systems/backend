<?php

declare(strict_types=1);

namespace Providentia\Identity\Application;

interface ProfileMediaStore
{
    /**
     * @return array<string, mixed>|null */
    public function homeProfile(string $homeId): ?array;
    /**
     * @param array<string, mixed> $values */
    public function updateHomeProfile(
        string $homeId,
        array $values,
        int $revision,
    ): bool;
    /** @return list<string> */
    public function sharedHomes(
        string $userId,
        string $otherUserId,
    ): array;
    /**
     * @return array{bytes: string, digest: string}|null */
    public function image(string $scope, string $id): ?array;
    public function saveImage(
        string $scope,
        string $id,
        ?string $bytes,
        ?string $digest,
        int $revision,
        string $now,
    ): bool;
}
