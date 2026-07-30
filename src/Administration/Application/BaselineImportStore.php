<?php

declare(strict_types=1);

namespace Providentia\Administration\Application;

use DateTimeImmutable;

interface BaselineImportStore
{
    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $rules
     * @param array<string, int|string> $reconciliation
     * @return array<string, int|string|bool>
     */
    public function import(
        string $homeId,
        string $actorUserId,
        string $dataDigest,
        string $rulesDigest,
        array $data,
        array $rules,
        array $reconciliation,
        DateTimeImmutable $at,
    ): array;
}
