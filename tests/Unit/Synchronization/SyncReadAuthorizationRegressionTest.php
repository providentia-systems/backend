<?php

declare(strict_types=1);

namespace ProvidentiaTest\Unit\Synchronization;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Providentia\Home\Application\HomeAuthorization;
use Providentia\Home\Application\HomeStore;
use Providentia\Identity\Application\AuthenticatedIdentity;
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

/** Service regressions; these are not PHP HTTP / Dart integration evidence. */
final class SyncReadAuthorizationRegressionTest extends TestCase
{
    private const HOME = '01912345-6789-7abc-8def-0123456789ab';
    private const ENTITY = '01912345-6789-7abc-adef-1123456789ab';

    public function testBootstrapDoesNotExposeUnclassifiedEntityTypes(): void
    {
        $store = $this->createStub(SyncStore::class);
        $store->method('highWater')->willReturn(1);
        $store->method('captureSnapshotPage')->willReturn(new SyncSnapshotPage(1, [[
            'entityType' => 'unclassified-private-entity',
            'entityId' => self::ENTITY,
            'revision' => 1,
            'representationSchemaVersion' => 1,
            'representation' => ['id' => self::ENTITY, 'secret' => 'must-not-leave-home'],
        ]], false));

        $response = $this->service($store)->bootstrap($this->identity(), self::HOME, 'bootstrap');

        self::assertSame([], $response['records']);
        self::assertFalse($response['hasMore']);
        self::assertIsString($response['snapshotCursor']);
        self::assertStringNotContainsString('must-not-leave-home', json_encode($response, JSON_THROW_ON_ERROR));
    }

    public function testDeniedOnlyIncrementalPageAdvancesWithoutReturningItsPayload(): void
    {
        $store = $this->createStub(SyncStore::class);
        $store->method('highWater')->willReturn(0, 1);
        $store->method('minimumAvailableCursor')->willReturn(0);
        $store->method('captureSnapshotPage')->willReturn(new SyncSnapshotPage(0, [], false));
        $store->method('changes')->willReturn([[
            'cursor' => 1,
            'entityType' => 'unclassified-private-entity',
            'entityId' => self::ENTITY,
            'operationType' => 'put',
            'revision' => 1,
            'payloadSchemaVersion' => 1,
            'payload' => ['secret' => 'must-not-leave-home'],
            'changedAt' => '2026-09-14T12:00:00+00:00',
        ]]);
        $service = $this->service($store);
        $bootstrap = $service->bootstrap($this->identity(), self::HOME, 'bootstrap');
        self::assertIsString($bootstrap['snapshotCursor']);

        $response = $service->pull($this->identity(), self::HOME, 'pull', $bootstrap['snapshotCursor']);

        self::assertSame([], $response['changes']);
        self::assertFalse($response['hasMore']);
        self::assertNotSame($bootstrap['snapshotCursor'], $response['pageCursor']);
        self::assertStringNotContainsString('must-not-leave-home', json_encode($response, JSON_THROW_ON_ERROR));
    }

    public function testEmptyScanAtHighWaterDoesNotCreateAnInfinitePullLoop(): void
    {
        $store = $this->createStub(SyncStore::class);
        $store->method('highWater')->willReturn(0, 5);
        $store->method('minimumAvailableCursor')->willReturn(0);
        $store->method('captureSnapshotPage')->willReturn(new SyncSnapshotPage(0, [], false));
        $store->method('changes')->willReturn([]);
        $service = $this->service($store);
        $bootstrap = $service->bootstrap($this->identity(), self::HOME, 'bootstrap');
        self::assertIsString($bootstrap['snapshotCursor']);

        $response = $service->pull($this->identity(), self::HOME, 'pull', $bootstrap['snapshotCursor']);

        self::assertSame([], $response['changes']);
        self::assertFalse($response['hasMore']);
        self::assertSame($response['highWaterCursor'], $response['pageCursor']);
    }

    private function service(SyncStore $store): SynchronizationService
    {
        $homes = $this->createStub(HomeStore::class);
        $homes->method('membership')->willReturn(['status' => 'active', 'role' => HomeAuthorization::MEMBER]);
        $clock = new FixedClock(new DateTimeImmutable('2026-09-14T12:00:00+00:00'));
        $cursors = new CursorCodec(str_repeat('s', 32), $clock, 3600);

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
            AccessFixture::administratorPermissions([]),
        );
    }
}
