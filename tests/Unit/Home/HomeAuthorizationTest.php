<?php

declare(strict_types=1);

namespace ProvidentiaTest\Unit\Home;

use PHPUnit\Framework\TestCase;
use Providentia\Home\Application\HomeAuthorization;
use Providentia\Home\Application\HomeStore;
use Providentia\Identity\Application\AuthenticatedIdentity;
use Providentia\SharedKernel\Http\HttpProblem;

final class HomeAuthorizationTest extends TestCase
{
    public function testActiveMemberCanReadOnlyTheRequestedHomeMembership(): void
    {
        $store = $this->createMock(HomeStore::class);
        $store->expects(self::once())
            ->method('membership')
            ->with('01912345-6789-7abc-8def-0123456789ab', '01912345-6789-7abc-9def-0123456789ab')
            ->willReturn(['status' => 'active', 'role' => HomeAuthorization::MEMBER]);
        $authorization = new HomeAuthorization($store);

        $membership = $authorization->requireMember($this->identity(), '01912345-6789-7abc-8def-0123456789ab');

        self::assertSame(HomeAuthorization::MEMBER, $membership['role']);
    }

    public function testCrossHomeOrInactiveMembershipIsIndistinguishableFromNotFound(): void
    {
        $store = $this->createStub(HomeStore::class);
        $store->method('membership')->willReturn(null);
        $authorization = new HomeAuthorization($store);

        try {
            $authorization->requireMember($this->identity(), '01912345-6789-7abc-8def-1123456789ab');
            self::fail('An absent cross-home membership was authorized.');
        } catch (HttpProblem $problem) {
            self::assertSame(404, $problem->status);
            self::assertSame('Not found', $problem->title);
        }
    }

    public function testViewerCannotPassAManagerRoleGate(): void
    {
        $store = $this->createStub(HomeStore::class);
        $store->method('membership')->willReturn([
            'status' => 'active',
            'role' => HomeAuthorization::VIEWER,
        ]);
        $authorization = new HomeAuthorization($store);

        $this->expectException(HttpProblem::class);
        $authorization->requireRole(
            $this->identity(),
            '01912345-6789-7abc-8def-0123456789ab',
            [HomeAuthorization::OWNER, HomeAuthorization::MANAGER],
        );
    }

    private function identity(): AuthenticatedIdentity
    {
        return new AuthenticatedIdentity(
            '01912345-6789-7abc-9def-0123456789ab',
            '01912345-6789-7abc-adef-0123456789ab',
            '01912345-6789-7abc-bdef-0123456789ab',
            null,
            [],
        );
    }
}
