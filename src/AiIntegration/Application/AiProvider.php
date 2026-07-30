<?php

declare(strict_types=1);

namespace Providentia\AiIntegration\Application;

use Providentia\AiIntegration\Domain\ExtractionRequest;
use Providentia\AiIntegration\Domain\ExtractionOutcome;

interface AiProvider
{
    public function id(): string;

    public function requiresCredential(): bool;

    public function extract(ExtractionRequest $request): ExtractionOutcome;
}
