<?php

declare(strict_types=1);

namespace ProvidentiaTest\Unit\Synchronization;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Providentia\SharedKernel\Application\Problem;
use Providentia\Synchronization\Application\CursorCodec;
use Providentia\Synchronization\Application\SnapshotCursorCodec;

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

    public function testChangedSigningKeyRequiresTypedRecoveryForBothCursorKinds(): void
    {
        $clock = new MutableClock(new DateTimeImmutable('2026-07-30T12:00:00+00:00'));
        $old = new CursorCodec(str_repeat('c', 32), $clock, 3600);
        $current = new CursorCodec(str_repeat('d', 32), $clock, 3600);
        $oldSnapshot = new SnapshotCursorCodec(str_repeat('c', 32), $clock, 3600);
        $currentSnapshot = new SnapshotCursorCodec(str_repeat('d', 32), $clock, 3600);
        foreach ([
            [$current, $old->encode('home', 0, 0)],
            [$currentSnapshot, $oldSnapshot->encode('home', 0, 'inventory-location', 'location')],
        ] as [$codec, $cursor]) {
            try {
                $codec->decode($cursor, 'home');
                self::fail('A cursor with an obsolete signature was accepted.');
            } catch (Problem $problem) {
                self::assertSame(422, $problem->status);
                self::assertSame('https://providentia.invalid/problems/sync_cursor_invalid', $problem->type);
            }
        }
    }

    #[DataProvider('invalidCursorBodies')]
    public function testMalformedCursorCannotBeMistakenForLostHomeAccess(string $encoded): void
    {
        $clock = new MutableClock(new DateTimeImmutable('2026-07-30T12:00:00+00:00'));
        $secret = str_repeat('c', 32);
        $signature = rtrim(strtr(base64_encode(hash_hmac('sha256', $encoded, $secret, true)), '+/', '-_'), '=');
        foreach ([new CursorCodec($secret, $clock, 3600), new SnapshotCursorCodec($secret, $clock, 3600)] as $codec) {
            try {
                $codec->decode($encoded . '.' . $signature, 'home');
                self::fail('A malformed cursor was accepted.');
            } catch (Problem $problem) {
                self::assertSame(422, $problem->status);
                self::assertSame('https://providentia.invalid/problems/sync_cursor_invalid', $problem->type);
            }
        }
    }

    /** @return iterable<string, array{string}> */
    public static function invalidCursorBodies(): iterable
    {
        yield 'invalid base64' => ['!'];
        yield 'invalid JSON' => [rtrim(strtr(base64_encode('{'), '+/', '-_'), '=')];
        yield 'non-object claims' => [rtrim(strtr(base64_encode('false'), '+/', '-_'), '=')];
    }

    public function testMalformedTokenShapeHasTypedRecovery(): void
    {
        $clock = new MutableClock(new DateTimeImmutable('2026-07-30T12:00:00+00:00'));
        foreach ([new CursorCodec(str_repeat('c', 32), $clock, 3600),
            new SnapshotCursorCodec(str_repeat('c', 32), $clock, 3600)] as $codec) {
            try {
                $codec->decode('not-a-cursor', 'home');
                self::fail('An unsigned malformed token was accepted.');
            } catch (Problem $problem) {
                self::assertSame(422, $problem->status);
                self::assertSame('https://providentia.invalid/problems/sync_cursor_invalid', $problem->type);
            }
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
