<?php

declare(strict_types=1);

namespace Providentia\Identity\Application;

interface AccountProfileStore
{
    /**
     * @return array<string, mixed> */
    public function profile(string $userId): array;

    /**
     * @param array<string, mixed> $values */
    public function update(string $userId, array $values, int $revision): bool;

    /**
     * @return list<array<string, mixed>> */
    public function emails(string $userId): array;

    public function addEmail(string $id, string $userId, string $email, string $now): void;

    public function makePrimary(string $userId, string $emailId): bool;

    public function removeEmail(string $userId, string $emailId): bool;

    public function registerAdministrator(string $userId, string $now): void;

    public function administratorStatus(string $userId): ?string;

    public function claimSystemOwner(string $userId, string $email): bool;

    public function isSystemOwner(string $userId): bool;
}
