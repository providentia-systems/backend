<?php

declare(strict_types=1);

namespace Providentia\Shopping\Application;

use DateTimeImmutable;

interface ShoppingStore
{
    /** @return list<array<string, mixed>> */
    public function lists(string $homeId): array;

    /** @return array<string, mixed>|null */
    public function shoppingList(string $homeId, string $listId): ?array;

    /** @return list<array<string, mixed>> */
    public function lines(string $homeId, string $listId): array;

    /** @return array<string, mixed>|null */
    public function line(string $homeId, string $listId, string $lineId): ?array;

    public function createList(
        string $id,
        string $homeId,
        string $name,
        string $kind,
        string $actorUserId,
        DateTimeImmutable $at,
    ): void;

    public function addLine(
        string $id,
        string $homeId,
        string $listId,
        int $expectedListRevision,
        ?string $homeProductId,
        string $description,
        string $source,
        string $quantity,
        string $explanation,
        ?string $confidence,
        DateTimeImmutable $at,
        ?string $suggestionId = null,
    ): bool;

    public function setChecked(
        string $homeId,
        string $listId,
        string $lineId,
        bool $checked,
        int $expectedRevision,
        DateTimeImmutable $at,
    ): bool;

    public function updateList(
        string $homeId,
        string $listId,
        string $name,
        string $status,
        int $expectedRevision,
        DateTimeImmutable $at,
    ): bool;

    public function updateLine(
        string $homeId,
        string $listId,
        string $lineId,
        string $description,
        string $quantity,
        bool $archived,
        int $expectedRevision,
        DateTimeImmutable $at,
    ): bool;

    /** @return list<array<string, mixed>> */
    public function legacySuggestionCandidates(string $homeId): array;
}
