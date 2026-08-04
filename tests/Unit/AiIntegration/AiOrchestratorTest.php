<?php

declare(strict_types=1);

namespace ProvidentiaTest\Unit\AiIntegration;

use PHPUnit\Framework\TestCase;
use Providentia\AiIntegration\Application\AiProvider;
use Providentia\AiIntegration\Application\AiProviderException;
use Providentia\AiIntegration\Application\ExtractionSchema;
use Providentia\AiIntegration\Application\Orchestration\AiExecution;
use Providentia\AiIntegration\Application\Orchestration\AiOrchestrator;
use Providentia\AiIntegration\Application\Orchestration\ExtractionReconciler;
use Providentia\AiIntegration\Application\Orchestration\ProviderFailureClassifier;
use Providentia\AiIntegration\Domain\ExtractionOutcome;
use Providentia\AiIntegration\Domain\ExtractionRequest;

final class AiOrchestratorTest extends TestCase
{
    public function testRetryableFailureFailsOverAndIndependentProviderValidates(): void
    {
        $unreachable = $this->provider('unreachable', static function (): never {
            throw new AiProviderException('provider_timeout', 'Timed out safely.');
        });
        $primary = $this->provider('primary', static fn (): ExtractionOutcome => self::outcome('Shop A'));
        $validator = $this->provider('validator', static fn (): ExtractionOutcome => self::outcome('Shop B'));

        $result = $this->orchestrator()->execute(
            'receipt',
            'image/png',
            'image bytes',
            [
                new AiExecution($unreachable, 'vision', 'key-a'),
                new AiExecution($primary, 'vision', 'key-b'),
            ],
            new AiExecution($validator, 'vision', 'key-c'),
        );

        self::assertTrue($result->validated);
        self::assertCount(3, $result->attempts);
        self::assertSame('provider_timeout', $result->attempts[0]['errorCode']);
        self::assertSame('validate', $result->attempts[2]['purpose']);
        self::assertSame('merchant', $result->discrepancies[0]['field']);
        self::assertSame(12, $result->usage['totalTokens']);
    }

    public function testRefusalCannotFailOverIntoPrivacyBypass(): void
    {
        $refusal = $this->provider('refusal', static function (): never {
            throw new AiProviderException('provider_refusal', 'The material was refused.');
        });
        $fallback = $this->provider('fallback', static fn (): ExtractionOutcome => self::outcome(null));

        try {
            $this->orchestrator()->execute(
                'receipt',
                'image/png',
                'image bytes',
                [
                    new AiExecution($refusal, 'vision', 'key-a'),
                    new AiExecution($fallback, 'vision', 'key-b'),
                ],
            );
            self::fail('A refusal was incorrectly failed over.');
        } catch (AiProviderException $error) {
            self::assertSame('provider_refusal', $error->safeCode);
        }
    }

    public function testValidationMustUseAnotherProvider(): void
    {
        $provider = $this->provider('same-provider', static fn (): ExtractionOutcome => self::outcome(null));

        $this->expectException(AiProviderException::class);
        $this->expectExceptionMessage('Independent validation requires another provider.');
        $this->orchestrator()->execute(
            'receipt',
            'image/png',
            'image bytes',
            [new AiExecution($provider, 'vision', 'key-a')],
            new AiExecution($provider, 'another-model', 'key-b'),
        );
    }

    /** @param callable(ExtractionRequest): ExtractionOutcome $extract */
    private function provider(string $id, callable $extract): AiProvider
    {
        return new class ($id, $extract) implements AiProvider {
            /** @var \Closure(ExtractionRequest): ExtractionOutcome */
            private readonly \Closure $extract;

            /** @param callable(ExtractionRequest): ExtractionOutcome $extract */
            public function __construct(
                private readonly string $providerId,
                callable $extract,
            ) {
                $this->extract = \Closure::fromCallable($extract);
            }

            public function id(): string
            {
                return $this->providerId;
            }

            public function requiresCredential(): bool
            {
                return true;
            }

            public function extract(ExtractionRequest $request): ExtractionOutcome
            {
                return ($this->extract)($request);
            }
        };
    }

    private function orchestrator(): AiOrchestrator
    {
        return new AiOrchestrator(
            new ExtractionSchema(),
            new ProviderFailureClassifier(),
            new ExtractionReconciler(),
        );
    }

    private static function outcome(?string $merchant): ExtractionOutcome
    {
        return new ExtractionOutcome([
            'documentType' => 'receipt',
            'merchant' => $merchant,
            'receiptNumber' => null,
            'purchaseDate' => null,
            'currency' => null,
            'totalAmount' => null,
            'taxAmount' => null,
            'notes' => null,
            'warnings' => [],
            'candidates' => [],
        ], [
            'inputTokens' => 4,
            'outputTokens' => 2,
            'totalTokens' => 6,
        ]);
    }
}
