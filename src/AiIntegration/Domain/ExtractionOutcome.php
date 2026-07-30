<?php

declare(strict_types=1);

namespace Providentia\AiIntegration\Domain;

final readonly class ExtractionOutcome
{
    /**
     * @param array<string, mixed> $data
     * @param array{inputTokens: int|null, outputTokens: int|null, totalTokens: int|null} $usage
     */
    public function __construct(
        public array $data,
        public array $usage,
    ) {
    }
}
