<?php

declare(strict_types=1);

namespace Providentia\Identity\Application;

use DateTimeImmutable;

interface NotificationOutbox
{
    /** @param array<string, scalar|null> $context */
    public function enqueue(
        string $id,
        string $template,
        string $recipient,
        array $context,
        DateTimeImmutable $availableAt,
    ): void;

    /** @return list<array{id: string, template: string, recipient: string, context: array<string, scalar|null>}> */
    public function lease(int $limit, DateTimeImmutable $now, DateTimeImmutable $leaseUntil): array;

    public function complete(string $id, DateTimeImmutable $at): void;

    public function fail(string $id, string $failureClass, DateTimeImmutable $at, int $maxAttempts): void;
}
