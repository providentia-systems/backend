<?php

declare(strict_types=1);

namespace ProvidentiaTest\Unit\DataGovernance;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Providentia\DataGovernance\Application\DataGovernanceService;
use Providentia\DataGovernance\Application\DataGovernanceStore;
use Providentia\DataGovernance\Application\DataGovernanceDownloadService;
use Providentia\DataGovernance\Application\DataGovernanceProcessor;
use Providentia\DataGovernance\Application\DataArtifactStorage;
use Providentia\DataGovernance\Application\DataExportGenerator;
use Providentia\DataGovernance\Application\DataErasureExecutor;
use Providentia\Home\Application\HomeAuthorization;
use Providentia\Home\Application\HomePermission;
use Providentia\Home\Application\HomeStore;
use Providentia\Identity\Application\AuthenticatedIdentity;
use Providentia\Identity\Application\CredentialHasher;
use Providentia\SharedKernel\Application\Problem;
use Providentia\SharedKernel\Application\UuidGenerator;
use Providentia\SharedKernel\Application\SecureTokenGenerator;
use ProvidentiaTest\Unit\Home\HomeFixedClock;
use ProvidentiaTest\Unit\Home\RecordingTransactionManager;
use Psr\Log\NullLogger;

final class DataGovernanceServiceTest extends TestCase
{
    private const HOME_ID = '01912345-6789-7abc-8def-0123456789ab';
    private const USER_ID = '01912345-6789-7abc-9def-0123456789ab';
    private const REQUEST_ID = '01912345-6789-7abc-adef-0123456789ab';

    public function testAccountExportIsPersistedAndQueuedAtomically(): void
    {
        $transactions = new RecordingTransactionManager();
        $store = $this->createMock(DataGovernanceStore::class);
        $store->expects(self::once())
            ->method('createRequest')
            ->willReturnCallback(function () use ($transactions): void {
                self::assertTrue($transactions->active);
            });
        $result = $this->service($store, $transactions)
            ->requestAccountExport($this->identity());

        self::assertSame(self::REQUEST_ID, $result['id']);
        self::assertSame('queued', $result['status']);
        self::assertSame([], $result['retainedDataDisclosure']);
        self::assertSame(1, $transactions->invocations);
    }

    public function testAccountErasureIsBlockedWhileTheUserOwnsAHome(): void
    {
        $store = $this->createStub(DataGovernanceStore::class);
        $store->method('ownedHomeIds')->willReturn([self::HOME_ID]);
        $this->expectException(Problem::class);
        $this->service($store)->requestAccountErasure($this->identity());
    }

    public function testAccountErasureSnapshotsTheRetainedDataDisclosure(): void
    {
        $store = $this->createMock(DataGovernanceStore::class);
        $store->method('ownedHomeIds')->willReturn([]);
        $store->expects(self::once())
            ->method('createRequest')
            ->willReturnCallback(function (...$arguments): void {
                self::assertSame('account_erasure', $arguments[1]);
                self::assertCount(5, $arguments[7]);
                self::assertSame('security_and_audit_records', $arguments[7][0]['category']);
            });
        $result = $this->service($store)->requestAccountErasure($this->identity());

        self::assertCount(5, $result['retainedDataDisclosure']);
    }

    public function testDelegatedPermissionDoesNotBypassHomeErasureOwnershipSafeguard(): void
    {
        $homeStore = $this->createMock(HomeStore::class);
        $homeStore->method('membership')->willReturn([
            'home_id' => self::HOME_ID,
            'user_id' => self::USER_ID,
            'status' => 'active',
            'role' => HomeAuthorization::MANAGER,
            'revision' => 1,
        ]);
        $homeStore->expects(self::once())->method('permissionDecision')
            ->with(self::HOME_ID, HomeAuthorization::MANAGER, HomePermission::DATA_ERASURE)
            ->willReturn(true);
        $this->expectException(Problem::class);
        $this->service(
            $this->createStub(DataGovernanceStore::class),
            null,
            $homeStore,
        )->requestHomeErasure($this->identity(), self::HOME_ID);
    }

    public function testCrossUserCannotConsumeAnAccountExport(): void
    {
        $store = $this->createMock(DataGovernanceStore::class);
        $store->method('request')->willReturn([
            'id' => self::REQUEST_ID,
            'scopeType' => 'account',
            'subjectUserId' => '01912345-6789-7abc-ffff-0123456789ab',
        ]);
        $store->expects(self::never())->method('consumeDownload');

        $this->expectException(Problem::class);
        $this->downloadService($store)->download($this->identity(), self::REQUEST_ID, 'secret');
    }

    public function testExpiredOrReplayedDownloadTokenReturnsGone(): void
    {
        $store = $this->createStub(DataGovernanceStore::class);
        $store->method('request')->willReturn([
            'id' => self::REQUEST_ID,
            'scopeType' => 'account',
            'subjectUserId' => self::USER_ID,
        ]);
        $store->method('consumeDownload')->willReturn(null);

        try {
            $this->downloadService($store)->download($this->identity(), self::REQUEST_ID, 'used-token');
            self::fail('A replayed token was accepted.');
        } catch (Problem $problem) {
            self::assertSame(410, $problem->status);
        }
    }

    public function testProcessorRedeliveryIsANoOpAfterTheQueueRowIsGone(): void
    {
        $store = $this->createStub(DataGovernanceStore::class);
        $store->method('nextQueuedRequest')->willReturn(null);
        $processor = new DataGovernanceProcessor(
            $store,
            new HomeFixedClock(new DateTimeImmutable('2026-08-04T12:00:00+00:00')),
            $this->createStub(DataExportGenerator::class),
            $this->createStub(DataArtifactStorage::class),
            $this->createStub(DataErasureExecutor::class),
            new NullLogger(),
        );

        self::assertFalse($processor->processOnce());
    }

    public function testProcessorRecordsABoundedFailureTransition(): void
    {
        $store = $this->createMock(DataGovernanceStore::class);
        $store->method('nextQueuedRequest')->willReturn([
            'id' => self::REQUEST_ID,
            'requestKind' => 'account_export',
            'scopeType' => 'account',
            'subjectUserId' => self::USER_ID,
            'revision' => 1,
        ]);
        $store->expects(self::exactly(2))->method('transition')->willReturn(true);
        $exports = $this->createStub(DataExportGenerator::class);
        $exports->method('generate')->willThrowException(new \RuntimeException('fixture export failed'));
        $processor = new DataGovernanceProcessor(
            $store,
            new HomeFixedClock(new DateTimeImmutable('2026-08-04T12:00:00+00:00')),
            $exports,
            $this->createStub(DataArtifactStorage::class),
            $this->createStub(DataErasureExecutor::class),
            new NullLogger(),
        );

        self::assertTrue($processor->processOnce());
    }

    private function service(
        DataGovernanceStore $store,
        ?RecordingTransactionManager $transactions = null,
        ?HomeStore $homeStore = null,
    ): DataGovernanceService {
        $homeStore ??= $this->createStub(HomeStore::class);
        $ids = $this->createStub(UuidGenerator::class);
        $ids->method('generate')->willReturn(self::REQUEST_ID);

        return new DataGovernanceService(
            $store,
            new HomeAuthorization($homeStore),
            $ids,
            new HomeFixedClock(new DateTimeImmutable('2026-08-04T12:00:00+00:00')),
            $transactions ?? new RecordingTransactionManager(),
        );
    }

    private function identity(): AuthenticatedIdentity
    {
        return new AuthenticatedIdentity(
            self::USER_ID,
            '01912345-6789-7abc-9def-1123456789ab',
            '01912345-6789-7abc-adef-1123456789ab',
            self::HOME_ID,
            [],
        );
    }

    private function downloadService(DataGovernanceStore $store): DataGovernanceDownloadService
    {
        $hasher = $this->createStub(CredentialHasher::class);
        $hasher->method('hashToken')->willReturn('hashed-token');

        return new DataGovernanceDownloadService(
            $store,
            new HomeAuthorization($this->createStub(HomeStore::class)),
            $this->createStub(DataArtifactStorage::class),
            $hasher,
            $this->createStub(SecureTokenGenerator::class),
            new HomeFixedClock(new DateTimeImmutable('2026-08-04T12:00:00+00:00')),
        );
    }
}
