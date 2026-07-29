<?php

declare(strict_types=1);

namespace ProvidentiaTest\Unit\Synchronization;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Providentia\Home\Application\HomeAuthorization;
use Providentia\Home\Application\HomeStore;
use Providentia\Identity\Application\AuthenticatedIdentity;
use Providentia\SharedKernel\Http\HttpProblem;
use Providentia\Synchronization\Application\CursorCodec;
use Providentia\Synchronization\Application\SynchronizationService;
use Providentia\Synchronization\Application\SyncStore;

final class SynchronizationServiceTest extends TestCase
{
    private const HOME_ID = '01912345-6789-7abc-8def-0123456789ab';
    private const USER_ID = '01912345-6789-7abc-9def-0123456789ab';
    private const SESSION_ID = '01912345-6789-7abc-adef-0123456789ab';
    private const DEVICE_ID = '01912345-6789-7abc-bdef-0123456789ab';
    private const BATCH_ID = '01912345-6789-7abc-8def-1123456789ab';
    private const OPERATION_ID = '01912345-6789-7abc-9def-1123456789ab';
    private const ENTITY_ID = '01912345-6789-7abc-adef-1123456789ab';

    public function testSessionDeviceBindingIsEnforcedBeforeApplyingOperations(): void
    {
        $store = $this->createMock(SyncStore::class);
        $store->expects(self::never())->method('apply');
        $service = $this->service($store, HomeAuthorization::MEMBER);
        $envelope = $this->envelope();
        $envelope['deviceId'] = '01912345-6789-7abc-bdef-1123456789ab';

        try {
            $service->push($this->identity(), self::HOME_ID, 'request-1', self::BATCH_ID, $envelope);
            self::fail('An operation from another device was accepted.');
        } catch (HttpProblem $problem) {
            self::assertSame(403, $problem->status);
        }
    }

    public function testViewerReceivesPerOperationAuthorizationFailureWithoutPersistence(): void
    {
        $store = $this->createMock(SyncStore::class);
        $store->expects(self::never())->method('apply');
        $store->method('highWater')->willReturn(0);
        $service = $this->service($store, HomeAuthorization::VIEWER);

        $response = $service->push(
            $this->identity(),
            self::HOME_ID,
            'request-2',
            self::BATCH_ID,
            $this->envelope(),
        );
        $result = $this->firstRow($response, 'results');

        self::assertSame('authorization_failure', $result['status']);
        self::assertSame(self::OPERATION_ID, $result['operationId']);
    }

    public function testAcceptedOperationReturnsOpaqueCursorAndServerRevision(): void
    {
        $store = $this->createMock(SyncStore::class);
        $store->expects(self::once())
            ->method('apply')
            ->with(
                self::HOME_ID,
                self::USER_ID,
                self::DEVICE_ID,
                self::isType('array'),
                self::matchesRegularExpression('/^[0-9a-f]{64}$/'),
                self::isInstanceOf(DateTimeImmutable::class),
            )
            ->willReturn([
                'operationId' => self::OPERATION_ID,
                'status' => 'accepted',
                'entityType' => 'private-note',
                'entityId' => self::ENTITY_ID,
                'serverRevision' => 1,
                'cursor' => 7,
                'payload' => ['body' => 'freezer'],
                'deleted' => false,
            ]);
        $store->method('highWater')->willReturn(7);
        $service = $this->service($store, HomeAuthorization::MEMBER);

        $response = $service->push(
            $this->identity(),
            self::HOME_ID,
            'request-3',
            self::BATCH_ID,
            $this->envelope(),
        );
        $result = $this->firstRow($response, 'results');
        $representation = $result['representation'] ?? null;
        if (! is_array($representation)) {
            self::fail('The accepted representation is missing.');
        }

        self::assertSame('accepted', $result['status']);
        self::assertSame(1, $result['revision']);
        self::assertSame('freezer', $representation['body']);
        self::assertIsString($result['changeCursor']);
        self::assertArrayNotHasKey('payload', $result);
    }

    public function testFirstIncrementalPullRequiresAuthorizedBootstrap(): void
    {
        $store = $this->createStub(SyncStore::class);
        $service = $this->service($store, HomeAuthorization::MEMBER);

        try {
            $service->pull($this->identity(), self::HOME_ID, 'request-4', null);
            self::fail('Incremental synchronization started without a bootstrap cursor.');
        } catch (HttpProblem $problem) {
            self::assertSame(410, $problem->status);
            self::assertSame(
                'https://providentia.invalid/problems/sync_resync_required',
                $problem->type,
            );
        }
    }

    public function testNewServerChangeIsVisibleAfterACompletedCursorWindow(): void
    {
        $store = $this->createMock(SyncStore::class);
        $store->expects(self::once())
            ->method('highWater')
            ->with(self::HOME_ID)
            ->willReturn(2);
        $store->expects(self::once())
            ->method('changes')
            ->with(self::HOME_ID, 1, 2, 250)
            ->willReturn([[
                'cursor' => 2,
                'entityType' => 'private-note',
                'entityId' => self::ENTITY_ID,
                'operationType' => 'put',
                'revision' => 2,
                'payloadSchemaVersion' => 1,
                'payload' => ['body' => 'new server value'],
                'changedAt' => '2026-07-30 12:00:00',
            ]]);
        $store->expects(self::once())
            ->method('acknowledgeCursor')
            ->with(
                self::HOME_ID,
                self::USER_ID,
                self::DEVICE_ID,
                2,
                self::isInstanceOf(DateTimeImmutable::class),
            );
        $service = $this->service($store, HomeAuthorization::MEMBER);
        $clock = new FixedClock(new DateTimeImmutable('2026-07-30T12:00:00+00:00'));
        $completedCursor = (new CursorCodec(str_repeat('s', 32), $clock, 3600))
            ->encode(self::HOME_ID, 1, 1);

        $response = $service->pull(
            $this->identity(),
            self::HOME_ID,
            'request-5',
            $completedCursor,
        );
        $change = $this->firstRow($response, 'changes');
        $representation = $change['representation'] ?? null;
        if (! is_array($representation)) {
            self::fail('The pulled representation is missing.');
        }

        self::assertFalse($response['hasMore']);
        self::assertCount(1, $this->rows($response, 'changes'));
        self::assertSame('new server value', $representation['body']);
    }

    public function testEnabledEntityPayloadsRejectUnknownFieldsInvalidTypesAndNonEmptyDeletes(): void
    {
        $store = $this->createMock(SyncStore::class);
        $store->expects(self::never())->method('apply');
        $store->method('highWater')->willReturn(0);
        $service = $this->service($store, HomeAuthorization::MEMBER);
        $envelope = $this->envelope();
        $operations = $envelope['operations'] ?? null;
        $base = is_array($operations) ? ($operations[0] ?? null) : null;
        if (! is_array($base)) {
            self::fail('The synchronization test fixture is invalid.');
        }
        $envelope['operations'] = [
            array_merge($base, [
                'operationId' => '01912345-6789-7abc-8def-3123456789ab',
                'payload' => ['body' => 'valid', 'revision' => 99],
            ]),
            array_merge($base, [
                'operationId' => '01912345-6789-7abc-9def-3123456789ab',
                'payload' => ['body' => str_repeat('x', 4001)],
            ]),
            array_merge($base, [
                'operationId' => '01912345-6789-7abc-adef-3123456789ab',
                'entityType' => 'home-preference',
                'payload' => ['defaultCurrency' => 'nad'],
            ]),
            array_merge($base, [
                'operationId' => '01912345-6789-7abc-bdef-3123456789ab',
                'operationType' => 'delete',
                'payload' => ['body' => 'must not survive delete'],
            ]),
        ];

        $response = $service->push(
            $this->identity(),
            self::HOME_ID,
            'request-6',
            self::BATCH_ID,
            $envelope,
        );
        $results = $this->rows($response, 'results');

        self::assertSame(
            ['validation_error', 'validation_error', 'validation_error', 'validation_error'],
            array_column($results, 'status'),
        );
    }

    public function testPushRejectsUnknownEnvelopeKeysBeforeApplyingOperations(): void
    {
        $store = $this->createMock(SyncStore::class);
        $store->expects(self::never())->method('apply');
        $service = $this->service($store, HomeAuthorization::MEMBER);
        $envelope = $this->envelope();
        $envelope['serverOwnedScope'] = self::HOME_ID;

        try {
            $service->push(
                $this->identity(),
                self::HOME_ID,
                'request-7',
                self::BATCH_ID,
                $envelope,
            );
            self::fail('An envelope containing a field outside its closed schema was accepted.');
        } catch (HttpProblem $problem) {
            self::assertSame(422, $problem->status);
            self::assertStringContainsString('serverOwnedScope', $problem->getMessage());
        }
    }

    public function testPushReportsUnknownOperationKeysAsValidationErrors(): void
    {
        $store = $this->createMock(SyncStore::class);
        $store->expects(self::never())->method('apply');
        $store->method('highWater')->willReturn(0);
        $service = $this->service($store, HomeAuthorization::MEMBER);
        $envelope = $this->envelope();
        $envelope['operations'][0]['serverRevision'] = 99;

        $response = $service->push(
            $this->identity(),
            self::HOME_ID,
            'request-8',
            self::BATCH_ID,
            $envelope,
        );
        $result = $this->firstRow($response, 'results');

        self::assertSame('validation_error', $result['status']);
        self::assertStringContainsString('serverRevision', (string) $result['detail']);
    }

    public function testPrivateNoteBodyUsesTheContractLengthSemantics(): void
    {
        $store = $this->createMock(SyncStore::class);
        $store->expects(self::once())->method('apply')->willReturn([
            'operationId' => self::OPERATION_ID,
            'status' => 'accepted',
            'entityType' => 'private-note',
            'entityId' => self::ENTITY_ID,
            'serverRevision' => 1,
            'cursor' => 1,
            'payload' => ['body' => ' '],
            'deleted' => false,
        ]);
        $store->method('highWater')->willReturn(1);
        $service = $this->service($store, HomeAuthorization::MEMBER);
        $envelope = $this->envelope();
        $envelope['operations'][0]['payload'] = ['body' => ' '];

        $response = $service->push(
            $this->identity(),
            self::HOME_ID,
            'request-9',
            self::BATCH_ID,
            $envelope,
        );

        self::assertSame('accepted', $this->firstRow($response, 'results')['status']);
    }

    private function service(SyncStore $syncStore, string $role): SynchronizationService
    {
        $homeStore = $this->createStub(HomeStore::class);
        $homeStore->method('membership')->willReturn(['status' => 'active', 'role' => $role]);
        $clock = new FixedClock(new DateTimeImmutable('2026-07-30T12:00:00+00:00'));

        return new SynchronizationService(
            $syncStore,
            new CursorCodec(str_repeat('s', 32), $clock, 3600),
            new HomeAuthorization($homeStore),
            $clock,
            100,
            65536,
            250,
        );
    }

    /** @return array<string, mixed> */
    private function envelope(): array
    {
        return [
            'protocolVersion' => 1,
            'batchId' => self::BATCH_ID,
            'deviceId' => self::DEVICE_ID,
            'lastPulledCursor' => null,
            'operations' => [[
                'operationId' => self::OPERATION_ID,
                'entityType' => 'private-note',
                'entityId' => self::ENTITY_ID,
                'operationType' => 'put',
                'baseRevision' => null,
                'clientTimestamp' => '2026-07-30T11:59:00+00:00',
                'payloadSchemaVersion' => 1,
                'payload' => ['body' => 'freezer'],
            ]],
        ];
    }

    private function identity(): AuthenticatedIdentity
    {
        return new AuthenticatedIdentity(
            self::USER_ID,
            self::SESSION_ID,
            self::DEVICE_ID,
            null,
            [],
        );
    }

    /**
     * @param array<string, mixed> $response
     * @return list<array<string, mixed>>
     */
    private function rows(array $response, string $key): array
    {
        $rows = $response[$key] ?? null;
        if (! is_array($rows) || ! array_is_list($rows)) {
            self::fail('Expected response list: ' . $key);
        }
        foreach ($rows as $row) {
            if (! is_array($row)) {
                self::fail('Expected an object in response list: ' . $key);
            }
        }

        /** @var list<array<string, mixed>> $rows */
        return $rows;
    }

    /**
     * @param array<string, mixed> $response
     * @return array<string, mixed>
     */
    private function firstRow(array $response, string $key): array
    {
        $row = $this->rows($response, $key)[0] ?? null;
        if (! is_array($row)) {
            self::fail('Expected a non-empty response list: ' . $key);
        }

        return $row;
    }
}
