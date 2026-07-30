<?php

declare(strict_types=1);

namespace ProvidentiaTest\Unit\Synchronization;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Providentia\SharedKernel\Application\Problem;
use Providentia\Synchronization\Application\CursorCodec;

final class CursorCodecTest extends TestCase
{
    public function testRoundTripPreservesPositionAndFrozenHighWater(): void
    {
        $clock = new MutableClock(new DateTimeImmutable('2026-07-30T12:00:00+00:00'));
        $codec = new CursorCodec(str_repeat('c', 32), $clock, 3600);

        $cursor = $codec->encode('01912345-6789-7abc-8def-0123456789ab', 17, 29);

        self::assertSame(
            ['position' => 17, 'highWater' => 29],
            $codec->decode($cursor, '01912345-6789-7abc-8def-0123456789ab'),
        );
    }

    public function testCursorCannotCrossAHomeBoundary(): void
    {
        $clock = new MutableClock(new DateTimeImmutable('2026-07-30T12:00:00+00:00'));
        $codec = new CursorCodec(str_repeat('c', 32), $clock, 3600);
        $cursor = $codec->encode('01912345-6789-7abc-8def-0123456789ab', 0, 0);

        try {
            $codec->decode($cursor, '01912345-6789-7abc-9def-0123456789ab');
            self::fail('A cursor issued for another home was accepted.');
        } catch (Problem $problem) {
            self::assertSame(404, $problem->status);
        }
    }

    public function testExpiredCursorRequiresSafeResynchronization(): void
    {
        $clock = new MutableClock(new DateTimeImmutable('2026-07-30T12:00:00+00:00'));
        $codec = new CursorCodec(str_repeat('c', 32), $clock, 60);
        $cursor = $codec->encode('01912345-6789-7abc-8def-0123456789ab', 0, 0);
        $clock->time = new DateTimeImmutable('2026-07-30T12:01:01+00:00');

        try {
            $codec->decode($cursor, '01912345-6789-7abc-8def-0123456789ab');
            self::fail('An expired cursor was accepted.');
        } catch (Problem $problem) {
            self::assertSame(410, $problem->status);
            self::assertSame(
                'https://providentia.invalid/problems/sync_resync_required',
                $problem->type,
            );
        }
    }
}
