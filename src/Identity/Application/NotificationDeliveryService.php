<?php

declare(strict_types=1);

namespace Providentia\Identity\Application;

use DateInterval;
use Providentia\SharedKernel\Application\Clock;

final class NotificationDeliveryService
{
    public function __construct(
        private readonly NotificationOutbox $outbox,
        private readonly NotificationTransport $transport,
        private readonly Clock $clock,
        private readonly int $batchSize,
        private readonly int $maxAttempts,
    ) {
    }

    /** @return array{sent: int, failed: int} */
    public function deliverOnce(): array
    {
        $now = $this->clock->now();
        $messages = $this->outbox->lease(
            $this->batchSize,
            $now,
            $now->add(new DateInterval('PT2M')),
        );
        $sent = 0;
        $failed = 0;
        foreach ($messages as $message) {
            try {
                $this->transport->deliver(
                    $message['template'],
                    $message['recipient'],
                    $message['context'],
                );
                $this->outbox->complete($message['id'], $this->clock->now());
                $sent++;
            } catch (\Throwable $failure) {
                $this->outbox->fail(
                    $message['id'],
                    $failure::class,
                    $this->clock->now(),
                    $this->maxAttempts,
                );
                $failed++;
            }
        }

        return ['sent' => $sent, 'failed' => $failed];
    }
}
