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
use Providentia\SharedKernel\Application\TransactionManager;
use Providentia\Synchronization\Application\CursorCodec;
use Providentia\Synchronization\Application\PrivateNoteSyncEntityPolicy;
use Providentia\Synchronization\Application\SyncCommandDispatcher;
use Providentia\Synchronization\Application\SyncCommandHasher;
use Providentia\Synchronization\Application\SyncCommandValidator;
use Providentia\Synchronization\Application\SyncDeploymentInformation;
use Providentia\Synchronization\Application\SyncEntityPolicyRegistry;
use Providentia\Synchronization\Application\SyncEnvelopeValidator;
use Providentia\Synchronization\Application\SyncOperationValidator;
use Providentia\Synchronization\Application\SyncProblemClassifier;
use Providentia\Synchronization\Application\SyncRequestHasher;
use Providentia\Synchronization\Application\SyncResultPresenter;
use Providentia\Synchronization\Application\SyncStore;
use Providentia\Synchronization\Application\SynchronizationService;
use ProvidentiaTest\Support\AccessFixture;
use RuntimeException;
use Throwable;

final class SyncRecoveryBoundaryTest extends TestCase
{
    private const HOME = '01912345-6789-7abc-8def-0123456789ab';
    private const USER = '01912345-6789-7abc-9def-0123456789ab';
    private const DEVICE = '01912345-6789-7abc-adef-0123456789ab';
    private const FIRST = '01912345-6789-7abc-bdef-0123456789ab';
    private const SECOND = '01912345-6789-7abc-8def-1123456789ab';
    private const THIRD = '01912345-6789-7abc-9def-1123456789ab';

    #[DataProvider('invalidOperationIds')]
    public function testStatusLookupRejectsMalformedIdentityBeforeReadingReceipts(mixed $value): void
    {
        $store = $this->createMock(SyncStore::class);
        $store->expects(self::never())->method('operationStatuses');
        try {
            $this->service($store)->operationStatuses($this->identity(), self::HOME, self::DEVICE, [$value]);
            self::fail('A malformed receipt identity was queried.');
        } catch (Problem $problem) {
            self::assertSame(422, $problem->status);
            self::assertSame('Invalid identifier', $problem->title);
            self::assertSame('operationId must be a UUID.', $problem->getMessage());
        }
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidOperationIds(): iterable
    {
        yield 'prefix' => ['prefix-' . self::FIRST];
        yield 'suffix' => [self::FIRST . '-suffix'];
        yield 'invalid variant' => [str_replace('bdef', '7def', self::FIRST)];
        yield 'empty' => [''];
        yield 'null' => [null];
        yield 'integer' => [42];
        yield 'boolean' => [false];
        yield 'array' => [[self::FIRST]];
    }

    public function testStatusLookupCanonicalizesUppercaseDeduplicatesAndRetainsKnownAndUnknownIdentity(): void
    {
        $accepted = ['operationId' => self::FIRST, 'status' => 'accepted', 'commandType' => 'shopping.list.create'];
        $store = $this->createMock(SyncStore::class);
        $store->expects(self::once())->method('operationStatuses')
            ->with(self::HOME, self::USER, self::DEVICE, [self::FIRST, self::SECOND])
            ->willReturn([self::FIRST => $accepted]);
        $result = $this->service($store)->operationStatuses($this->identity(), self::HOME, self::DEVICE, [
            strtoupper(self::FIRST), self::FIRST, strtoupper(self::SECOND), self::SECOND,
        ]);
        self::assertSame([
            'protocolVersion' => 2,
            'operations' => [
                ['operationId' => self::FIRST, 'known' => true, 'result' => $accepted],
                ['operationId' => self::SECOND, 'known' => false],
            ],
        ], $result);
    }

    #[DataProvider('commandDomains')]
    public function testRecoveryRequiresTheSpecificCommandDomainsWritePermission(string $type, string $permission): void
    {
        $store = $this->createMock(SyncStore::class);
        $store->method('operationReceipt')->willReturn(null);
        $store->expects(self::never())->method('recordCommandReceipt');
        $command = $this->command(self::FIRST, $type);
        $result = $this->service($store, $permission)->revalidate($this->identity(), self::HOME, $this->request([
            ['blockedCode' => 'device_binding_mismatch', 'command' => $command],
        ]));
        self::assertSame($this->response([
            ['operationId' => self::FIRST, 'action' => 'retry_same_operation',
                'code' => 'unknown_under_original_binding'],
        ]), $result);
    }

    /** @return iterable<string, array{string, string}> */
    public static function commandDomains(): iterable
    {
        yield 'inventory' => ['inventory.location.create', HomePermission::INVENTORY_WRITE];
        yield 'purchasing' => ['purchasing.store.create', HomePermission::PURCHASES_WRITE];
        yield 'shopping' => ['shopping.list.create', HomePermission::SHOPPING_WRITE];
    }

    #[DataProvider('permissionFailures')]
    public function testRecoveryOnlyConvertsPermissionDenialsIntoReviewAndPropagatesOtherFailures(int $status): void
    {
        $problem = new Problem($status, 'Authorization boundary', 'Retain the original queue.');
        $store = $this->createMock(SyncStore::class);
        $store->method('operationReceipt')->willReturn(null);
        $store->expects(self::never())->method('recordCommandReceipt');
        $service = $this->service($store, HomePermission::PURCHASES_WRITE, $problem);
        try {
            $result = $service->revalidate($this->identity(), self::HOME, $this->request([
                ['blockedCode' => 'device_binding_mismatch', 'command' => $this->command(self::FIRST)],
            ]));
            self::assertContains($status, [403, 404], 'A non-permission failure must remain unresolved.');
            self::assertSame($this->response([
                ['operationId' => self::FIRST, 'action' => 'review_required', 'code' => 'permission_denied'],
            ]), $result);
        } catch (Problem $actual) {
            self::assertNotContains($status, [403, 404], 'A confirmed permission denial should require review.');
            self::assertSame($problem, $actual);
        }
    }

    /** @return iterable<string, array{int}> */
    public static function permissionFailures(): iterable
    {
        foreach ([401, 403, 404, 409, 422, 429, 503] as $status) {
            yield 'HTTP ' . $status => [$status];
        }
    }

    #[DataProvider('bindingFailures')]
    public function testWrongOriginalBindingHasExplicitSecurityClassification(string $field, string $type): void
    {
        $request = $this->request([
            ['blockedCode' => 'device_binding_mismatch', 'command' => $this->command(self::FIRST)],
        ]);
        $request[$field] = self::THIRD;
        $store = $this->createMock(SyncStore::class);
        $store->expects(self::never())->method('operationReceipt');
        try {
            $this->service($store)->revalidate($this->identity(), self::HOME, $request);
            self::fail('Recovery crossed an original identity binding.');
        } catch (Problem $problem) {
            self::assertSame(403, $problem->status);
            self::assertSame($type, $problem->type);
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function bindingFailures(): iterable
    {
        yield 'account' => ['accountId', SyncProblemClassifier::ACCOUNT_MISMATCH];
        yield 'device' => ['deviceId', SyncProblemClassifier::DEVICE_MISMATCH];
    }

    #[DataProvider('immutableReceiptFields')]
    public function testEachImmutableReceiptBindingIsCheckedIndependently(string $field): void
    {
        $command = $this->command(self::FIRST);
        $receipt = $this->receipt($command);
        $receipt[$field] = $field === 'requestHash' ? str_repeat('0', 64) : self::THIRD;
        $store = $this->createStub(SyncStore::class);
        $store->method('operationReceipt')->willReturn($receipt);
        $result = $this->service($store)->revalidate($this->identity(), self::HOME, $this->request([
            ['blockedCode' => 'device_binding_mismatch', 'command' => $command],
        ]));
        self::assertSame($this->response([
            ['operationId' => self::FIRST, 'action' => 'review_required', 'code' => 'immutable_request_mismatch'],
        ]), $result);
    }

    /** @return iterable<string, array{string}> */
    public static function immutableReceiptFields(): iterable
    {
        foreach (['homeId', 'userId', 'deviceId', 'requestHash'] as $field) {
            yield $field => [$field];
        }
    }

    public function testRecoveryPreservesFirstBlockerAcrossThreeOperations(): void
    {
        $entries = [];
        foreach ([self::FIRST, self::SECOND, self::THIRD] as $id) {
            $entries[] = ['blockedCode' => 'revision_conflict', 'command' => $this->command($id)];
        }
        $store = $this->createStub(SyncStore::class);
        $store->method('operationReceipt')->willReturn(null);
        $result = $this->service($store)->revalidate($this->identity(), self::HOME, $this->request($entries));
        self::assertSame($this->response([
            ['operationId' => self::FIRST, 'action' => 'review_required', 'code' => 'block_not_eligible'],
            ['operationId' => self::SECOND, 'action' => 'await_predecessor', 'code' => 'dependency_pending',
                'predecessorId' => self::FIRST],
            ['operationId' => self::THIRD, 'action' => 'await_predecessor', 'code' => 'dependency_pending',
                'predecessorId' => self::FIRST],
        ]), $result);
    }

    public function testKnownAcceptedWorkSettlesWithoutBlockingTheNextOriginalUnknownOperation(): void
    {
        $first = $this->command(self::FIRST);
        $receipt = $this->receipt($first);
        $store = $this->createStub(SyncStore::class);
        $store->method('operationReceipt')->willReturnCallback(
            static fn (string $id): ?array => $id === self::FIRST ? $receipt : null,
        );
        $result = $this->service($store)->revalidate($this->identity(), self::HOME, $this->request([
            ['blockedCode' => 'device_binding_mismatch', 'command' => $first],
            ['blockedCode' => 'device_binding_mismatch', 'command' => $this->command(self::SECOND)],
        ]));
        self::assertSame($this->response([
            ['operationId' => self::FIRST, 'action' => 'readback', 'code' => 'execution_receipt_found',
                'result' => $receipt['response']],
            ['operationId' => self::SECOND, 'action' => 'retry_same_operation',
                'code' => 'unknown_under_original_binding'],
        ]), $result);
    }

    public function testDiagnosticsRechecksMembershipAfterCollectingDeploymentEvidence(): void
    {
        $active = true;
        $deployment = $this->createStub(SyncDeploymentInformation::class);
        $deployment->method('information')->willReturnCallback(static function () use (&$active): array {
            $active = false;
            return ['applicationVersion' => 'synthetic-build'];
        });
        $service = $this->service(
            $this->createStub(SyncStore::class),
            deployment: $deployment,
            membership: static function () use (&$active): bool {
                return $active;
            },
        );
        try {
            $service->diagnostics($this->identity(), self::HOME);
            self::fail('A revoked member received deployment evidence.');
        } catch (Problem $problem) {
            self::assertSame(404, $problem->status);
            self::assertSame('Not found', $problem->title);
        }
    }

    #[DataProvider('protocols')]
    public function testUnexpectedInfrastructureErrorsPreserveOperationIdentityAndProduceSafeRetry(int $protocol): void
    {
        $store = $this->createMock(SyncStore::class);
        $store->method('highWater')->willReturn(0);
        $store->method('operationReceipt')->willReturn(null);
        $store->expects(self::never())->method('recordCommandReceipt');
        $dispatcher = $this->createMock(SyncCommandDispatcher::class);
        $error = new RuntimeException('PRIVATE transport or database detail');
        if ($protocol === 1) {
            $store->expects(self::once())->method('apply')->willThrowException($error);
            $dispatcher->expects(self::never())->method('dispatch');
        } else {
            $store->expects(self::never())->method('apply');
            $dispatcher->expects(self::once())->method('dispatch')->willThrowException($error);
        }
        $operation = $protocol === 1 ? [
            'operationId' => self::FIRST, 'entityId' => self::FIRST, 'entityType' => 'private-note',
            'operationType' => 'put', 'baseRevision' => null, 'clientTimestamp' => '2026-10-22T10:00:00Z',
            'payloadSchemaVersion' => 1, 'payload' => ['body' => 'Synthetic note'],
        ] : $this->command(self::FIRST);
        $result = $this->service($store, dispatcher: $dispatcher)->push(
            $this->identity(),
            self::HOME,
            'retry-evidence',
            self::SECOND,
            ['protocolVersion' => $protocol, 'batchId' => self::SECOND, 'deviceId' => self::DEVICE,
                'lastPulledCursor' => null, 'operations' => [$operation]],
        );
        self::assertSame([[
            'operationId' => self::FIRST,
            'status' => 'retryable_failure',
            'detail' => $protocol === 1
                ? 'The operation could not be processed safely.' : 'The command could not be processed safely.',
        ]], $result['results']);
        self::assertStringNotContainsString('PRIVATE', json_encode($result, JSON_THROW_ON_ERROR));
    }

    /** @return iterable<string, array{int}> */
    public static function protocols(): iterable
    {
        yield 'generic operation' => [1];
        yield 'typed command' => [2];
    }

    /**
     * @param list<array<string, mixed>> $operations
     * @return array<string, mixed>
     */
    private function request(array $operations): array
    {
        return ['accountId' => self::USER, 'deviceId' => self::DEVICE, 'operations' => $operations];
    }

    /**
     * @param list<array<string, mixed>> $operations
     * @return array<string, mixed>
     */
    private function response(array $operations): array
    {
        return ['protocolVersion' => 2, 'accountId' => self::USER, 'deviceId' => self::DEVICE,
            'homeId' => self::HOME, 'operations' => $operations];
    }

    /** @return array<string, mixed> */
    private function command(string $id, string $type = 'purchasing.store.create'): array
    {
        return [
            'operationId' => $id, 'commandType' => $type, 'entityId' => self::THIRD, 'baseRevision' => null,
            'clientTimestamp' => '2026-10-22T10:00:00Z', 'payloadSchemaVersion' => 1,
            'payload' => match ($type) {
                'inventory.location.create' => ['name' => 'Pantry', 'kind' => 'pantry'],
                'shopping.list.create' => ['name' => 'Groceries', 'kind' => 'manual'],
                default => ['name' => 'Shop', 'location' => 'Windhoek'],
            },
        ];
    }

    /**
     * @param array<string, mixed> $command
     * @return array<string, mixed>
     */
    private function receipt(array $command): array
    {
        return [
            'homeId' => self::HOME, 'userId' => self::USER, 'deviceId' => self::DEVICE,
            'requestHash' => new SyncCommandHasher()->hash(new SyncCommandValidator(65536)->validate($command)),
            'response' => ['operationId' => $command['operationId'], 'status' => 'accepted',
                'commandType' => $command['commandType']],
        ];
    }

    private function identity(): AuthenticatedIdentity
    {
        return new AuthenticatedIdentity(self::USER, self::USER, self::DEVICE, null, []);
    }

    private function service(
        SyncStore $store,
        ?string $onlyWritePermission = null,
        ?Throwable $writeFailure = null,
        ?SyncCommandDispatcher $dispatcher = null,
        ?SyncDeploymentInformation $deployment = null,
        ?\Closure $membership = null,
    ): SynchronizationService {
        $homes = $this->createStub(HomeStore::class);
        $homes->method('membership')->willReturnCallback(static fn (): ?array => ($membership === null || $membership())
            ? ['status' => 'active', 'role' => HomeAuthorization::MEMBER] : null);
        $homes->method('permissionDecision')->willReturnCallback(
            static function (
                string $_home,
                string $_role,
                string $permission
            ) use (
                $onlyWritePermission,
                $writeFailure,
            ): ?bool {
                if (str_ends_with($permission, '.write')) {
                    if ($onlyWritePermission !== null && $permission !== $onlyWritePermission) {
                        return false;
                    }
                    if ($writeFailure !== null) {
                        throw $writeFailure;
                    }
                }
                return null;
            },
        );
        $transactions = $this->createStub(TransactionManager::class);
        $transactions->method('transactional')->willReturnCallback(static fn (callable $work): mixed => $work());
        $clock = new FixedClock(new DateTimeImmutable('2026-10-22T12:00:00Z'));
        $cursors = new CursorCodec(str_repeat('s', 32), $clock, 3600);
        return new SynchronizationService(
            $store,
            $cursors,
            new HomeAuthorization($homes, AccessFixture::create()),
            $clock,
            new SyncEnvelopeValidator(100),
            new SyncOperationValidator(new SyncEntityPolicyRegistry([new PrivateNoteSyncEntityPolicy()]), 65536),
            new SyncRequestHasher(),
            new SyncResultPresenter($cursors),
            100,
            new SyncCommandValidator(65536),
            $dispatcher ?? $this->createStub(SyncCommandDispatcher::class),
            new SyncCommandHasher(),
            $transactions,
            null,
            $deployment,
        );
    }
}
