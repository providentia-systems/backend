<?php

declare(strict_types=1);

namespace ProvidentiaTest\Unit\Synchronization;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Providentia\Home\Application\HomeAuthorization;
use Providentia\Home\Application\HomePermission;
use Providentia\Home\Application\HomeStore;
use Providentia\Identity\Application\AuthenticatedIdentity;
use Providentia\SharedKernel\Application\Problem;
use Providentia\Synchronization\Application\CursorCodec;
use Providentia\Synchronization\Application\HomePreferenceSyncEntityPolicy;
use Providentia\Synchronization\Application\PrivateNoteSyncEntityPolicy;
use Providentia\Synchronization\Application\SnapshotCursorCodec;
use Providentia\Synchronization\Application\SyncEntityPolicyRegistry;
use Providentia\Synchronization\Application\SyncEnvelopeValidator;
use Providentia\Synchronization\Application\SyncOperationValidator;
use Providentia\Synchronization\Application\SyncReadPolicy;
use Providentia\Synchronization\Application\SyncRequestHasher;
use Providentia\Synchronization\Application\SyncResultPresenter;
use Providentia\Synchronization\Application\SyncSnapshotPage;
use Providentia\Synchronization\Application\SyncStore;
use Providentia\Synchronization\Application\SynchronizationService;
use ProvidentiaTest\Support\AccessFixture;

/** Real authorization/service regressions, not a claim of client cache acceptance. */
final class SyncPermissionCursorTest extends TestCase
{
    private const HOME = '01912345-6789-7abc-8def-0123456789ab';
    private const ENTITY = '01912345-6789-7abc-adef-1123456789ab';
    private const OPERATION = '01912345-6789-7abc-9def-1123456789ab';
    private const BATCH = '01912345-6789-7abc-8def-1123456789ab';
    private bool $allowPurchases = false;
    private bool $active = true;

    #[DataProvider('permissionTransitions')]
    public function testReadPermissionChangeRequiresBootstrapBeforeScanning(
        bool $initial,
        bool $current,
    ): void {
        $this->allowPurchases = $initial;
        $store = $this->createMock(SyncStore::class);
        $store->method('highWater')->willReturn(7);
        $store->method('captureSnapshotPage')->willReturn(new SyncSnapshotPage(7, [], false));
        $store->expects(self::never())->method('changes');
        $store->expects(self::once())->method('acknowledgeCursor');
        $service = $this->service($store);
        $bootstrap = $service->bootstrap($this->identity(), self::HOME, 'first');
        self::assertIsString($bootstrap['snapshotCursor']);
        $this->allowPurchases = $current;

        $this->assertResyncRequired(fn (): array => $service->pull(
            $this->identity(),
            self::HOME,
            'after-permission-change',
            $bootstrap['snapshotCursor'],
        ));
    }

    /** @return iterable<string, array{bool, bool}> */
    public static function permissionTransitions(): iterable
    {
        yield 'grant must recover previously invisible history' => [false, true];
        yield 'revocation invalidates the previously readable scope' => [true, false];
    }

    #[DataProvider('permissionTransitions')]
    public function testSnapshotContinuationCannotMixReadScopes(bool $initial, bool $current): void
    {
        $this->allowPurchases = $initial;
        $store = $this->createMock(SyncStore::class);
        $store->method('highWater')->willReturn(7);
        $store->expects(self::once())->method('captureSnapshotPage')
            ->willReturn(new SyncSnapshotPage(7, [$this->receipt()], true));
        $store->expects(self::never())->method('acknowledgeCursor');
        $service = $this->service($store);
        $first = $service->bootstrap($this->identity(), self::HOME, 'first');
        self::assertIsString($first['pageCursor']);
        $this->allowPurchases = $current;

        $this->assertResyncRequired(fn (): array => $service->bootstrap(
            $this->identity(),
            self::HOME,
            'continuation',
            $first['pageCursor'],
        ));
    }

    public function testFreshBootstrapAfterGrantIncludesPreviouslyHiddenRecords(): void
    {
        $store = $this->createStub(SyncStore::class);
        $store->method('highWater')->willReturn(7);
        $store->method('captureSnapshotPage')->willReturn(new SyncSnapshotPage(7, [$this->receipt()], false));
        $service = $this->service($store);
        $before = $service->bootstrap($this->identity(), self::HOME, 'before-grant');
        self::assertSame([], $before['records']);
        $this->allowPurchases = true;
        $after = $service->bootstrap($this->identity(), self::HOME, 'after-grant');

        self::assertSame([$this->receipt()], $after['records']);
        self::assertNotSame($before['snapshotCursor'], $after['snapshotCursor']);
    }

    #[DataProvider('foreignReaders')]
    public function testCursorCannotBeTransferredToAnotherAccountOrDevice(
        string $userId,
        string $deviceId,
    ): void {
        $store = $this->createMock(SyncStore::class);
        $store->method('highWater')->willReturn(7);
        $store->method('captureSnapshotPage')->willReturn(new SyncSnapshotPage(7, [], false));
        $store->expects(self::never())->method('changes');
        $service = $this->service($store);
        $first = $service->bootstrap($this->identity(), self::HOME, 'first');
        self::assertIsString($first['snapshotCursor']);
        $other = new AuthenticatedIdentity($userId, $this->identity()->sessionId, $deviceId, null, []);

        $this->assertResyncRequired(fn (): array => $service->pull(
            $other,
            self::HOME,
            'foreign-reader',
            $first['snapshotCursor'],
        ));
    }

    /** @return iterable<string, array{string, string}> */
    public static function foreignReaders(): iterable
    {
        yield 'another account' => [
            '01912345-6789-7abc-9def-2123456789ab',
            '01912345-6789-7abc-bdef-0123456789ab',
        ];
        yield 'another device' => [
            '01912345-6789-7abc-9def-0123456789ab',
            '01912345-6789-7abc-bdef-2123456789ab',
        ];
    }

    public function testSessionRotationOnTheSameAccountAndDevicePreservesCursor(): void
    {
        $store = $this->createMock(SyncStore::class);
        $store->method('highWater')->willReturn(7);
        $store->method('captureSnapshotPage')->willReturn(new SyncSnapshotPage(7, [], false));
        $store->expects(self::once())->method('changes')->willReturn([]);
        $service = $this->service($store);
        $first = $service->bootstrap($this->identity(), self::HOME, 'first');
        self::assertIsString($first['snapshotCursor']);
        $old = $this->identity();
        $rotated = new AuthenticatedIdentity(
            $old->userId,
            '01912345-6789-7abc-adef-2123456789ab',
            $old->deviceId,
            null,
            [],
        );
        $result = $service->pull($rotated, self::HOME, 'rotated', $first['snapshotCursor']);

        self::assertFalse($result['hasMore']);
        self::assertSame([], $result['changes']);
    }

    public function testLegacyUnscopedCursorRequiresNonDestructiveBootstrap(): void
    {
        $store = $this->createMock(SyncStore::class);
        $store->expects(self::never())->method('changes');
        $store->expects(self::never())->method('acknowledgeCursor');
        $store->expects(self::never())->method('apply');
        $legacy = (new CursorCodec(str_repeat('s', 32), $this->clock(), 3600))->encode(self::HOME, 0, 0);

        $this->assertResyncRequired(fn (): array => $this->service($store)->pull(
            $this->identity(),
            self::HOME,
            'legacy',
            $legacy,
        ));
    }

    public function testPermissionRevokedDuringSnapshotReadPreventsResponseAndAcknowledgement(): void
    {
        $this->allowPurchases = true;
        $store = $this->createMock(SyncStore::class);
        $store->method('highWater')->willReturn(7);
        $store->expects(self::once())->method('captureSnapshotPage')->willReturnCallback(function (): SyncSnapshotPage {
            $this->allowPurchases = false;
            return new SyncSnapshotPage(7, [$this->receipt()], false);
        });
        $store->expects(self::never())->method('acknowledgeCursor');

        $this->assertResyncRequired(fn (): array => $this->service($store)->bootstrap(
            $this->identity(),
            self::HOME,
            'revoked-during-read',
        ));
    }

    public function testPermissionRevokedDuringPullPreventsPayloadAndAcknowledgement(): void
    {
        $this->allowPurchases = true;
        $store = $this->createMock(SyncStore::class);
        $store->method('highWater')->willReturn(7, 8);
        $store->method('captureSnapshotPage')->willReturn(new SyncSnapshotPage(7, [], false));
        $store->expects(self::once())->method('acknowledgeCursor');
        $store->expects(self::once())->method('changes')->willReturnCallback(function (): array {
            $this->allowPurchases = false;
            return [[
                'cursor' => 8,
                'entityType' => 'purchasing-receipt',
                'entityId' => self::ENTITY,
                'operationType' => 'put',
                'revision' => 1,
                'payloadSchemaVersion' => 1,
                'payload' => ['notes' => 'private-receipt'],
                'changedAt' => '2026-09-16T06:00:00+00:00',
            ]];
        });
        $service = $this->service($store);
        $bootstrap = $service->bootstrap($this->identity(), self::HOME, 'first');
        self::assertIsString($bootstrap['snapshotCursor']);

        $this->assertResyncRequired(fn (): array => $service->pull(
            $this->identity(),
            self::HOME,
            'revoked-during-pull',
            $bootstrap['snapshotCursor'],
        ));
    }

    public function testPermissionChangeDuringPushFencesResultWithoutReplayingAcceptedWrite(): void
    {
        $this->allowPurchases = true;
        $store = $this->createMock(SyncStore::class);
        $store->method('highWater')->willReturn(8);
        $store->expects(self::once())->method('apply')->willReturnCallback(function (): array {
            $this->allowPurchases = false;
            return [
                'operationId' => self::OPERATION,
                'status' => 'accepted',
                'entityType' => 'private-note',
                'entityId' => self::ENTITY,
                'serverRevision' => 1,
                'cursor' => 8,
                'payload' => ['body' => 'approved note'],
                'deleted' => false,
            ];
        });
        $store->expects(self::never())->method('acknowledgeCursor');
        $identity = $this->identity();
        $envelope = [
            'protocolVersion' => 1,
            'batchId' => self::BATCH,
            'deviceId' => $identity->deviceId,
            'lastPulledCursor' => null,
            'operations' => [[
                'operationId' => self::OPERATION,
                'entityType' => 'private-note',
                'entityId' => self::ENTITY,
                'operationType' => 'put',
                'baseRevision' => null,
                'clientTimestamp' => '2026-09-16T06:00:00+00:00',
                'payloadSchemaVersion' => 1,
                'payload' => ['body' => 'approved note'],
            ]],
        ];
        $this->assertResyncRequired(fn (): array => $this->service($store)->push(
            $identity,
            self::HOME,
            'changed-during-push',
            self::BATCH,
            $envelope,
        ));
    }

    #[DataProvider('cursorKinds')]
    public function testBothCursorCodecsBindTheSignedReadScope(bool $snapshot): void
    {
        $codec = $snapshot
            ? new SnapshotCursorCodec(str_repeat('s', 32), $this->clock(), 3600)
            : new CursorCodec(str_repeat('s', 32), $this->clock(), 3600);
        $scope = str_repeat('a', 64);
        $cursor = $codec instanceof SnapshotCursorCodec
            ? $codec->encode(self::HOME, 7, 'purchasing-receipt', self::ENTITY, $scope)
            : $codec->encode(self::HOME, 3, 7, $scope);
        $expected = $snapshot
            ? ['highWater' => 7, 'entityType' => 'purchasing-receipt', 'entityId' => self::ENTITY]
            : ['position' => 3, 'highWater' => 7];
        self::assertSame($expected, $codec->decode($cursor, self::HOME, $scope));
        $this->assertResyncRequired(fn (): array => $codec->decode($cursor, self::HOME, str_repeat('b', 64)));
    }

    #[DataProvider('cursorKinds')]
    public function testBothCursorCodecsRejectLegacyScopeWhenAReaderIsRequired(bool $snapshot): void
    {
        $codec = $snapshot
            ? new SnapshotCursorCodec(str_repeat('s', 32), $this->clock(), 3600)
            : new CursorCodec(str_repeat('s', 32), $this->clock(), 3600);
        $legacy = $codec instanceof SnapshotCursorCodec
            ? $codec->encode(self::HOME, 7, 'purchasing-receipt', self::ENTITY)
            : $codec->encode(self::HOME, 3, 7);
        $this->assertResyncRequired(fn (): array => $codec->decode($legacy, self::HOME, str_repeat('a', 64)));
    }

    /** @return iterable<string, array{bool}> */
    public static function cursorKinds(): iterable
    {
        yield 'incremental' => [false];
        yield 'snapshot' => [true];
    }

    public function testMembershipRevokedDuringReceiptLookupDoesNotRevealReceiptExistence(): void
    {
        $store = $this->createStub(SyncStore::class);
        $store->method('operationStatuses')->willReturnCallback(function (): array {
            $this->active = false;
            return [];
        });
        try {
            $this->service($store)->operationStatuses(
                $this->identity(),
                self::HOME,
                $this->identity()->deviceId,
                [self::OPERATION],
            );
            self::fail('Receipt existence must not be disclosed after membership revocation.');
        } catch (Problem $problem) {
            self::assertSame(404, $problem->status);
        }
    }

    public function testScopeIsIndependentOfPermissionOrderDuplicatesAndWriteOnlyPermissions(): void
    {
        $read = [HomePermission::HOME_READ, HomePermission::INVENTORY_READ];
        $reordered = [HomePermission::INVENTORY_READ, HomePermission::HOME_READ, HomePermission::HOME_READ];
        self::assertSame(
            SyncReadPolicy::scope($this->identity(), $read),
            SyncReadPolicy::scope($this->identity(), [...$reordered, HomePermission::INVENTORY_WRITE]),
        );
        self::assertNotSame(
            SyncReadPolicy::scope($this->identity(), $read),
            SyncReadPolicy::scope($this->identity(), [...$read, HomePermission::PURCHASES_READ]),
        );
    }

    /** @param callable(): array<string, mixed> $request */
    private function assertResyncRequired(callable $request): void
    {
        try {
            $request();
            self::fail('A changed or unbound read scope was accepted.');
        } catch (Problem $problem) {
            self::assertSame(410, $problem->status);
            self::assertSame('https://providentia.invalid/problems/sync_resync_required', $problem->type);
        }
    }

    /** @return array<string, mixed> */
    private function receipt(): array
    {
        return [
            'entityType' => 'purchasing-receipt',
            'entityId' => self::ENTITY,
            'revision' => 1,
            'representationSchemaVersion' => 1,
            'representation' => ['notes' => 'private-receipt'],
        ];
    }

    private function service(SyncStore $store): SynchronizationService
    {
        $homes = $this->createStub(HomeStore::class);
        $homes->method('membership')->willReturnCallback(fn (): ?array => $this->active
            ? ['status' => 'active', 'role' => HomeAuthorization::MEMBER] : null);
        $homes->method('permissionDecision')->willReturnCallback(
            fn (string $home, string $role, string $permission): ?bool =>
                $permission === HomePermission::PURCHASES_READ ? $this->allowPurchases : null,
        );
        $cursors = new CursorCodec(str_repeat('s', 32), $this->clock(), 3600);
        return new SynchronizationService(
            $store,
            $cursors,
            new HomeAuthorization($homes, AccessFixture::create()),
            $this->clock(),
            new SyncEnvelopeValidator(100),
            new SyncOperationValidator(new SyncEntityPolicyRegistry([
                new PrivateNoteSyncEntityPolicy(), new HomePreferenceSyncEntityPolicy(),
            ]), 65536),
            new SyncRequestHasher(),
            new SyncResultPresenter($cursors),
            2,
            snapshotCursors: new SnapshotCursorCodec(str_repeat('s', 32), $this->clock(), 3600),
        );
    }

    private function clock(): FixedClock
    {
        return new FixedClock(new DateTimeImmutable('2026-09-16T06:00:00+00:00'));
    }

    private function identity(): AuthenticatedIdentity
    {
        return new AuthenticatedIdentity(
            '01912345-6789-7abc-9def-0123456789ab',
            '01912345-6789-7abc-adef-0123456789ab',
            '01912345-6789-7abc-bdef-0123456789ab',
            null,
            [],
        );
    }
}
