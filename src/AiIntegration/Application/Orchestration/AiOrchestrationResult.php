<?php

declare(strict_types=1);

namespace Providentia\AiIntegration\Application\Orchestration;

final readonly class AiOrchestrationResult
{
    /**
     * @param array<string, mixed> $data
     * @param array{inputTokens: int|null, outputTokens: int|null, totalTokens: int|null} $usage
     * @param list<array{purpose: string, provider: string, status: string, errorCode: string|null}> $attempts
     * @param list<array<string, mixed>> $discrepancies
     */
    public function __construct(
        public array $data,
        public array $usage,
        public array $attempts,
        public array $discrepancies,
        public bool $validated,
    ) {
    }
}
