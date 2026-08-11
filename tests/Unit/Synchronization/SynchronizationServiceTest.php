<?php

declare(strict_types=1);

namespace ProvidentiaTest\Unit\Synchronization;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Providentia\Home\Application\HomeAuthorization;
use Providentia\Home\Application\HomeStore;
use Providentia\Identity\Application\AuthenticatedIdentity;
use Providentia\SharedKernel\Application\Problem;
use Providentia\SharedKernel\Application\TransactionManager;
use Providentia\Synchronization\Application\CursorCodec;
use Providentia\Synchronization\Application\HomePreferenceSyncEntityPolicy;
use Providentia\Synchronization\Application\PrivateNoteSyncEntityPolicy;
use Providentia\Synchronization\Application\SnapshotCursorCodec;
use Providentia\Synchronization\Application\SyncCommand;
use Providentia\Synchronization\Application\SyncCommandDispatcher;
use Providentia\Synchronization\Application\SyncCommandHasher;
use Providentia\Synchronization\Application\SyncCommandValidator;
use Providentia\Synchronization\Application\SyncEntityPolicyRegistry;
use Providentia\Synchronization\Application\SyncEnvelopeValidator;
use Providentia\Synchronization\Application\SyncOperation;
use Providentia\Synchronization\Application\SyncOperationValidator;
use Providentia\Synchronization\Application\SyncRequestHasher;
use Providentia\Synchronization\Application\SyncResultPresenter;
use Providentia\Synchronization\Application\SyncSnapshotPage;
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
    private const OTHER_HOME_ID = '01912345-6789-7abc-bdef-2123456789ab';

    /** @param array{status: string, role: string}|null $membership */
    #[DataProvider('concealedMembershipProvider')]
    public function testPrivateSynchronizationEndpointsConcealNonMembership(
        ?array $membership,
        string $membershipHomeId,
    ): void {
        $store = $this->createMock(SyncStore::class);
        $store->expects(self::never())->method('highWater');
        $store->expects(self::never())->method('changes');
        $store->expects(self::never())->method('captureSnapshotPage');
        $store->expects(self::never())->method('apply');
        $store->expects(self::never())->method('operationStatuses');
        $service = $this->serviceWithMembership(
            $store,
            $membership,
            $membershipHomeId,
        );
        $requests = [
            'bootstrap' => fn (): array => $service->bootstrap(
                $this->identity(),
                self::HOME_ID,
                'concealed-bootstrap',
            ),
            'pull' => fn (): array => $service->pull(
                $this->identity(),
                self::HOME_ID,
                'concealed-pull',
                null,
            ),
            'push' => fn (): array => $service->push(
                $this->identity(),
                self::HOME_ID,
                'concealed-push',
                self::BATCH_ID,
                $this->envelope(),
            ),
            'operation-status' => fn (): array => $service->operationStatuses(
                $this->identity(),
                self::HOME_ID,
                self::DEVICE_ID,
                [self::OPERATION_ID],
            ),
        ];

        foreach ($requests as $endpoint => $request) {
            try {
                $representation = $request();
                self::fail(sprintf(
                    '%s returned a private representation to a non-member: %s',
                    $endpoint,
                    json_encode($representation, JSON_THROW_ON_ERROR),
                ));
            } catch (Problem $problem) {
                self::assertSame(404, $problem->status, $endpoint);
                self::assertNotSame(403, $problem->status, $endpoint);
                self::assertSame('Not found', $problem->title, $endpoint);
                self::assertSame(
                    'The requested resource is unavailable.',
                    $problem->getMessage(),
                    $endpoint,
                );
            }
        }
    }

    /**
     * @return iterable<string, array{0: array{status: string, role: string}|null, 1: string}>
     */
    public static function concealedMembershipProvider(): iterable
    {
        yield 'absent membership' => [null, self::HOME_ID];
        yield 'revoked membership' => [[
            'status' => 'revoked',
            'role' => HomeAuthorization::MEMBER,
        ], self::HOME_ID];
        yield 'active membership in a foreign home' => [[
            'status' => 'active',
            'role' => HomeAuthorization::MEMBER,
        ], self::OTHER_HOME_ID];
    }

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
        } catch (Problem $problem) {
            self::assertSame(403, $problem->status);
        }
    }

    public function testOperationStatusDeviceMismatchRemainsAnExplicitForbiddenError(): void
    {
        $store = $this->createMock(SyncStore::class);
        $store->expects(self::never())->method('operationStatuses');
        $service = $this->service($store, HomeAuthorization::MEMBER);

        try {
            $service->operationStatuses(
                $this->identity(),
                self::HOME_ID,
                '01912345-6789-7abc-cdef-2123456789ab',
                [self::OPERATION_ID],
            );
            self::fail('A member queried operation receipts for another device.');
        } catch (Problem $problem) {
            self::assertSame(403, $problem->status);
            self::assertSame('Device mismatch', $problem->title);
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
                self::isInstanceOf(SyncOperation::class),
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
        } catch (Problem $problem) {
            self::assertSame(410, $problem->status);
            self::assertSame(
                'https://providentia.invalid/problems/sync_resync_required',
                $problem->type,
            );
        }
    }

    public function testPullBehindTheCompactedHistoryBoundaryRequiresAFullBootstrap(): void
    {
        $store = $this->createMock(SyncStore::class);
        $store->expects(self::once())
            ->method('minimumAvailableCursor')
            ->with(self::HOME_ID)
            ->willReturn(2);
        $store->expects(self::never())->method('changes');
        $service = $this->service($store, HomeAuthorization::MEMBER);
        $clock = new FixedClock(new DateTimeImmutable('2026-07-30T12:00:00+00:00'));
        $staleCursor = (new CursorCodec(str_repeat('s', 32), $clock, 3600))
            ->encode(self::HOME_ID, 1, 1);

        try {
            $service->pull($this->identity(), self::HOME_ID, 'request-stale', $staleCursor);
            self::fail('A client behind compacted synchronization history was allowed to pull.');
        } catch (Problem $problem) {
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
        } catch (Problem $problem) {
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

    public function testBootstrapUsesOneCapturedSnapshotAndAcknowledgesItsBoundary(): void
    {
        $record = [
            'entityType' => 'private-note',
            'entityId' => self::ENTITY_ID,
            'revision' => 2,
            'representationSchemaVersion' => 1,
            'representation' => [
                'id' => self::ENTITY_ID,
                'revision' => 2,
                'body' => 'freezer',
            ],
            'serverTimestamp' => '2026-07-30 12:00:00',
        ];
        $store = $this->createMock(SyncStore::class);
        $store->expects(self::once())->method('highWater')->with(self::HOME_ID)->willReturn(7);
        $store->expects(self::once())
            ->method('captureSnapshotPage')
            ->with(self::HOME_ID, 7, null, null, 250)
            ->willReturn(new SyncSnapshotPage(7, [$record], false));
        $store->expects(self::once())
            ->method('acknowledgeCursor')
            ->with(
                self::HOME_ID,
                self::USER_ID,
                self::DEVICE_ID,
                7,
                self::isInstanceOf(DateTimeImmutable::class),
            );
        $service = $this->service($store, HomeAuthorization::MEMBER);

        $response = $service->bootstrap(
            $this->identity(),
            self::HOME_ID,
            'request-bootstrap',
        );

        self::assertSame([$record], $response['records']);
        self::assertIsString($response['snapshotCursor']);
        self::assertFalse($response['hasMore']);
    }

    public function testProtocolTwoDispatchesAValidatedPantryCommandAndStoresItsReceipt(): void
    {
        $store = $this->createMock(SyncStore::class);
        $store->method('operationReceipt')->willReturn(null);
        $store->method('highWater')->willReturn(0);
        $store->expects(self::once())
            ->method('recordCommandReceipt')
            ->with(
                self::HOME_ID,
                self::USER_ID,
                self::DEVICE_ID,
                self::isInstanceOf(SyncCommand::class),
                self::matchesRegularExpression('/^[0-9a-f]{64}$/'),
                self::callback(static fn (array $response): bool => $response['status'] === 'accepted'),
                self::isInstanceOf(DateTimeImmutable::class),
            );
        $dispatcher = $this->createMock(SyncCommandDispatcher::class);
        $dispatcher->expects(self::once())
            ->method('dispatch')
            ->with(
                self::isInstanceOf(AuthenticatedIdentity::class),
                self::HOME_ID,
                self::callback(
                    static fn (SyncCommand $command): bool =>
                        $command->commandType === 'shopping.list.create'
                        && $command->entityId === self::ENTITY_ID,
                ),
            )
            ->willReturn(['id' => self::ENTITY_ID, 'revision' => 1]);
        $service = $this->service($store, HomeAuthorization::MEMBER, $dispatcher);
        $envelope = [
            'protocolVersion' => 2,
            'batchId' => self::BATCH_ID,
            'deviceId' => self::DEVICE_ID,
            'lastPulledCursor' => null,
            'operations' => [[
                'operationId' => self::OPERATION_ID,
                'commandType' => 'shopping.list.create',
                'entityId' => self::ENTITY_ID,
                'baseRevision' => null,
                'clientTimestamp' => '2026-07-30T11:59:00+00:00',
                'payloadSchemaVersion' => 1,
                'payload' => ['name' => 'Weekly', 'kind' => 'manual'],
            ]],
        ];

        $response = $service->push(
            $this->identity(),
            self::HOME_ID,
            'request-v2',
            self::BATCH_ID,
            $envelope,
        );

        self::assertSame(2, $response['protocolVersion']);
        self::assertSame('accepted', $this->firstRow($response, 'results')['status']);
    }

    public function testLostResponseStatusRecoveryIsScopedAndPreservesUnknownOperations(): void
    {
        $unknown = '01912345-6789-7abc-bdef-4123456789ab';
        $storedResult = [
            'operationId' => self::OPERATION_ID,
            'status' => 'accepted',
            'entityId' => self::ENTITY_ID,
        ];
        $store = $this->createMock(SyncStore::class);
        $store->expects(self::once())
            ->method('operationStatuses')
            ->with(
                self::HOME_ID,
                self::USER_ID,
                self::DEVICE_ID,
                [self::OPERATION_ID, $unknown],
            )
            ->willReturn([self::OPERATION_ID => $storedResult]);
        $service = $this->service($store, HomeAuthorization::MEMBER);

        $response = $service->operationStatuses(
            $this->identity(),
            self::HOME_ID,
            self::DEVICE_ID,
            [self::OPERATION_ID, $unknown],
        );

        self::assertTrue($response['operations'][0]['known']);
        self::assertSame($storedResult, $response['operations'][0]['result']);
        self::assertFalse($response['operations'][1]['known']);
    }

    public function testUnresolvedReceiptCommandReplayReturnsImmutableReceiptWithoutRedispatch(): void
    {
        $lineId = self::ENTITY_ID;
        $storedReceipt = null;
        $store = $this->createMock(SyncStore::class);
        $store->method('operationReceipt')
            ->with(self::OPERATION_ID)
            ->willReturnCallback(static function () use (&$storedReceipt): ?array {
                return $storedReceipt;
            });
        $store->expects(self::once())
            ->method('recordCommandReceipt')
            ->willReturnCallback(
                static function (
                    string $homeId,
                    string $userId,
                    string $deviceId,
                    SyncCommand $_command,
                    string $requestHash,
                    array $response,
                ) use (&$storedReceipt): void {
                    $storedReceipt = [
                        'homeId' => $homeId,
                        'userId' => $userId,
                        'deviceId' => $deviceId,
                        'requestHash' => $requestHash,
                        'response' => $response,
                    ];
                },
            );
        $store->expects(self::exactly(2))->method('highWater')->willReturn(0);
        $dispatcher = $this->createMock(SyncCommandDispatcher::class);
        $dispatcher->expects(self::once())
            ->method('dispatch')
            ->with(
                self::isInstanceOf(AuthenticatedIdentity::class),
                self::HOME_ID,
                self::callback(
                    static fn (SyncCommand $command): bool =>
                        $command->commandType === 'purchasing.receipt-line.unresolve'
                        && $command->entityId === $lineId
                        && $command->baseRevision === 2,
                ),
            )
            ->willReturn(['id' => $lineId, 'revision' => 3, 'approvalStatus' => 'unresolved']);
        $service = $this->service($store, HomeAuthorization::MEMBER, $dispatcher);
        $envelope = [
            'protocolVersion' => 2,
            'batchId' => self::BATCH_ID,
            'deviceId' => self::DEVICE_ID,
            'lastPulledCursor' => null,
            'operations' => [[
                'operationId' => self::OPERATION_ID,
                'commandType' => 'purchasing.receipt-line.unresolve',
                'entityId' => $lineId,
                'baseRevision' => 2,
                'clientTimestamp' => '2026-07-30T11:59:00+00:00',
                'payloadSchemaVersion' => 1,
                'payload' => ['receiptId' => '01912345-6789-7abc-8def-3123456789ab'],
            ]],
        ];

        $first = $service->push(
            $this->identity(),
            self::HOME_ID,
            'unresolve-first',
            self::BATCH_ID,
            $envelope,
        );
        $replayed = $service->push(
            $this->identity(),
            self::HOME_ID,
            'unresolve-lost-response-retry',
            self::BATCH_ID,
            $envelope,
        );

        self::assertSame(
            $this->firstRow($first, 'results'),
            $this->firstRow($replayed, 'results'),
        );
        self::assertSame('accepted', $this->firstRow($first, 'results')['status']);
        self::assertSame('unresolved', $this->firstRow($first, 'results')['result']['approvalStatus']);
    }

    private function service(
        SyncStore $syncStore,
        string $role,
        ?SyncCommandDispatcher $dispatcher = null,
    ): SynchronizationService {
        return $this->serviceWithMembership(
            $syncStore,
            ['status' => 'active', 'role' => $role],
            self::HOME_ID,
            $dispatcher,
        );
    }

    /** @param array{status: string, role: string}|null $membership */
    private function serviceWithMembership(
        SyncStore $syncStore,
        ?array $membership,
        string $membershipHomeId,
        ?SyncCommandDispatcher $dispatcher = null,
    ): SynchronizationService {
        $homeStore = $this->createStub(HomeStore::class);
        $homeStore->method('membership')->willReturnCallback(
            static fn (string $homeId, string $_userId): ?array => $homeId === $membershipHomeId
                ? $membership
                : null,
        );
        $clock = new FixedClock(new DateTimeImmutable('2026-07-30T12:00:00+00:00'));
        $cursors = new CursorCodec(str_repeat('s', 32), $clock, 3600);

        return new SynchronizationService(
            $syncStore,
            $cursors,
            new HomeAuthorization($homeStore),
            $clock,
            new SyncEnvelopeValidator(100),
            new SyncOperationValidator(
                new SyncEntityPolicyRegistry([
                    new PrivateNoteSyncEntityPolicy(),
                    new HomePreferenceSyncEntityPolicy(),
                ]),
                65536,
            ),
            new SyncRequestHasher(),
            new SyncResultPresenter($cursors),
            250,
            new SyncCommandValidator(65536),
            $dispatcher ?? $this->createStub(SyncCommandDispatcher::class),
            new SyncCommandHasher(),
            new ImmediateTransactionManager(),
            new SnapshotCursorCodec(str_repeat('s', 32), $clock, 3600),
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

// phpcs:disable PSR1.Classes.ClassDeclaration.MultipleClasses -- focused test double belongs with this unit.
final class ImmediateTransactionManager implements TransactionManager
{
    public function transactional(callable $operation): mixed
    {
        return $operation();
    }
}
