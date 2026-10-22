<?php

declare(strict_types=1);

namespace ProvidentiaTest\Unit\Inventory;

use DateTimeImmutable;
use Laminas\Diactoros\ServerRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Providentia\Home\Application\HomePermissionAuthorizer;
use Providentia\Identity\Application\AuthenticatedIdentity;
use Providentia\Identity\Http\BearerAuthenticationMiddleware;
use Providentia\Inventory\Application\InventoryService;
use Providentia\Inventory\Application\InventoryStore;
use Providentia\Inventory\Http\InventoryHandler;
use Providentia\SharedKernel\Application\UuidGenerator;
use Providentia\SharedKernel\Http\HttpProblem;
use ProvidentiaTest\Support\AccessFixture;
use ProvidentiaTest\Unit\Home\HomeFixedClock;
use ProvidentiaTest\Unit\Home\RecordingTransactionManager;

final class CountEvidenceHandlerTest extends TestCase
{
    /** @param array<string, mixed> $body */
    #[DataProvider('invalidRequestProvider')]
    public function testInvalidCountEvidenceIsRejectedBeforeAnyPersistence(string $action, array $body): void
    {
        $store = $this->createMock(InventoryStore::class);
        $store->expects(self::never())->method('countSession');
        $store->expects(self::never())->method('createCountSession');
        $service = new InventoryService(
            $store,
            $this->createStub(HomePermissionAuthorizer::class),
            $this->createStub(UuidGenerator::class),
            new HomeFixedClock(new DateTimeImmutable('2026-10-22T10:00:00Z')),
            new RecordingTransactionManager(),
            null,
            AccessFixture::create(),
        );
        $request = new ServerRequest()->withParsedBody($body)
            ->withAttribute('homeId', '01912345-6789-7abc-8def-0123456789ab')
            ->withAttribute('sessionId', '01912345-6789-7abc-9def-0123456789ab')
            ->withAttribute(BearerAuthenticationMiddleware::ATTRIBUTE, new AuthenticatedIdentity(
                '01912345-6789-7abc-adef-0123456789ab',
                'session',
                'device',
                null,
                [],
                [],
            ));
        $this->expectException(HttpProblem::class);
        new InventoryHandler($service, $action)->handle($request);
    }

    /** @return iterable<string, array{string, array<string, mixed>}> */
    public static function invalidRequestProvider(): iterable
    {
        yield 'truthy string completeness' => ['counts.close', ['expectedRevision' => 1, 'scopeComplete' => 'false']];
        yield 'string revision' => ['counts.close', ['expectedRevision' => '1']];
        yield 'null timestamp' => ['counts.create', ['observedAt' => null]];
        yield 'missing deliberate review' => ['counts.review', ['expectedRevision' => 1]];
        yield 'unsupported actor injection' => ['counts.close', ['expectedRevision' => 1, 'actorUserId' => 'other']];
        yield 'null corrections' => ['counts.review', [
            'expectedRevision' => 1, 'reason' => 'Verified', 'scopeComplete' => true,
            'reliability' => 'reliable', 'observedAt' => '2026-10-21T10:00:00Z', 'lineCorrections' => null,
        ]];
    }
}
