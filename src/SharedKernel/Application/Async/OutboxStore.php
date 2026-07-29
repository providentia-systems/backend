<?php

declare(strict_types=1);

namespace Providentia\SharedKernel\Application\Async;

interface OutboxStore
{
    public function append(AsyncMessage $message): void;

    /** @return list<AsyncMessage> */
    public function claimBatch(int $limit): array;

    public function markPublished(string $messageId): void;

    public function markFailed(string $messageId, string $reason, int $maxAttempts): void;

    /** @return array{pending: int, failed: int, oldest_pending_seconds: float} */
    public function metrics(): array;
}
