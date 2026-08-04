<?php

declare(strict_types=1);

namespace Providentia\AiIntegration\Application\Orchestration;

use Providentia\AiIntegration\Application\AiProviderException;
use Providentia\AiIntegration\Application\ExtractionSchema;
use Providentia\AiIntegration\Domain\ExtractionOutcome;
use Providentia\AiIntegration\Domain\ExtractionRequest;

final readonly class AiOrchestrator
{
    public function __construct(
        private ExtractionSchema $schema,
        private ProviderFailureClassifier $failures,
        private ExtractionReconciler $reconciler,
        private int $maxAttempts = 4,
    ) {
    }

    /**
     * @param non-empty-list<AiExecution> $extractionPlan
     */
    public function execute(
        string $kind,
        string $mimeType,
        string $bytes,
        array $extractionPlan,
        ?AiExecution $validator = null,
    ): AiOrchestrationResult {
        if (count($extractionPlan) > $this->maxAttempts) {
            throw new AiProviderException('orchestration_budget_exceeded', 'The AI attempt budget was exceeded.');
        }
        $attempts = [];
        $primary = null;
        $primaryProvider = null;
        $lastError = null;
        foreach ($extractionPlan as $execution) {
            try {
                $primary = $this->run($execution, $kind, $mimeType, $bytes);
                $primaryProvider = $execution->provider->id();
                $attempts[] = $this->attempt('extract', $primaryProvider, 'completed', null);
                break;
            } catch (AiProviderException $error) {
                $lastError = $error;
                $attempts[] = $this->attempt(
                    'extract',
                    $execution->provider->id(),
                    'failed',
                    $error->safeCode,
                );
                if (! $this->failures->permitsFailover($error->safeCode)) {
                    throw $error;
                }
            }
        }
        if ($primary === null) {
            throw $lastError ?? new AiProviderException(
                'provider_unavailable',
                'No AI extraction provider completed the request.',
            );
        }

        $discrepancies = [];
        $validated = false;
        $usage = $primary->usage;
        if ($validator !== null) {
            if ($validator->provider->id() === $primaryProvider) {
                throw new AiProviderException(
                    'validator_not_independent',
                    'Independent validation requires another provider.',
                );
            }
            $validation = $this->run($validator, $kind, $mimeType, $bytes);
            $attempts[] = $this->attempt('validate', $validator->provider->id(), 'completed', null);
            $discrepancies = $this->reconciler->discrepancies($primary->data, $validation->data);
            $usage = $this->addUsage($primary->usage, $validation->usage);
            $validated = true;
        }

        return new AiOrchestrationResult(
            $primary->data,
            $usage,
            $attempts,
            $discrepancies,
            $validated,
        );
    }

    private function run(
        AiExecution $execution,
        string $kind,
        string $mimeType,
        string $bytes,
    ): ExtractionOutcome {
        $outcome = $execution->provider->extract(new ExtractionRequest(
            $kind,
            $mimeType,
            $bytes,
            $execution->model,
            $execution->credential,
        ));
        $validated = $this->schema->validate($outcome->data, $kind);

        return new ExtractionOutcome($validated, $outcome->usage);
    }

    /** @return array{purpose: string, provider: string, status: string, errorCode: string|null} */
    private function attempt(string $purpose, string $provider, string $status, ?string $error): array
    {
        return [
            'purpose' => $purpose,
            'provider' => $provider,
            'status' => $status,
            'errorCode' => $error,
        ];
    }

    /**
     * @param array{inputTokens: int|null, outputTokens: int|null, totalTokens: int|null} $left
     * @param array{inputTokens: int|null, outputTokens: int|null, totalTokens: int|null} $right
     * @return array{inputTokens: int|null, outputTokens: int|null, totalTokens: int|null}
     */
    private function addUsage(array $left, array $right): array
    {
        return [
            'inputTokens' => $this->add($left['inputTokens'], $right['inputTokens']),
            'outputTokens' => $this->add($left['outputTokens'], $right['outputTokens']),
            'totalTokens' => $this->add($left['totalTokens'], $right['totalTokens']),
        ];
    }

    private function add(?int $left, ?int $right): ?int
    {
        return $left === null || $right === null ? null : $left + $right;
    }
}
