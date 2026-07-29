<?php

declare(strict_types=1);

namespace ProvidentiaTest\Unit\SharedKernel;

use Providentia\SharedKernel\Application\Async\AsyncMessage;
use Providentia\SharedKernel\Application\Async\OutboxStore;

final class InMemoryOutboxStore implements OutboxStore
{
    /** @var list<string> */
    public array $published = [];

    /** @var list<string> */
    public array $failed = [];

    /** @param list<AsyncMessage> $messages */
    public function __construct(private array $messages)
    {
    }

    public function append(AsyncMessage $message): void
    {
        $this->messages[] = $message;
    }

    public function claimBatch(int $limit): array
    {
        return array_slice($this->messages, 0, $limit);
    }

    public function markPublished(string $messageId): void
    {
        $this->published[] = $messageId;
    }

    public function markFailed(string $messageId, string $reason, int $maxAttempts): void
    {
        $this->failed[] = $messageId;
    }

    public function metrics(): array
    {
        return [
            'pending' => count($this->messages),
            'failed' => count($this->failed),
            'oldest_pending_seconds' => 0.0,
        ];
    }
}
