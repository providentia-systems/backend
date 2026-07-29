<?php

declare(strict_types=1);

namespace ProvidentiaTest\Unit\SharedKernel;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Providentia\SharedKernel\Application\Async\AsyncMessage;
use Providentia\SharedKernel\Application\Async\AsyncMessageBus;
use Providentia\SharedKernel\Application\Async\OutboxRelay;
use Providentia\SharedKernel\Application\Async\OutboxStore;

final class OutboxRelayTest extends TestCase
{
    public function testItMarksSuccessfullyPublishedMessages(): void
    {
        $message = new AsyncMessage('message-1', 'foundation.recorded.v1', [], new DateTimeImmutable());
        $store = new InMemoryOutboxStore([$message]);
        $bus = new RecordingBus();

        $result = (new OutboxRelay($store, $bus, 10, 3))->relayOnce();

        self::assertSame(['published' => 1, 'failed' => 0], $result);
        self::assertSame(['message-1'], $store->published);
        self::assertSame(['message-1'], $bus->published);
    }

    public function testItReturnsFailedPublicationToStorePolicy(): void
    {
        $message = new AsyncMessage('message-2', 'foundation.recorded.v1', [], new DateTimeImmutable());
        $store = new InMemoryOutboxStore([$message]);
        $bus = new RecordingBus(true);

        $result = (new OutboxRelay($store, $bus, 10, 3))->relayOnce();

        self::assertSame(['published' => 0, 'failed' => 1], $result);
        self::assertSame(['message-2'], $store->failed);
    }
}

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
        return ['pending' => count($this->messages), 'failed' => count($this->failed), 'oldest_pending_seconds' => 0.0];
    }
}

final class RecordingBus implements AsyncMessageBus
{
    /** @var list<string> */
    public array $published = [];

    public function __construct(private readonly bool $fail = false)
    {
    }

    public function publish(AsyncMessage $message): void
    {
        if ($this->fail) {
            throw new \RuntimeException('broker unavailable');
        }
        $this->published[] = $message->id;
    }
}

