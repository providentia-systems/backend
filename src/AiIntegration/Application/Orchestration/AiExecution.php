<?php

declare(strict_types=1);

namespace Providentia\AiIntegration\Application\Orchestration;

use Providentia\AiIntegration\Application\AiProvider;

final readonly class AiExecution
{
    public function __construct(
        public AiProvider $provider,
        public string $model,
        public ?string $credential,
        public string $profileId = '',
        public int $estimatedCostMicros = 0,
    ) {
        if ($estimatedCostMicros < 0) {
            throw new \InvalidArgumentException('Estimated AI cost cannot be negative.');
        }
    }
}
