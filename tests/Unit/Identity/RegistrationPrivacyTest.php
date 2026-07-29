<?php

declare(strict_types=1);

namespace ProvidentiaTest\Unit\Identity;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Providentia\Identity\Application\AccountNotificationSender;
use Providentia\Identity\Application\AuthenticationService;
use Providentia\Identity\Application\CredentialHasher;
use Providentia\Identity\Application\IdentityStore;
use Providentia\SharedKernel\Application\UuidGenerator;

final class RegistrationPrivacyTest extends TestCase
{
    public function testExistingVerifiedAddressReturnsGenericAcceptanceWithoutConflict(): void
    {
        $identities = $this->createStub(IdentityStore::class);
        $identities->method('findUserByEmail')->willReturn([
            'id' => '01912345-6789-7abc-8def-0123456789ab',
            'email_verified_at' => '2026-07-30 10:00:00',
        ]);
        $hasher = $this->createMock(CredentialHasher::class);
        $hasher->expects(self::once())
            ->method('hashPassword')
            ->with('Existing-account-password-123')
            ->willReturn('unused-timing-equalization-hash');
        $notifications = $this->createMock(AccountNotificationSender::class);
        $notifications->expects(self::never())->method('sendEmailVerification');
        $service = new AuthenticationService(
            $identities,
            $hasher,
            $notifications,
            $this->createStub(UuidGenerator::class),
            new IdentityFixedClock(new DateTimeImmutable('2026-07-30T12:00:00+00:00')),
            new IdentityTransactionManager(),
            900,
            2592000,
        );

        $result = $service->register(
            'existing@example.test',
            'Existing-account-password-123',
            'Unrelated Submitted Name',
        );

        self::assertSame(['verificationToken' => null], $result);
    }
}
