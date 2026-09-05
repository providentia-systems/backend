<?php

declare(strict_types=1);

namespace ProvidentiaTest\Unit\Purchasing;

use DateTimeImmutable;
use Laminas\Diactoros\ServerRequest;
use Laminas\Diactoros\Uri;
use PHPUnit\Framework\TestCase;
use Providentia\Home\Application\HomeAuthorization;
use Providentia\Home\Application\HomeStore;
use Providentia\Identity\Application\AuthenticatedIdentity;
use Providentia\Identity\Http\BearerAuthenticationMiddleware;
use Providentia\Inventory\Application\InventoryMovementGateway;
use Providentia\Purchasing\Application\PurchasingService;
use Providentia\Purchasing\Application\PurchasingStore;
use Providentia\Purchasing\Http\PurchasingHandler;
use Providentia\SharedKernel\Application\Problem;
use Providentia\SharedKernel\Application\UuidGenerator;
use ProvidentiaTest\Unit\Home\HomeFixedClock;
use ProvidentiaTest\Unit\Home\RecordingTransactionManager;

final class PurchasingHandlerTest extends TestCase
{
    public function testOrdinaryHttpUnresolvedDecisionUsesExpectedRevision(): void
    {
        $homeId = '01912345-6789-7abc-8def-0123456789ab';
        $receiptId = '01912345-6789-7abc-9def-0123456789ab';
        $lineId = '01912345-6789-7abc-adef-0123456789ab';
        $userId = '01912345-6789-7abc-bdef-0123456789ab';
        $purchases = $this->createMock(PurchasingStore::class);
        $purchases->expects(self::once())
            ->method('receipt')
            ->with($homeId, $receiptId)
            ->willReturn(
                [
                'id' => $receiptId,
                'status' => 'committed',
                'revision' => 5,
                ],
            );
        $purchases->expects(self::once())
            ->method('receiptLine')
            ->with(
                $homeId,
                $receiptId,
                $lineId,
            )
            ->willReturn(
                [
                'id' => $lineId,
                'approvalStatus' => 'unresolved',
                'revision' => 3,
                ],
            );
        $purchases->expects(self::never())
            ->method('markReceiptLineUnresolved');
        $homes = $this->createStub(HomeStore::class);
        $homes->method('membership')
            ->willReturn(
                [
                'status' => 'active',
                'role' => HomeAuthorization::OWNER,
                ],
            );
        $service = new PurchasingService(
            $purchases,
            $this->createStub(InventoryMovementGateway::class),
            new HomeAuthorization(
                $homes,
                \ProvidentiaTest\Support\AccessFixture::create(),
            ),
            $this->createStub(UuidGenerator::class),
            new HomeFixedClock(
                new DateTimeImmutable('2026-08-10T12:00:00+00:00'),
            ),
            new RecordingTransactionManager(),
        );
        $request = new ServerRequest(
            [],
            [],
            new Uri(
                'https://app.example.test/api/v1/homes/' . $homeId . '/receipts/' . $receiptId,
            ),
            'POST',
            'php://memory',
        )->withAttribute(
            'homeId',
            $homeId,
        )
            ->withAttribute(
                'receiptId',
                $receiptId,
            )
            ->withAttribute(
                'lineId',
                $lineId,
            )
            ->withAttribute(
                BearerAuthenticationMiddleware::ATTRIBUTE,
                new AuthenticatedIdentity(
                    $userId,
                    'session',
                    'device',
                    $homeId,
                    [],
                    \ProvidentiaTest\Support\AccessFixture::administratorPermissions([]),
                ),
            )
            ->withParsedBody(
                ['expectedRevision' => 2],
            );
        $response = new PurchasingHandler($service, 'lines.unresolve')->handle($request);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(
            [
                'id' => $lineId,
                'revision' => 3,
                'approvalStatus' => 'unresolved',
            ],
            json_decode(
                (string) $response->getBody(),
                true,
                512,
                JSON_THROW_ON_ERROR,
            ),
        );
    }

    public function testOrdinaryHttpUnresolvedDecisionRejectsSchemaInvalidBodiesBeforeMutation(): void
    {
        $homeId = '01912345-6789-7abc-8def-0123456789ab';
        $receiptId = '01912345-6789-7abc-9def-0123456789ab';
        $lineId = '01912345-6789-7abc-adef-0123456789ab';
        $userId = '01912345-6789-7abc-bdef-0123456789ab';
        foreach (
            [
            [],
            ['expectedRevision' => '2junk'],
            ['expectedRevision' => 2.9],
            ['expectedRevision' => true],
            ['expectedRevision' => 0],
            ['expectedRevision' => 2, 'unexpected' => 'field'],
            ] as $body
        ) {
            $purchases = $this->createMock(PurchasingStore::class);
            $purchases->expects(self::never())
                ->method('receipt');
            $purchases->expects(self::never())
                ->method('receiptLine');
            $purchases->expects(self::never())
                ->method('markReceiptLineUnresolved');
            $homes = $this->createStub(HomeStore::class);
            $service = new PurchasingService(
                $purchases,
                $this->createStub(InventoryMovementGateway::class),
                new HomeAuthorization(
                    $homes,
                    \ProvidentiaTest\Support\AccessFixture::create(),
                ),
                $this->createStub(UuidGenerator::class),
                new HomeFixedClock(
                    new DateTimeImmutable('2026-08-10T12:00:00+00:00'),
                ),
                new RecordingTransactionManager(),
            );
            $request = new ServerRequest(
                [],
                [],
                new Uri(
                    'https://app.example.test/api/v1/homes/' . $homeId . '/receipts/' . $receiptId,
                ),
                'POST',
                'php://memory',
            )->withAttribute(
                'homeId',
                $homeId,
            )
                ->withAttribute(
                    'receiptId',
                    $receiptId,
                )
                ->withAttribute(
                    'lineId',
                    $lineId,
                )
                ->withAttribute(
                    BearerAuthenticationMiddleware::ATTRIBUTE,
                    new AuthenticatedIdentity(
                        $userId,
                        'session',
                        'device',
                        $homeId,
                        [],
                        \ProvidentiaTest\Support\AccessFixture::administratorPermissions([]),
                    ),
                )
                ->withParsedBody(
                    $body,
                );
            try {
                new PurchasingHandler($service, 'lines.unresolve')->handle($request);
                self::fail(
                    'A schema-invalid unresolved decision body was accepted.',
                );
            } catch (Problem $problem) {
                self::assertSame(422, $problem->status);
                self::assertSame(
                    'The request must contain only a positive integer expectedRevision.',
                    $problem->getMessage(),
                );
            }
        }
    }
}
