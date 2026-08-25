<?php

declare(strict_types=1);

namespace ProvidentiaTest\Unit\Home;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Providentia\Home\Application\HomeAuthorization;
use Providentia\Home\Application\HomePermission;
use Providentia\Home\Application\HomeService;
use Providentia\Home\Application\HomeStore;
use Providentia\Identity\Application\AccountNotificationSender;
use Providentia\Identity\Application\AuthenticatedIdentity;
use Providentia\Identity\Application\AuthenticationService;
use Providentia\Identity\Application\CredentialHasher;
use Providentia\Identity\Application\IdentityStore;
use Providentia\SharedKernel\Application\Problem;
use Providentia\SharedKernel\Application\SecureTokenGenerator;
use Providentia\SharedKernel\Application\UuidGenerator;

final class HomeMaturityServiceTest extends TestCase
{
    private const HOME_ID = '01912345-6789-7abc-8def-0123456789ab';
    private const OWNER_ID = '01912345-6789-7abc-9def-0123456789ab';
    private const TARGET_ID = '01912345-6789-7abc-adef-0123456789ab';
    private const TRANSFER_ID = '01912345-6789-7abc-bdef-0123456789ab';
    private const AUDIT_ID = '01912345-6789-7abc-8def-1123456789ab';

    public function testOwnerCanReplaceARevisionedRolePermissionPolicy(): void
    {
        $homes = $this->createMock(HomeStore::class);
        $homes->method('membership')->willReturn($this->membership(self::OWNER_ID, HomeAuthorization::OWNER));
        $homes->expects(self::once())
            ->method('replaceRolePermissions')
            ->with(
                self::HOME_ID,
                HomeAuthorization::MEMBER,
                [HomePermission::HOME_READ, HomePermission::MEMBERS_INVITE],
                2,
                self::OWNER_ID,
                self::isInstanceOf(DateTimeImmutable::class),
            )
            ->willReturn(true);
        $homes->expects(self::once())->method('recordAudit');

        $result = $this->service($homes)->configureRolePermissions(
            $this->identity(self::OWNER_ID),
            self::HOME_ID,
            HomeAuthorization::MEMBER,
            [HomePermission::MEMBERS_INVITE, HomePermission::HOME_READ],
            2,
        );

        self::assertSame(3, $result['revision']);
        self::assertSame([HomePermission::HOME_READ, HomePermission::MEMBERS_INVITE], $result['permissions']);
    }

    public function testManagerCanOnlyRevokeTheirOwnOrdinaryInvitation(): void
    {
        $homes = $this->createMock(HomeStore::class);
        $homes->method('membership')->willReturn($this->membership(self::OWNER_ID, HomeAuthorization::MANAGER));
        $homes->method('permissionDecision')->willReturn(null);
        $homes->method('invitation')->willReturn([
            'id' => self::TRANSFER_ID,
            'inviterUserId' => self::OWNER_ID,
            'role' => HomeAuthorization::MEMBER,
            'status' => 'pending',
            'revision' => 4,
        ]);
        $homes->expects(self::once())
            ->method('revokeInvitation')
            ->with(
                self::HOME_ID,
                self::TRANSFER_ID,
                4,
                self::OWNER_ID,
                self::isInstanceOf(DateTimeImmutable::class),
            )
            ->willReturn(true);
        $homes->expects(self::once())->method('recordAudit');

        $this->service($homes)->revokeInvitation(
            $this->identity(self::OWNER_ID),
            self::HOME_ID,
            self::TRANSFER_ID,
            4,
        );
    }

    public function testManagerCannotRevokeAnotherInvitersInvitation(): void
    {
        $homes = $this->createMock(HomeStore::class);
        $homes->method('membership')->willReturn($this->membership(self::OWNER_ID, HomeAuthorization::MANAGER));
        $homes->method('permissionDecision')->willReturn(null);
        $homes->method('invitation')->willReturn([
            'id' => self::TRANSFER_ID,
            'inviterUserId' => self::TARGET_ID,
            'role' => HomeAuthorization::MEMBER,
            'status' => 'pending',
            'revision' => 4,
        ]);
        $homes->expects(self::never())->method('revokeInvitation');

        $this->expectException(Problem::class);
        $this->service($homes)->revokeInvitation(
            $this->identity(self::OWNER_ID),
            self::HOME_ID,
            self::TRANSFER_ID,
            4,
        );
    }

    public function testOwnershipProposalConsumesStepUpAndPersistsPendingTransfer(): void
    {
        $homes = $this->createMock(HomeStore::class);
        $homes->expects(self::exactly(2))
            ->method('membership')
            ->willReturnOnConsecutiveCalls(
                $this->membership(self::OWNER_ID, HomeAuthorization::OWNER),
                $this->membership(self::TARGET_ID, HomeAuthorization::MEMBER, 7),
            );
        $homes->expects(self::once())
            ->method('createOwnershipTransfer')
            ->with(
                self::TRANSFER_ID,
                self::HOME_ID,
                self::OWNER_ID,
                self::TARGET_ID,
                7,
                self::isInstanceOf(DateTimeImmutable::class),
                self::isInstanceOf(DateTimeImmutable::class),
                self::isInstanceOf(DateTimeImmutable::class),
            );
        $homes->expects(self::once())->method('recordAudit');

        $result = $this->service($homes, $this->authentication())->proposeOwnershipTransfer(
            $this->identity(self::OWNER_ID),
            self::HOME_ID,
            self::TARGET_ID,
            7,
            'step-up-token',
        );

        self::assertSame(self::TRANSFER_ID, $result['id']);
        self::assertSame('pending', $result['status']);
    }

    public function testOnlyTargetCanAcceptAndOwnershipChangesInTheSameTransaction(): void
    {
        $transactions = new RecordingTransactionManager();
        $homes = $this->createMock(HomeStore::class);
        $homes->expects(self::exactly(3))
            ->method('membership')
            ->willReturnOnConsecutiveCalls(
                $this->membership(self::TARGET_ID, HomeAuthorization::MEMBER, 7),
                $this->membership(self::TARGET_ID, HomeAuthorization::MEMBER, 7),
                $this->membership(self::OWNER_ID, HomeAuthorization::OWNER, 3),
            );
        $homes->method('ownershipTransfer')->willReturn([
            'id' => self::TRANSFER_ID,
            'homeId' => self::HOME_ID,
            'proposedByUserId' => self::OWNER_ID,
            'targetUserId' => self::TARGET_ID,
            'expectedTargetRevision' => 7,
            'status' => 'pending',
            'revision' => 1,
        ]);
        $homes->expects(self::once())
            ->method('transitionOwnershipTransfer')
            ->willReturnCallback(function () use ($transactions): bool {
                self::assertTrue($transactions->active);

                return true;
            });
        $homes->expects(self::once())
            ->method('transferOwnership')
            ->with(
                self::HOME_ID,
                self::OWNER_ID,
                self::TARGET_ID,
                7,
                self::isInstanceOf(DateTimeImmutable::class),
            )
            ->willReturnCallback(function () use ($transactions): bool {
                self::assertTrue($transactions->active);

                return true;
            });
        $homes->expects(self::once())->method('recordAudit');

        $this->service($homes, null, $transactions)->acceptOwnershipTransfer(
            $this->identity(self::TARGET_ID),
            self::HOME_ID,
            self::TRANSFER_ID,
            1,
        );

        self::assertSame(1, $transactions->invocations);
    }

    public function testProposerCannotAcceptTheirOwnOwnershipProposal(): void
    {
        $homes = $this->createMock(HomeStore::class);
        $homes->method('membership')->willReturn($this->membership(self::OWNER_ID, HomeAuthorization::OWNER));
        $homes->method('ownershipTransfer')->willReturn([
            'id' => self::TRANSFER_ID,
            'homeId' => self::HOME_ID,
            'proposedByUserId' => self::OWNER_ID,
            'targetUserId' => self::TARGET_ID,
            'expectedTargetRevision' => 7,
            'status' => 'pending',
            'revision' => 1,
        ]);
        $homes->expects(self::never())->method('transitionOwnershipTransfer');
        $homes->expects(self::never())->method('transferOwnership');

        $this->expectException(Problem::class);
        $this->service($homes)->acceptOwnershipTransfer(
            $this->identity(self::OWNER_ID),
            self::HOME_ID,
            self::TRANSFER_ID,
            1,
        );
    }

    public function testTargetCanRejectPendingOwnershipTransfer(): void
    {
        $this->assertParticipantTransition('rejected', self::TARGET_ID);
    }

    public function testProposerCanRevokePendingOwnershipTransfer(): void
    {
        $this->assertParticipantTransition('revoked', self::OWNER_ID);
    }

    private function authentication(): AuthenticationService
    {
        $identities = $this->createMock(IdentityStore::class);
        $identities->expects(self::once())
            ->method('consumeOneTimeToken')
            ->with(
                'step-up-ownership:homeowner',
                'hashed-step-up-token',
                self::isInstanceOf(DateTimeImmutable::class),
            )
            ->willReturn(self::OWNER_ID);
        $hasher = $this->createStub(CredentialHasher::class);
        $hasher->method('hashToken')->willReturn('hashed-step-up-token');

        return new AuthenticationService(
            $identities,
            $hasher,
            $this->createStub(AccountNotificationSender::class),
            $this->createStub(UuidGenerator::class),
            new HomeFixedClock(new DateTimeImmutable('2026-08-04T12:00:00+00:00')),
            new RecordingTransactionManager(),
            $this->createStub(SecureTokenGenerator::class),
            900,
            2592000,
            false,
        );
    }

    private function assertParticipantTransition(string $status, string $actorUserId): void
    {
        $homes = $this->createMock(HomeStore::class);
        $homes->method('membership')->willReturn($this->membership($actorUserId, HomeAuthorization::MEMBER));
        $homes->method('ownershipTransfer')->willReturn([
            'id' => self::TRANSFER_ID,
            'homeId' => self::HOME_ID,
            'proposedByUserId' => self::OWNER_ID,
            'targetUserId' => self::TARGET_ID,
            'expectedTargetRevision' => 7,
            'status' => 'pending',
            'revision' => 1,
        ]);
        $homes->expects(self::once())
            ->method('transitionOwnershipTransfer')
            ->with(
                self::HOME_ID,
                self::TRANSFER_ID,
                1,
                $status,
                self::isInstanceOf(DateTimeImmutable::class),
            )
            ->willReturn(true);
        $homes->expects(self::once())->method('recordAudit');
        $service = $this->service($homes);

        if ($status === 'rejected') {
            $service->rejectOwnershipTransfer(
                $this->identity($actorUserId),
                self::HOME_ID,
                self::TRANSFER_ID,
                1,
            );

            return;
        }
        $service->revokeOwnershipTransfer(
            $this->identity($actorUserId),
            self::HOME_ID,
            self::TRANSFER_ID,
            1,
        );
    }

    private function service(
        HomeStore $homes,
        ?AuthenticationService $authentication = null,
        ?RecordingTransactionManager $transactions = null,
    ): HomeService {
        $ids = $this->createStub(UuidGenerator::class);
        $ids->method('generate')->willReturnOnConsecutiveCalls(self::TRANSFER_ID, self::AUDIT_ID);

        return new HomeService(
            $homes,
            new HomeAuthorization($homes),
            $this->createStub(IdentityStore::class),
            $this->createStub(CredentialHasher::class),
            $this->createStub(AccountNotificationSender::class),
            $ids,
            new HomeFixedClock(new DateTimeImmutable('2026-08-04T12:00:00+00:00')),
            $transactions ?? new RecordingTransactionManager(),
            $this->createStub(SecureTokenGenerator::class),
            $authentication,
        );
    }

    /** @return array<string, mixed> */
    private function membership(string $userId, string $role, int $revision = 1): array
    {
        return [
            'home_id' => self::HOME_ID,
            'user_id' => $userId,
            'status' => 'active',
            'role' => $role,
            'revision' => $revision,
        ];
    }

    private function identity(string $userId): AuthenticatedIdentity
    {
        return new AuthenticatedIdentity(
            $userId,
            '01912345-6789-7abc-9def-1123456789ab',
            '01912345-6789-7abc-adef-1123456789ab',
            self::HOME_ID,
            [],
        );
    }
}
