<?php

declare(strict_types=1);

namespace ProvidentiaTest\Unit\SharedKernel;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Providentia\SharedKernel\Application\Async\AsyncMessage;
use Providentia\SharedKernel\Application\Async\OutboxRelay;

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
