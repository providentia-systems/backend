<?php

declare(strict_types=1);

namespace Providentia\AiIntegration\Application\Orchestration;

use Providentia\AiIntegration\Application\AiProviderException;
use Providentia\AiIntegration\Application\ExtractionSchema;
use Providentia\AiIntegration\Application\SensitiveBufferEraser;
use Providentia\AiIntegration\Domain\ExtractionOutcome;
use Providentia\AiIntegration\Domain\ExtractionRequest;
use Throwable;

final readonly class AiOrchestrator
{
    public function __construct(
        private ExtractionSchema $schema,
        private ProviderFailureClassifier $failures,
        private ExtractionReconciler $reconciler,
        private SensitiveBufferEraser $buffers,
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
        int $maxEstimatedCostMicros = PHP_INT_MAX,
        int $maxTotalTokens = PHP_INT_MAX,
        ?callable $recordAttempt = null,
    ): AiOrchestrationResult {
        try {
            return $this->executeTracked(
                $kind,
                $mimeType,
                $bytes,
                $extractionPlan,
                $validator,
                $maxEstimatedCostMicros,
                $maxTotalTokens,
                $recordAttempt,
            );
        } finally {
            $this->buffers->erase($bytes);
        }
    }

    /**
     * @param non-empty-list<AiExecution> $extractionPlan
     */
    private function executeTracked(
        string $kind,
        string $mimeType,
        string &$bytes,
        array $extractionPlan,
        ?AiExecution $validator,
        int $maxEstimatedCostMicros,
        int $maxTotalTokens,
        ?callable $recordAttempt,
    ): AiOrchestrationResult {
        if (count($extractionPlan) + ($validator === null ? 0 : 1) > $this->maxAttempts) {
            throw new AiProviderException('orchestration_budget_exceeded', 'The AI attempt budget was exceeded.');
        }
        if ($maxEstimatedCostMicros < 0 || $maxTotalTokens < 1) {
            throw new AiProviderException('orchestration_budget_invalid', 'The AI budget is invalid.');
        }
        $attempts = [];
        $primary = null;
        $primaryProvider = null;
        $lastError = null;
        $spentMicros = 0;
        foreach ($extractionPlan as $execution) {
            $spentMicros = $this->reserveBudget($spentMicros, $execution, $maxEstimatedCostMicros);
            try {
                $primary = $this->run($execution, $kind, $mimeType, $bytes);
                $primaryProvider = $execution->provider->id();
                $attempt = $this->attempt('extract', $execution, 'completed', null);
                $attempts[] = $attempt;
                if ($recordAttempt !== null) {
                    $recordAttempt($attempt);
                }
                break;
            } catch (AiProviderException $error) {
                $lastError = $error;
                $attempt = $this->attempt('extract', $execution, 'failed', $error->safeCode);
                $attempts[] = $attempt;
                if ($recordAttempt !== null) {
                    $recordAttempt($attempt);
                }
                if (! $this->failures->permitsFailover($error->safeCode)) {
                    throw $error;
                }
            } catch (Throwable) {
                $attempt = $this->attempt('extract', $execution, 'failed', 'provider_failure');
                $attempts[] = $attempt;
                if ($recordAttempt !== null) {
                    $recordAttempt($attempt);
                }
                throw new AiProviderException(
                    'provider_failure',
                    'The provider request could not be completed safely.',
                );
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
            $this->reserveBudget($spentMicros, $validator, $maxEstimatedCostMicros);
            try {
                $validation = $this->run($validator, $kind, $mimeType, $bytes);
                $attempt = $this->attempt('validate', $validator, 'completed', null);
                $attempts[] = $attempt;
                if ($recordAttempt !== null) {
                    $recordAttempt($attempt);
                }
            } catch (AiProviderException $error) {
                $attempt = $this->attempt('validate', $validator, 'failed', $error->safeCode);
                if ($recordAttempt !== null) {
                    $recordAttempt($attempt);
                }
                throw $error;
            } catch (Throwable) {
                $attempt = $this->attempt('validate', $validator, 'failed', 'provider_failure');
                if ($recordAttempt !== null) {
                    $recordAttempt($attempt);
                }
                throw new AiProviderException(
                    'provider_failure',
                    'The validation request could not be completed safely.',
                );
            }
            $discrepancies = $this->reconciler->discrepancies($primary->data, $validation->data);
            $usage = $this->addUsage($primary->usage, $validation->usage);
            $validated = true;
        }
        if ($usage['totalTokens'] !== null && $usage['totalTokens'] > $maxTotalTokens) {
            throw new AiProviderException(
                'orchestration_token_budget_exceeded',
                'The AI token budget was exceeded; no candidates were released.',
            );
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
        string &$bytes,
    ): ExtractionOutcome {
        $requestBytes = $bytes;
        $requestCredential = $execution->credential;
        $request = new ExtractionRequest(
            $kind,
            $mimeType,
            $requestBytes,
            $execution->model,
            $requestCredential,
            $execution->endpoint,
        );
        try {
            $outcome = $execution->provider->extract($request);
            $validated = $this->schema->validate($outcome->data, $kind);

            return new ExtractionOutcome($validated, $outcome->usage);
        } finally {
            // Providers execute synchronously and must not retain the request.
            // Drop that reference before erasing our two mutable request copies.
            unset($request);
            $this->buffers->erase($requestBytes);
            if ($requestCredential !== null) {
                $this->buffers->erase($requestCredential);
            }
        }
    }

    /** @return array{purpose: string, profileId: string, provider: string, model: string,
     *     status: string, errorCode: string|null, estimatedCostMicros: int} */
    private function attempt(string $purpose, AiExecution $execution, string $status, ?string $error): array
    {
        return [
            'purpose' => $purpose,
            'profileId' => $execution->profileId,
            'provider' => $execution->provider->id(),
            'model' => $execution->model,
            'status' => $status,
            'errorCode' => $error,
            'estimatedCostMicros' => $execution->estimatedCostMicros,
        ];
    }

    private function reserveBudget(int $spent, AiExecution $execution, int $maximum): int
    {
        if ($execution->estimatedCostMicros > $maximum - $spent) {
            throw new AiProviderException(
                'orchestration_budget_exceeded',
                'The AI estimated-cost budget was exceeded.',
            );
        }

        return $spent + $execution->estimatedCostMicros;
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
