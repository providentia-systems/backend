<?php

declare(strict_types=1);

namespace ProvidentiaTest\Unit\Synchronization;

use DateTimeImmutable;
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
use Providentia\Synchronization\Application\SyncRequestHasher;
use Providentia\Synchronization\Application\SyncResultPresenter;
use Providentia\Synchronization\Application\SyncSnapshotPage;
use Providentia\Synchronization\Application\SyncStore;
use Providentia\Synchronization\Application\SynchronizationService;
use ProvidentiaTest\Support\AccessFixture;

/** Real authorization/service boundary; this is not HTTP or database acceptance. */
final class RestrictedSyncReadTest extends TestCase
{
    private const HOME = '01912345-6789-7abc-8def-0123456789ab';
    private const ENTITY = '01912345-6789-7abc-adef-1123456789ab';
    private const OPERATION = '01912345-6789-7abc-bdef-2123456789ab';
    private bool $allowPurchases = false;

    public function testOrdinaryAuthorizationBootstrapAndMixedPullHonorTheSameDeniedPermission(): void
    {
        $homes = $this->homes();
        $authorization = new HomeAuthorization($homes, AccessFixture::create());
        try {
            $authorization->requirePermission($this->identity(), self::HOME, HomePermission::PURCHASES_READ);
            self::fail('Purchases must be denied for this member.');
        } catch (Problem $problem) {
            self::assertSame(404, $problem->status);
        }
        $location = [
            'entityType' => 'inventory-location',
            'entityId' => self::ENTITY,
            'revision' => 1,
            'representationSchemaVersion' => 1,
            'representation' => ['name' => 'Pantry'],
        ];
        $receipt = array_replace($location, [
            'entityType' => 'purchasing-receipt',
            'representation' => ['notes' => 'denied-private-receipt'],
        ]);
        $store = $this->createStub(SyncStore::class);
        $store->method('highWater')->willReturn(2);
        $store->method('minimumAvailableCursor')->willReturn(0);
        $store->method('captureSnapshotPage')->willReturn(new SyncSnapshotPage(2, [$location, $receipt], false));
        $store->method('changes')->willReturn(array_map(
            static fn (array $record, int $cursor): array => [
                ...$record,
                'cursor' => $cursor,
                'operationType' => 'put',
                'payloadSchemaVersion' => 1,
                'payload' => $record['representation'],
                'changedAt' => '2026-09-14T12:00:00+00:00',
            ],
            [$location, $receipt],
            [1, 2],
        ));
        $service = $this->service($store, $homes);
        $bootstrap = $service->bootstrap($this->identity(), self::HOME, 'bootstrap');
        self::assertSame([$location], $bootstrap['records']);
        $cursors = $this->cursors();
        $pull = $service->pull($this->identity(), self::HOME, 'pull', $cursors->encode(self::HOME, 0, 0));
        self::assertCount(1, $pull['changes']);
        self::assertSame('inventory-location', $pull['changes'][0]['entityType']);
        self::assertSame(2, $cursors->decode($pull['pageCursor'], self::HOME)['position']);
        self::assertFalse($pull['hasMore']);
        self::assertStringNotContainsString(
            'denied-private-receipt',
            json_encode([$bootstrap, $pull], JSON_THROW_ON_ERROR),
        );
    }

    public function testReceiptRecoveryRechecksReadPermissionWithoutChangingAcceptedOutcome(): void
    {
        $result = [
            'operationId' => self::OPERATION,
            'status' => 'accepted',
            'commandType' => 'purchasing.receipt.commit',
            'entityId' => self::ENTITY,
            'result' => ['notes' => 'private-receipt'],
        ];
        $store = $this->createStub(SyncStore::class);
        $store->method('operationStatuses')->willReturn([self::OPERATION => $result]);
        $service = $this->service($store, $this->homes());
        $identity = $this->identity();
        $this->allowPurchases = true;
        $allowed = $service->operationStatuses($identity, self::HOME, $identity->deviceId, [self::OPERATION]);
        self::assertSame($result, $allowed['operations'][0]['result']);
        $this->allowPurchases = false;
        $denied = $service->operationStatuses($identity, self::HOME, $identity->deviceId, [self::OPERATION]);
        self::assertTrue($denied['operations'][0]['known']);
        self::assertSame(
            ['operationId' => self::OPERATION, 'status' => 'accepted'],
            $denied['operations'][0]['result'],
        );
    }

    public function testPlatformAdministratorCannotBootstrapAnotherHomeWithoutMembership(): void
    {
        $homes = $this->createStub(HomeStore::class);
        $homes->method('membership')->willReturn(null);
        $store = $this->createMock(SyncStore::class);
        $store->expects(self::never())->method('captureSnapshotPage');
        $identity = $this->identity();
        $administrator = new AuthenticatedIdentity(
            $identity->userId,
            $identity->sessionId,
            $identity->deviceId,
            null,
            ['platform_administrator'],
            AccessFixture::administratorPermissions(['platform_administrator']),
        );
        $this->expectException(Problem::class);
        $this->service($store, $homes)->bootstrap($administrator, self::HOME, 'bootstrap');
    }

    private function homes(): HomeStore
    {
        $homes = $this->createStub(HomeStore::class);
        $homes->method('membership')->willReturn(['status' => 'active', 'role' => HomeAuthorization::MEMBER]);
        $homes->method('permissionDecision')->willReturnCallback(
            fn (string $home, string $role, string $permission): ?bool =>
                $permission === HomePermission::PURCHASES_READ ? $this->allowPurchases : null,
        );
        return $homes;
    }

    private function cursors(): CursorCodec
    {
        return new CursorCodec(
            str_repeat('s', 32),
            new FixedClock(new DateTimeImmutable('2026-09-14T12:00:00+00:00')),
            3600,
        );
    }

    private function service(SyncStore $store, HomeStore $homes): SynchronizationService
    {
        $clock = new FixedClock(new DateTimeImmutable('2026-09-14T12:00:00+00:00'));
        $cursors = $this->cursors();
        return new SynchronizationService(
            $store,
            $cursors,
            new HomeAuthorization($homes, AccessFixture::create()),
            $clock,
            new SyncEnvelopeValidator(100),
            new SyncOperationValidator(new SyncEntityPolicyRegistry([
                new PrivateNoteSyncEntityPolicy(),
                new HomePreferenceSyncEntityPolicy(),
            ]), 65536),
            new SyncRequestHasher(),
            new SyncResultPresenter($cursors),
            2,
            snapshotCursors: new SnapshotCursorCodec(str_repeat('s', 32), $clock, 3600),
        );
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
