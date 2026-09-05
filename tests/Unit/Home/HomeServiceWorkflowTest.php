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
    private const TARGET_ID = '01912345-6789-7abc-8def-2123456789ab';
    public function testManagerCannotInviteAnotherManager(): void
    {
        $homes = $this->createMock(HomeStore::class);
        $homes->method('membership')
            ->willReturn(
                [
                'status' => 'active',
                'role' => HomeAuthorization::MANAGER,
                ],
            );
        $homes->expects(self::never())
            ->method('createInvitation');
        try {
            $this->service($homes)
                ->invite(
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

    public function testOwnerCannotLeaveWithoutExplicitTransfer(): void
    {
        $homes = $this->createMock(HomeStore::class);
        $homes->method('membership')
            ->willReturn(
                [
                'status' => 'active',
                'role' => HomeAuthorization::OWNER,
                ],
            );
        $homes->expects(self::never())
            ->method('removeMembership');
        $this->expectException(Problem::class);
        $this->expectExceptionMessage('transfer ownership');
        $this->service($homes)
            ->leave($this->identity(), self::HOME_ID);
    }

    public function testHomeUpdateRejectsMissingMutableFieldBeforePersistence(): void
    {
        $homes = $this->createMock(HomeStore::class);
        $homes->expects(self::never())
            ->method('updateHome');
        try {
            $this->service($homes)
                ->update(
                    $this->identity(),
                    self::HOME_ID,
                    'My home',
                    'en-NA',
                    'NAD',
                    'Africa/Windhoek',
                    0,
                );
            self::fail('A non-positive home revision was accepted.');
        } catch (Problem $problem) {
            self::assertSame(422, $problem->status);
        }
    }

    public function testOwnershipCannotTransferToCurrentOwner(): void
    {
        $homes = $this->createMock(HomeStore::class);
        $homes->expects(self::never())
            ->method('transferOwnership');
        $this->expectException(Problem::class);
        $this->service($homes)
            ->transferOwnership(
                $this->identity(),
                self::HOME_ID,
                self::USER_ID,
                1,
            );
    }

    public function testOwnerRemovesAMemberAndRevokesTheirHomeAccess(): void
    {
        $homes = $this->createMock(HomeStore::class);
        $homes->method('membership')
            ->willReturnCallback(
                static fn(string $homeId, string $userId): array => $userId === self::USER_ID
                ? [
                'status' => 'active',
                'role' => HomeAuthorization::OWNER,
                ]
                : [
                'status' => 'active',
                'role' => HomeAuthorization::MEMBER,
                'revision' => 4,
                ],
            );
        $homes->expects(self::once())
            ->method('removeMembershipAtRevision')
            ->with(
                self::HOME_ID,
                self::TARGET_ID,
                4,
                self::isInstanceOf(DateTimeImmutable::class),
            )
            ->willReturn(
                true,
            );
        $homes->expects(self::once())
            ->method('recordAudit')
            ->with(
                self::anything(),
                self::USER_ID,
                'home.membership.removed',
                'home_membership',
                self::TARGET_ID,
                self::HOME_ID,
                self::stringContains('member'),
                self::isInstanceOf(DateTimeImmutable::class),
            );
        $identities = $this->createMock(IdentityStore::class);
        $identities->expects(self::once())
            ->method('clearActiveHome')
            ->with(
                self::TARGET_ID,
                self::HOME_ID,
                self::isInstanceOf(DateTimeImmutable::class),
            );
        $this->service($homes, null, $identities)
            ->removeMember(
                $this->identity(),
                self::HOME_ID,
                self::TARGET_ID,
                4,
            );
    }

    public function testManagerCannotRemoveAPeerManager(): void
    {
        $homes = $this->createMock(HomeStore::class);
        $homes->method('membership')
            ->willReturnCallback(
                static fn(string $homeId, string $userId): array => $userId === self::USER_ID
                ? [
                'status' => 'active',
                'role' => HomeAuthorization::MANAGER,
                ]
                : [
                'status' => 'active',
                'role' => HomeAuthorization::MANAGER,
                'revision' => 2,
                ],
            );
        $homes->method('permissionDecision')
            ->willReturn(true);
        $homes->expects(self::never())
            ->method('removeMembershipAtRevision');
        try {
            $this->service($homes)
                ->removeMember(
                    $this->identity(),
                    self::HOME_ID,
                    self::TARGET_ID,
                    2,
                );
            self::fail('A manager removed a peer manager.');
        } catch (Problem $problem) {
            self::assertSame(403, $problem->status);
        }
    }

    public function testTheOwnerMembershipCannotBeRemoved(): void
    {
        $homes = $this->createMock(HomeStore::class);
        $homes->method('membership')
            ->willReturn(
                [
                'status' => 'active',
                'role' => HomeAuthorization::OWNER,
                ],
            );
        $homes->expects(self::never())
            ->method('removeMembershipAtRevision');
        try {
            $this->service($homes)
                ->removeMember(
                    $this->identity(),
                    self::HOME_ID,
                    self::TARGET_ID,
                    1,
                );
            self::fail('The owner membership was removed.');
        } catch (Problem $problem) {
            self::assertSame(409, $problem->status);
            self::assertStringContainsString('ownership-transfer', $problem->getMessage());
        }
    }

    public function testSelfRemovalIsRedirectedToTheLeaveOperation(): void
    {
        $homes = $this->createMock(HomeStore::class);
        $homes->method('membership')
            ->willReturn(
                [
                'status' => 'active',
                'role' => HomeAuthorization::OWNER,
                ],
            );
        $homes->expects(self::never())
            ->method('removeMembershipAtRevision');
        try {
            $this->service($homes)
                ->removeMember(
                    $this->identity(),
                    self::HOME_ID,
                    self::USER_ID,
                    1,
                );
            self::fail(
                'A member removed their own membership through the administrative removal.',
            );
        } catch (Problem $problem) {
            self::assertSame(409, $problem->status);
            self::assertStringContainsString('Leave the home', $problem->getMessage());
        }
    }

    public function testMemberRemovalDetectsARevisionConflict(): void
    {
        $homes = $this->createStub(HomeStore::class);
        $homes->method('membership')
            ->willReturnCallback(
                static fn(string $homeId, string $userId): array => $userId === self::USER_ID
                ? [
                'status' => 'active',
                'role' => HomeAuthorization::OWNER,
                ]
                : [
                'status' => 'active',
                'role' => HomeAuthorization::VIEWER,
                'revision' => 9,
                ],
            );
        $homes->method('removeMembershipAtRevision')
            ->willReturn(false);
        $identities = $this->createMock(IdentityStore::class);
        $identities->expects(self::never())
            ->method('clearActiveHome');
        try {
            $this->service($homes, null, $identities)
                ->removeMember(
                    $this->identity(),
                    self::HOME_ID,
                    self::TARGET_ID,
                    8,
                );
            self::fail('A stale membership revision was removed.');
        } catch (Problem $problem) {
            self::assertSame(409, $problem->status);
            self::assertStringContainsString('Revision conflict', $problem->title);
        }
    }

    private function service(
        HomeStore $homes,
        ?RecordingTransactionManager $transactions = null,
        ?IdentityStore $identities = null,
        ?CredentialHasher $hasher = null,
    ): HomeService {
        $ids = $this->createStub(UuidGenerator::class);
        $ids->method('generate')
            ->willReturnOnConsecutiveCalls(
                self::HOME_ID,
                self::AUDIT_ID,
                self::AUDIT_ID,
            );
        $tokens = $this->createStub(SecureTokenGenerator::class);
        $tokens->method('generate')
            ->willReturn('invitation-token');
        return new HomeService(
            $homes,
            new HomeAuthorization(
                $homes,
                \ProvidentiaTest\Support\AccessFixture::create(),
            ),
            $identities ?? $this->createStub(IdentityStore::class),
            $hasher ?? $this->createStub(CredentialHasher::class),
            $this->createStub(AccountNotificationSender::class),
            $ids,
            new HomeFixedClock(
                new DateTimeImmutable('2026-07-30T12:00:00+00:00'),
            ),
            $transactions ?? new RecordingTransactionManager(),
            $tokens,
            \ProvidentiaTest\Support\AccessFixture::authentication(),
            \ProvidentiaTest\Support\AccessFixture::create(),
            $this->createStub(
                \Providentia\Identity\Application\AccountProfileStore::class,
            ),
            \ProvidentiaTest\Support\AccessFixture::countries(),
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
            \ProvidentiaTest\Support\AccessFixture::administratorPermissions([]),
        );
    }
}
