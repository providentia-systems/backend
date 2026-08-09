<?php

declare(strict_types=1);

namespace ProvidentiaTest\Unit\Identity;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Providentia\Identity\Application\NotificationDeliveryService;
use Providentia\Identity\Application\NotificationOutbox;
use Providentia\Identity\Application\NotificationTransport;
use Providentia\SharedKernel\Application\Clock;
use RuntimeException;

final class NotificationDeliveryServiceTest extends TestCase
{
    public function testFailureIsReturnedToTheDurableRetryState(): void
    {
        $now = new DateTimeImmutable('2026-08-04T12:00:00+00:00');
        $outbox = $this->createMock(NotificationOutbox::class);
        $outbox->method('lease')->willReturn([[
            'id' => '01989f53-a000-7000-8000-000000000001',
            'template' => 'login-link',
            'recipient' => 'member@example.test',
            'context' => ['requestId' => 'request-id', 'approvalToken' => 'secret'],
        ]]);
        $outbox->expects(self::once())->method('fail')->with(
            '01989f53-a000-7000-8000-000000000001',
            RuntimeException::class,
            $now,
            10,
        );
        $outbox->expects(self::never())->method('complete');
        $transport = $this->createStub(NotificationTransport::class);
        $transport->method('deliver')->willThrowException(new RuntimeException('offline'));
        $clock = $this->createStub(Clock::class);
        $clock->method('now')->willReturn($now);

        $delivery = new NotificationDeliveryService($outbox, $transport, $clock, 100, 10);

        self::assertSame(['sent' => 0, 'failed' => 1], $delivery->deliverOnce());
    }
}
