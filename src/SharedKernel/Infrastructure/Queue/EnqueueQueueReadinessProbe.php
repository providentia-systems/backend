<?php

declare(strict_types=1);

namespace Providentia\SharedKernel\Infrastructure\Queue;

use Interop\Queue\Context;
use Providentia\SharedKernel\Application\Health\QueueReadinessProbe;
use Throwable;

final class EnqueueQueueReadinessProbe implements QueueReadinessProbe
{
    public function __construct(
        private readonly Context $context,
        private readonly string $queueName,
        private readonly bool $required,
    ) {
    }

    public function check(): array
    {
        if (! $this->required) {
            return ['status' => 'optional'];
        }

        try {
            $this->context->declareQueue($this->context->createQueue($this->queueName));

            return ['status' => 'up'];
        } catch (Throwable) {
            return ['status' => 'down'];
        }
    }
}
