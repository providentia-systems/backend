<?php

declare(strict_types=1);

namespace ProvidentiaTest\Unit\Administration;

use DateTimeImmutable;
use Laminas\Diactoros\ServerRequest;
use Laminas\Diactoros\Uri;
use PHPUnit\Framework\TestCase;
use Providentia\Administration\Application\OperatorAccountService;
use Providentia\Administration\Http\OperatorAccountHandler;
use Providentia\Billing\Application\OperatorSubscriptionReader;
use Providentia\Home\Application\OperatorHomeAccessReader;
use Providentia\Identity\Application\AuthenticatedIdentity;
use Providentia\Identity\Application\OperatorAccountControl;
use Providentia\Identity\Application\OperatorIdentityDirectory;
use Providentia\Identity\Application\PlatformRoleService;
use Providentia\Identity\Application\PlatformRoleStore;
use Providentia\Identity\Http\BearerAuthenticationMiddleware;
use Providentia\SharedKernel\Application\UuidGenerator;
use Providentia\SharedKernel\Http\HttpProblem;
use ProvidentiaTest\Unit\Identity\IdentityFixedClock;
use ProvidentiaTest\Unit\Identity\IdentityTransactionManager;

final class OperatorAccountHandlerTest extends TestCase
{
    private const ACTOR_ID = '01912345-6789-7abc-8def-0123456789ab';
    private const TARGET_ID = '01912345-6789-7abc-9def-0123456789ab';
    private const HOME_ID = '01912345-6789-7abc-adef-0123456789ab';

    public function testStatusMutationReturnsTheComposedPrivacySafeDetail(): void
    {
        $control = $this->createMock(OperatorAccountControl::class);
        $control->expects(self::once())->method('updateOperatorAccountStatus')->with(
            self::isString(),
            self::ACTOR_ID,
            self::TARGET_ID,
            'suspended',
            'Security review',
            2,
            self::isInstanceOf(DateTimeImmutable::class),
        )->willReturn('updated');
        $directory = $this->createMock(OperatorIdentityDirectory::class);
        $directory->expects(self::once())->method('operatorAccount')->willReturn($this->identityProjection(3));
        $homes = $this->createMock(OperatorHomeAccessReader::class);
        $homes->expects(self::once())->method('operatorHomeAccess')->with([self::TARGET_ID])->willReturn([
            self::TARGET_ID => [[
                'homeId' => self::HOME_ID,
                'name' => 'Household',
                'membershipRole' => 'owner',
                'membershipStatus' => 'active',
            ]],
        ]);
        $billing = $this->createMock(OperatorSubscriptionReader::class);
        $billing->expects(self::once())
            ->method('operatorSubscriptions')
            ->with([self::HOME_ID])
            ->willReturn([]);
        $request = $this->request('PATCH')->withParsedBody([
            'status' => 'suspended',
            'reason' => 'Security review',
            'expectedRevision' => 2,
        ]);

        $response = (new OperatorAccountHandler(
            $this->service(
                $directory,
                $control,
                $this->createStub(PlatformRoleStore::class),
                $homes,
                $billing,
            ),
            'status',
        ))->handle($request);
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(1, $body['homeCount']);
        self::assertSame('Household', $body['homes'][0]['name']);
        self::assertNull($body['homes'][0]['subscription']);
        self::assertArrayNotHasKey('stock', $body['homes'][0]);
    }



    public function testMutationRejectsExtraJsonFieldsBeforeTheUseCase(): void
    {
        $control = $this->createMock(OperatorAccountControl::class);
        $control->expects(self::never())->method('updateOperatorAccountStatus');
        $request = $this->request('PATCH')->withParsedBody([
            'status' => 'suspended',
            'reason' => 'Security review',
            'expectedRevision' => 2,
            'privateNote' => 'must not be accepted',
        ]);

        $this->expectException(HttpProblem::class);
        (new OperatorAccountHandler(
            $this->service(
                $this->createStub(OperatorIdentityDirectory::class),
                $control,
                $this->createStub(PlatformRoleStore::class),
                $this->createStub(OperatorHomeAccessReader::class),
                $this->createStub(OperatorSubscriptionReader::class),
            ),
            'status',
        ))->handle($request);
    }

    private function service(
        OperatorIdentityDirectory $directory,
        OperatorAccountControl $control,
        PlatformRoleStore $roles,
        OperatorHomeAccessReader $homes,
        OperatorSubscriptionReader $billing,
    ): OperatorAccountService {
        $ids = $this->createStub(UuidGenerator::class);
        $ids->method('generate')->willReturn('01912345-6789-7abc-bdef-0123456789ab');
        $clock = new IdentityFixedClock(new DateTimeImmutable('2026-08-24T12:00:00+00:00'));
        $transactions = new IdentityTransactionManager();

        return new OperatorAccountService(
            $directory,
            $control,
            $homes,
            $billing,
            $ids,
            $clock,
            $transactions,
            $this->createStub(\Providentia\Identity\Application\AccountProfileStore::class)
        );
    }

    private function request(string $method): ServerRequest
    {
        return (new ServerRequest(
            [],
            [],
            new Uri('https://admin.example.test/api/v1/admin/accounts/' . self::TARGET_ID),
            $method,
            'php://memory',
        ))
            ->withAttribute('userId', self::TARGET_ID)
            ->withAttribute(BearerAuthenticationMiddleware::ATTRIBUTE, new AuthenticatedIdentity(
                self::ACTOR_ID,
                'session',
                'device',
                null,
                ['platform_administrator'],
                \ProvidentiaTest\Support\AccessFixture::administratorPermissions(['platform_administrator'])
            ));
    }

    /** @return array<string, mixed> */
    private function identityProjection(int $revision): array
    {
        return [
            'userId' => self::TARGET_ID,
            'email' => 'person@example.test',
            'emailVerified' => true,
            'displayName' => 'Person',
            'status' => 'suspended',
            'revision' => $revision,
            'createdAt' => '2026-08-01T12:00:00Z',
            'statusChangedAt' => '2026-08-24T12:00:00Z',
            'suspendedAt' => '2026-08-24T12:00:00Z',
            'closedAt' => null,
            'activeSessionCount' => 0,
            'platformRoles' => [],
        ];
    }
}
