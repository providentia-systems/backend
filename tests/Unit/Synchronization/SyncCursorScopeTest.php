<?php

declare(strict_types=1);

namespace ProvidentiaTest\Unit\Synchronization;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Providentia\Home\Application\HomePermission;
use Providentia\Identity\Application\AuthenticatedIdentity;
use Providentia\SharedKernel\Application\Problem;
use Providentia\Synchronization\Application\CursorCodec;
use Providentia\Synchronization\Application\SnapshotCursorCodec;
use Providentia\Synchronization\Application\SyncReadPolicy;

final class SyncCursorScopeTest extends TestCase
{
    private const SECRET = 'cursor-scope-regression-secret-32';

    public function testScopeIsStableAcrossPermissionOrderAndSessionRotation(): void
    {
        $first = $this->identity('user', 'device', 'session-one');
        $rotated = $this->identity('user', 'device', 'session-two');
        $permissions = [HomePermission::INVENTORY_READ, HomePermission::PURCHASES_READ];
        self::assertSame(
            SyncReadPolicy::scope($first, $permissions),
            SyncReadPolicy::scope($rotated, [
                HomePermission::PURCHASES_READ,
                HomePermission::INVENTORY_READ,
                HomePermission::PURCHASES_READ,
                HomePermission::INVENTORY_WRITE,
            ]),
        );
        self::assertNotSame(
            SyncReadPolicy::scope($first, $permissions),
            SyncReadPolicy::scope($this->identity('other-user', 'device'), $permissions),
        );
        self::assertNotSame(
            SyncReadPolicy::scope($first, $permissions),
            SyncReadPolicy::scope($this->identity('user', 'other-device'), $permissions),
        );
    }

    #[DataProvider('scopeChanges')]
    public function testScopeChangesRequireBootstrapForBothCursorKinds(?string $issuedScope, string $currentScope): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2026-09-16T00:00:00Z'));
        $incremental = new CursorCodec(self::SECRET, $clock, 3600);
        $snapshot = new SnapshotCursorCodec(self::SECRET, $clock, 3600);
        foreach (
            [
            [$incremental, $incremental->encode('home', 3, 9, $issuedScope)],
            [$snapshot, $snapshot->encode('home', 9, 'inventory-location', 'location', $issuedScope)],
            ] as [$codec, $cursor]
        ) {
            try {
                $codec->decode($cursor, 'home', $currentScope);
                self::fail('An obsolete cursor was accepted.');
            } catch (Problem $problem) {
                self::assertSame(410, $problem->status);
                self::assertSame('https://providentia.invalid/problems/sync_resync_required', $problem->type);
            }
        }
    }

    /** @return iterable<string, array{?string, string}> */
    public static function scopeChanges(): iterable
    {
        yield 'legacy cursor' => [null, 'current-scope'];
        yield 'grant or revocation' => ['before-change', 'after-change'];
        yield 'account or device switch' => ['first-reader', 'second-reader'];
    }

    public function testMatchingScopePreservesFrozenTraversal(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2026-09-16T00:00:00Z'));
        $incremental = new CursorCodec(self::SECRET, $clock, 3600);
        $snapshot = new SnapshotCursorCodec(self::SECRET, $clock, 3600);
        self::assertSame(
            ['position' => 3, 'highWater' => 9],
            $incremental->decode($incremental->encode('home', 3, 9, 'scope'), 'home', 'scope'),
        );
        self::assertSame(
            ['highWater' => 9, 'entityType' => 'inventory-location', 'entityId' => 'location'],
            $snapshot->decode($snapshot->encode('home', 9, 'inventory-location', 'location', 'scope'), 'home', 'scope'),
        );
    }

    #[DataProvider('invalidClaims')]
    public function testSignedMalformedNumericClaimsAreNotCoerced(string $claim, mixed $value): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2026-09-16T00:00:00Z'));
        $codec = new CursorCodec(self::SECRET, $clock, 3600);
        $claims = [
            'v' => 1,
            'home' => 'home',
            'scope' => 'scope',
            'position' => 0,
            'highWater' => 9,
            'expiresAt' => $clock->now()->getTimestamp() + 3600,
        ];
        $claims[$claim] = $value;
        $encoded = rtrim(strtr(base64_encode(json_encode($claims, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
        $signature = rtrim(strtr(base64_encode(hash_hmac('sha256', $encoded, self::SECRET, true)), '+/', '-_'), '=');
        try {
            $codec->decode($encoded . '.' . $signature, 'home', 'scope');
            self::fail('Malformed signed claims were coerced.');
        } catch (Problem $problem) {
            self::assertSame(422, $problem->status);
        }
    }

    /** @return iterable<string, array{string, mixed}> */
    public static function invalidClaims(): iterable
    {
        yield 'string position' => ['position', '0'];
        yield 'boolean position' => ['position', false];
        yield 'fractional high water' => ['highWater', 9.5];
        yield 'string expiry' => ['expiresAt', '1790000000'];
        yield 'negative position' => ['position', -1];
        yield 'backward high water' => ['highWater', -1];
        yield 'overflow high water' => ['highWater', 1.0e30];
    }

    private function identity(string $user, string $device, string $session = 'session'): AuthenticatedIdentity
    {
        return new AuthenticatedIdentity($user, $session, $device, null, []);
    }
}
