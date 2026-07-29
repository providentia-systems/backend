<?php

declare(strict_types=1);

namespace Providentia\SharedKernel\Infrastructure\Queue;

use Providentia\SharedKernel\Application\Async\QueueMetricsProbe;
use Providentia\SharedKernel\Application\Health\QueueReadinessProbe;

final class EnqueueQueueReadinessProbe implements QueueReadinessProbe
{
    public function __construct(
        private readonly QueueMetricsProbe $metrics,
        private readonly bool $required,
    ) {
    }

    public function check(): array
    {
        if (! $this->required) {
            return ['status' => 'optional'];
        }

        $measurement = $this->metrics->measure();

        if ($measurement['up'] === 1) {
            return ['status' => 'up'];
        }

        return ['status' => 'down'];
    }
}
