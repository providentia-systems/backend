<?php

declare(strict_types=1);

namespace Providentia\SharedKernel\Application;

use Providentia\SharedKernel\Application\Health\DatabaseReadinessProbe;
use Providentia\SharedKernel\Application\Health\QueueReadinessProbe;

final class ReadinessService
{
    public function __construct(
        private readonly DatabaseReadinessProbe $database,
        private readonly QueueReadinessProbe $queue,
    ) {
    }

    /** @return array{status: string, checks: array<string, array{status: string, detail?: string}>} */
    public function check(): array
    {
        $checks = [
            'database' => $this->database->check(),
            'queue' => $this->queue->check(),
        ];

        $ready = ! array_filter(
            $checks,
            static fn (array $check): bool => $check['status'] === 'down',
        );

        return ['status' => $ready ? 'ready' : 'not_ready', 'checks' => $checks];
    }
}
