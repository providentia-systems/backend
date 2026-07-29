<?php

declare(strict_types=1);

namespace Providentia\SharedKernel\Application\Async;

use Throwable;

final class OutboxRelay
{
    public function __construct(
        private readonly OutboxStore $store,
        private readonly AsyncMessageBus $bus,
        private readonly int $batchSize,
        private readonly int $maxAttempts,
    ) {
    }

    /** @return array{published: int, failed: int} */
    public function relayOnce(): array
    {
        $published = 0;
        $failed = 0;

        foreach ($this->store->claimBatch($this->batchSize) as $message) {
            try {
                $this->bus->publish($message);
                $this->store->markPublished($message->id);
                ++$published;
            } catch (Throwable $error) {
                $this->store->markFailed($message->id, $error->getMessage(), $this->maxAttempts);
                ++$failed;
            }
        }

        return ['published' => $published, 'failed' => $failed];
    }
}
