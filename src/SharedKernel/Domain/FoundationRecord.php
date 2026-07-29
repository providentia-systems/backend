<?php

declare(strict_types=1);

namespace Providentia\SharedKernel\Domain;

use DateTimeImmutable;

/** Historical Phase 1 persistence/outbox proof aggregate; not a product record. */
final class FoundationRecord
{
    public function __construct(
        private readonly string $id,
        private readonly string $label,
        private readonly DateTimeImmutable $createdAt,
    ) {
        if ($id === '' || $label === '') {
            throw new \InvalidArgumentException('Foundation record values cannot be empty.');
        }
    }

    public function id(): string
    {
        return $this->id;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
