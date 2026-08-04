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
    ) {
    }
}
