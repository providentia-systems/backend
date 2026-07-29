<?php

declare(strict_types=1);

namespace ProvidentiaTest\Unit\SharedKernel;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Providentia\SharedKernel\Application\Async\AsyncMessage;

final class AsyncMessageTest extends TestCase
{
    public function testItRetainsAnImmutableTransportNeutralEnvelope(): void
    {
        $message = new AsyncMessage(
            '018fb2f7-30ed-7c8a-b344-739663f568a1',
            'foundation.recorded.v1',
            ['recordId' => 'record-1'],
            new DateTimeImmutable('2026-07-29T12:00:00+00:00'),
        );

        self::assertSame('foundation.recorded.v1', $message->type);
        self::assertSame(['recordId' => 'record-1'], $message->payload);
        self::assertSame('providentia.default', $message->queue);
    }

    public function testItRejectsAnEmptyIdentity(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new AsyncMessage('', 'foundation.recorded.v1', [], new DateTimeImmutable());
    }
}

