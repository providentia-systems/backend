<?php

declare(strict_types=1);

namespace Providentia\Geography\Application;

interface CountryStore
{
    /**
     * @return list<array<string, mixed>> */
    public function countries(bool $publishedOnly): array;

    /**
     * @return array<string, mixed>|null */
    public function settings(string $code): ?array;

    /**
     * @return list<array<string, mixed>> */
    public function places(string $country, ?int $state, string $query, bool $cities, int $offset): array;

    public function validPlace(string $country, ?int $state, ?int $city): bool;

    /**
     * @param array<string, mixed> $values */
    public function saveSettings(string $code, array $values, int $revision): bool;

    /**
     * @return array<string, mixed>|null */
    public function policy(string $id): ?array;

    /**
     * @return list<array<string, mixed>> */
    public function policies(?string $country): array;

    /**
     * @param array<string, mixed> $values */
    public function savePolicy(string $id, array $values, int $revision): bool;

    public function acceptPolicy(string $userId, string $id, int $revision, string $country, string $now): void;

    /**
     * @return list<array<string, mixed>> */
    public function jobs(): array;

    public function requestUpdate(string $id, string $userId, string $now): void;
}
