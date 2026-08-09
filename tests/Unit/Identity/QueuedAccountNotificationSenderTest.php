<?php

declare(strict_types=1);

namespace ProvidentiaTest\Unit\Identity;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Providentia\Identity\Application\NotificationOutbox;
use Providentia\Identity\Application\QueuedAccountNotificationSender;
use Providentia\SharedKernel\Application\Clock;
use Providentia\SharedKernel\Application\UuidGenerator;

final class QueuedAccountNotificationSenderTest extends TestCase
{
    public function testLoginLinkIsQueuedWithOnlyApprovalCapability(): void
    {
        $now = new DateTimeImmutable('2026-08-04T12:00:00+00:00');
        $outbox = $this->createMock(NotificationOutbox::class);
        $outbox->expects(self::once())->method('enqueue')->with(
            '01989f53-a000-7000-8000-000000000001',
            'login-link',
            'member@example.test',
            [
                'requestId' => '01989f53-a000-7000-8000-000000000002',
                'approvalToken' => 'approval-capability',
            ],
            $now,
        );
        $ids = $this->createStub(UuidGenerator::class);
        $ids->method('generate')->willReturn('01989f53-a000-7000-8000-000000000001');
        $clock = $this->createStub(Clock::class);
        $clock->method('now')->willReturn($now);

        $sender = new QueuedAccountNotificationSender($outbox, $ids, $clock);
        $sender->sendLoginLink(
            'member@example.test',
            '01989f53-a000-7000-8000-000000000002',
            'approval-capability',
        );
    }
}
