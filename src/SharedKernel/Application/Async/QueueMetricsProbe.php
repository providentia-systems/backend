<?php

declare(strict_types=1);

namespace Providentia\SharedKernel\Application\Async;

interface QueueMetricsProbe
{
    /** @return array{up: int, depth: int} */
    public function measure(): array;
}

