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
use Providentia\Synchronization\Application\PrivateNoteSyncEntityPolicy;
use Providentia\Synchronization\Application\SyncCommandDispatcher;
use Providentia\Synchronization\Application\SyncCommandHasher;
use Providentia\Synchronization\Application\SyncCommandValidator;
use Providentia\Synchronization\Application\SyncEntityPolicyRegistry;
use Providentia\Synchronization\Application\SyncEnvelopeValidator;
use Providentia\Synchronization\Application\SyncOperationValidator;
use Providentia\Synchronization\Application\SyncRequestHasher;
use Providentia\Synchronization\Application\SyncResultPresenter;
use Providentia\Synchronization\Application\SyncStore;
use Providentia\Synchronization\Application\SynchronizationService;
use ProvidentiaTest\Support\AccessFixture;

final class SyncFailureClassificationTest extends TestCase
{
    private const ID = '01912345-6789-7abc-8def-0123456789ab';

    #[DataProvider('failures')]
    public function testBothProtocolsKeepFailureSemantics(
        int $protocol,
        int $httpStatus,
        string $outcome,
        string $type = 'about:blank',
        ?string $code = null,
    ): void {
        $problem = new Problem($httpStatus, 'Synthetic failure', 'Synthetic domain or internal service detail', $type);
        $store = $this->createMock(SyncStore::class);
        $store->method('highWater')->willReturn(0);
        $store->method('operationReceipt')->willReturn(null);
        $store->expects(self::never())->method('recordCommandReceipt');
        $dispatcher = $this->createMock(SyncCommandDispatcher::class);
        if ($protocol === 1) {
            $store->expects(self::once())->method('apply')->willThrowException($problem);
            $dispatcher->expects(self::never())->method('dispatch');
        } else {
            $store->expects(self::never())->method('apply');
            $dispatcher->expects(self::once())->method('dispatch')->willThrowException($problem);
        }
        $homes = $this->createStub(HomeStore::class);
        $homes->method('membership')->willReturn(['status' => 'active', 'role' => HomeAuthorization::MEMBER]);
        $clock = new FixedClock(new DateTimeImmutable('2026-09-16T00:00:00Z'));
        $cursors = new CursorCodec(str_repeat('s', 32), $clock, 3600);
        $transactions = $this->createStub(TransactionManager::class);
        $transactions->method('transactional')->willReturnCallback(
            static fn (callable $operation): mixed => $operation(),
        );
        $service = new SynchronizationService(
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
            $dispatcher,
            new SyncCommandHasher(),
            $transactions,
        );
        $operation = [
            'operationId' => self::ID,
            'entityId' => self::ID,
            'baseRevision' => null,
            'clientTimestamp' => '2026-09-16T00:00:00Z',
            'payloadSchemaVersion' => 1,
            ...($protocol === 1
                ? ['entityType' => 'private-note', 'operationType' => 'put', 'payload' => ['body' => 'Synthetic note']]
                : [
                    'commandType' => 'shopping.list.create',
                    'payload' => ['name' => 'Synthetic list', 'kind' => 'manual'],
                ]),
        ];
        try {
            $response = $service->push(
                new AuthenticatedIdentity(self::ID, self::ID, self::ID, null, []),
                self::ID,
                'failure-classification',
                self::ID,
                [
                    'protocolVersion' => $protocol,
                    'batchId' => self::ID,
                    'deviceId' => self::ID,
                    'lastPulledCursor' => null,
                    'operations' => [$operation],
                ],
            );
            self::assertNotSame(
                'request_authentication',
                $outcome,
                'Authentication must not terminally reject intent.',
            );
            self::assertSame(self::ID, $response['results'][0]['operationId']);
            self::assertSame($outcome, $response['results'][0]['status']);
            if ($code !== null) {
                self::assertSame($code, $response['results'][0]['code']);
            }
            if ($httpStatus === 404 && $type === 'about:blank') {
                self::assertSame('resource_unavailable', $response['results'][0]['code']);
            }
            if ($outcome === 'retryable_failure') {
                self::assertStringNotContainsString('internal service detail', $response['results'][0]['detail']);
                self::assertStringContainsString('Retry the same operation', $response['results'][0]['detail']);
            } else {
                self::assertSame($problem->getMessage(), $response['results'][0]['detail']);
            }
        } catch (Problem $actual) {
            self::assertSame('request_authentication', $outcome);
            self::assertSame($problem, $actual);
            self::assertSame(401, $actual->status);
        }
    }

    /** @return iterable<string, array{int, int, string, 3?: string, 4?: string}> */
    public static function failures(): iterable
    {
        foreach ([1, 2] as $protocol) {
            foreach (
                [
                'sync_device_mismatch' => [403, 'device_binding_mismatch'],
                'sync_home_access_denied' => [404, 'home_access_denied'],
                'sync_permission_denied' => [404, 'permission_denied'],
                ] as $type => [$status, $code]
            ) {
                yield "protocol $protocol / $type" => [
                    $protocol,
                    $status,
                    'authorization_failure',
                    'https://providentia.invalid/problems/' . $type,
                    $code,
                ];
            }
            foreach (
                [
                    400 => 'validation_error',
                    401 => 'request_authentication',
                    403 => 'authorization_failure',
                    404 => 'validation_error',
                    408 => 'retryable_failure',
                    409 => 'conflict',
                    412 => 'conflict',
                    422 => 'validation_error',
                    425 => 'retryable_failure',
                    429 => 'retryable_failure',
                    500 => 'retryable_failure',
                    502 => 'retryable_failure',
                    503 => 'retryable_failure',
                    504 => 'retryable_failure',
                ] as $status => $outcome
            ) {
                yield "protocol $protocol / HTTP $status" => [$protocol, $status, $outcome];
            }
        }
    }
}
