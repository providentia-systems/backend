<?php

declare(strict_types=1);

namespace ProvidentiaTest\Unit\Home;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Providentia\Home\Application\HomeAuthorization;
use Providentia\Home\Application\HomeService;
use Providentia\Home\Application\HomeStore;
use Providentia\Identity\Application\AccountNotificationSender;
use Providentia\Identity\Application\AuthenticatedIdentity;
use Providentia\Identity\Application\CredentialHasher;
use Providentia\Identity\Application\IdentityStore;
use Providentia\SharedKernel\Application\Problem;
use Providentia\SharedKernel\Application\SecureTokenGenerator;
use Providentia\SharedKernel\Application\UuidGenerator;

final class HomeServiceWorkflowTest extends TestCase
{
    private const HOME_ID = '01912345-6789-7abc-8def-0123456789ab';
    private const USER_ID = '01912345-6789-7abc-9def-0123456789ab';
    private const SESSION_ID = '01912345-6789-7abc-adef-0123456789ab';
    private const DEVICE_ID = '01912345-6789-7abc-bdef-0123456789ab';
    private const AUDIT_ID = '01912345-6789-7abc-8def-1123456789ab';

    public function testCreatePersistsHomeAndAuditInOneTransaction(): void
    {
        $transactions = new RecordingTransactionManager();
        $homes = $this->createMock(HomeStore::class);
        $homes->expects(self::once())
            ->method('createHome')
            ->willReturnCallback(function () use ($transactions): void {
                self::assertTrue($transactions->active);
            });
        $homes->expects(self::once())
            ->method('recordAudit')
            ->with(
                self::AUDIT_ID,
                self::USER_ID,
                'home.created',
                'home',
                self::HOME_ID,
                self::HOME_ID,
                '[]',
                self::isInstanceOf(DateTimeImmutable::class),
            );
        $homes->method('findHome')->with(self::HOME_ID)->willReturn([
            'id' => self::HOME_ID,
            'name' => 'Windhoek Home',
        ]);

        $result = $this->service($homes, $transactions)->create(
            $this->identity(),
            ' Windhoek Home ',
            'en-NA',
            'NAD',
            'Africa/Windhoek',
        );

        self::assertSame(self::HOME_ID, $result['id']);
        self::assertSame(1, $transactions->invocations);
    }

    public function testManagerCannotInviteAnotherManager(): void
    {
        $homes = $this->createMock(HomeStore::class);
        $homes->method('membership')->willReturn([
            'status' => 'active',
            'role' => HomeAuthorization::MANAGER,
        ]);
        $homes->expects(self::never())->method('createInvitation');

        try {
            $this->service($homes)->invite(
                $this->identity(),
                self::HOME_ID,
                'member@example.test',
                HomeAuthorization::MANAGER,
            );
            self::fail('A manager created a peer manager invitation.');
        } catch (Problem $problem) {
            self::assertSame(403, $problem->status);
        }
    }

    public function testAcceptInvitationUsesAuthenticatedAccountEmailAndAudits(): void
    {
        $homes = $this->createMock(HomeStore::class);
        $homes->expects(self::once())
            ->method('acceptInvitation')
            ->with(
                'hashed-invitation',
                self::USER_ID,
                'user@example.test',
                self::isInstanceOf(DateTimeImmutable::class),
            )
            ->willReturn([
                'invitationId' => '01912345-6789-7abc-9def-1123456789ab',
                'homeId' => self::HOME_ID,
            ]);
        $homes->expects(self::once())->method('recordAudit');
        $identities = $this->createStub(IdentityStore::class);
        $identities->method('findUserById')->willReturn([
            'normalized_email' => 'user@example.test',
        ]);
        $hasher = $this->createStub(CredentialHasher::class);
        $hasher->method('hashToken')->with('invitation')->willReturn('hashed-invitation');

        $result = $this->service(
            $homes,
            null,
            $identities,
            $hasher,
        )->acceptInvitation($this->identity(), 'invitation');

        self::assertSame(self::HOME_ID, $result['homeId']);
    }

    public function testOwnerCannotLeaveWithoutExplicitTransfer(): void
    {
        $homes = $this->createMock(HomeStore::class);
        $homes->method('membership')->willReturn([
            'status' => 'active',
            'role' => HomeAuthorization::OWNER,
        ]);
        $homes->expects(self::never())->method('removeMembership');

        $this->expectException(Problem::class);
        $this->expectExceptionMessage('transfer ownership');
        $this->service($homes)->leave($this->identity(), self::HOME_ID);
    }

    public function testOwnershipCannotTransferToCurrentOwner(): void
    {
        $homes = $this->createMock(HomeStore::class);
        $homes->expects(self::never())->method('transferOwnership');

        $this->expectException(Problem::class);
        $this->service($homes)->transferOwnership(
            $this->identity(),
            self::HOME_ID,
            self::USER_ID,
            1,
        );
    }

    private function service(
        HomeStore $homes,
        ?RecordingTransactionManager $transactions = null,
        ?IdentityStore $identities = null,
        ?CredentialHasher $hasher = null,
    ): HomeService {
        $ids = $this->createStub(UuidGenerator::class);
        $ids->method('generate')->willReturnOnConsecutiveCalls(
            self::HOME_ID,
            self::AUDIT_ID,
            self::AUDIT_ID,
        );
        $tokens = $this->createStub(SecureTokenGenerator::class);
        $tokens->method('generate')->willReturn('invitation-token');

        return new HomeService(
            $homes,
            new HomeAuthorization($homes),
            $identities ?? $this->createStub(IdentityStore::class),
            $hasher ?? $this->createStub(CredentialHasher::class),
            $this->createStub(AccountNotificationSender::class),
            $ids,
            new HomeFixedClock(new DateTimeImmutable('2026-07-30T12:00:00+00:00')),
            $transactions ?? new RecordingTransactionManager(),
            $tokens,
        );
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
}
