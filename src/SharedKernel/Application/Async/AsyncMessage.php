<?php

declare(strict_types=1);

namespace Providentia\SharedKernel\Application\Async;

use DateTimeImmutable;

final readonly class AsyncMessage
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public string $id,
        public string $type,
        public array $payload,
        public DateTimeImmutable $occurredAt,
        public string $queue = 'providentia.default',
    ) {
        if ($id === '' || $type === '' || $queue === '') {
            throw new \InvalidArgumentException('Message id, type, and queue are required.');
        }
    }
}

