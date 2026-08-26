<?php

declare(strict_types=1);

namespace ProvidentiaTest\Unit\Identity;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Providentia\Identity\Application\LoginApplicationKind;
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
                'applicationKind' => 'homeowner',
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
            LoginApplicationKind::HOMEOWNER,
        );
    }

    public function testEveryQueuedNotificationCarriesOnlyItsBoundedContext(): void
    {
        $now = new DateTimeImmutable('2026-08-25T12:00:00+00:00');
        $queued = [];
        $outbox = $this->createMock(NotificationOutbox::class);
        $outbox->expects(self::exactly(3))->method('enqueue')->willReturnCallback(
            /** @param array<string, scalar|null> $context */
            static function (
                string $id,
                string $template,
                string $recipient,
                array $context,
                DateTimeImmutable $createdAt,
            ) use (&$queued): void {
                $queued[] = [$id, $template, $recipient, $context, $createdAt];
            },
        );
        $ids = $this->createStub(UuidGenerator::class);
        $ids->method('generate')->willReturn('01989f53-a000-7000-8000-000000000003');
        $clock = $this->createStub(Clock::class);
        $clock->method('now')->willReturn($now);
        $sender = new QueuedAccountNotificationSender($outbox, $ids, $clock);

        $sender->sendStepUpLink(
            'owner@example.test',
            'step-up-capability',
            'ownership-transfer',
            LoginApplicationKind::HOMEOWNER,
        );
        $sender->sendPlatformAdministratorInvitation('operator@example.test');
        $sender->sendHomeInvitation('member@example.test', 'My home', 'manager');

        self::assertSame([
            [
                '01989f53-a000-7000-8000-000000000003',
                'step-up-link',
                'owner@example.test',
                [
                    'token' => 'step-up-capability',
                    'action' => 'ownership-transfer',
                    'applicationKind' => 'homeowner',
                ],
                $now,
            ],
            [
                '01989f53-a000-7000-8000-000000000003',
                'platform-administrator-invitation',
                'operator@example.test',
                [],
                $now,
            ],
            [
                '01989f53-a000-7000-8000-000000000003',
                'home-invitation',
                'member@example.test',
                ['homeName' => 'My home', 'role' => 'manager'],
                $now,
            ],
        ], $queued);
    }
}
