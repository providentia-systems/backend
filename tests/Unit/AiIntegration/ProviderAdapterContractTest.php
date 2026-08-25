<?php

declare(strict_types=1);

namespace ProvidentiaTest\Unit\AiIntegration;

use PHPUnit\Framework\TestCase;
use Providentia\AiIntegration\Application\ExtractionSchema;
use Providentia\AiIntegration\Application\JsonHttpClient;
use Providentia\AiIntegration\Domain\ExtractionRequest;
use Providentia\AiIntegration\Infrastructure\Provider\OllamaProvider;
use Providentia\AiIntegration\Infrastructure\Provider\OpenAiCompatibleProvider;

final class ProviderAdapterContractTest extends TestCase
{
    public function testCompatibleAdapterRequestsStrictNonStreamingOutput(): void
    {
        $http = new class implements JsonHttpClient {
            /** @var array<string, mixed> */
            public array $payload = [];

            public function post(
                string $url,
                array $headers,
                array $payload,
                int $timeoutSeconds,
                int $maxResponseBytes,
            ): array {
                TestCase::assertSame('https://vision.example.test/v1/chat/completions', $url);
                $this->payload = $payload;

                return [
                    'choices' => [[
                        'finish_reason' => 'stop',
                        'message' => ['content' => json_encode(
                            ProviderAdapterContractTest::emptyDocument('receipt'),
                            JSON_THROW_ON_ERROR,
                        )],
                    ]],
                    'usage' => [
                        'prompt_tokens' => 12,
                        'completion_tokens' => 4,
                        'total_tokens' => 16,
                    ],
                ];
            }
        };
        $provider = new OpenAiCompatibleProvider(
            $http,
            new ExtractionSchema(),
            'https://vision.example.test/v1/chat/completions',
        );

        $outcome = $provider->extract($this->request('receipt', 'secret-key'));

        /** @var array<string, mixed> $responseFormat */
        $responseFormat = $http->payload['response_format'];
        /** @var array<string, mixed> $jsonSchema */
        $jsonSchema = $responseFormat['json_schema'];
        self::assertFalse((bool) $http->payload['stream']);
        self::assertTrue((bool) $jsonSchema['strict']);
        self::assertSame('providentia_receipt_extraction_v2', $jsonSchema['name']);
        /** @var array<string, mixed> $schema */
        $schema = $jsonSchema['schema'];
        /** @var array<string, mixed> $properties */
        $properties = $schema['properties'];
        /** @var array<string, mixed> $candidates */
        $candidates = $properties['candidates'];
        /** @var array<string, mixed> $candidateSchema */
        $candidateSchema = $candidates['items'];
        /** @var list<string> $candidateRequired */
        $candidateRequired = $candidateSchema['required'];
        self::assertContains('quantityMinimum', $candidateRequired);
        self::assertContains('quantityMaximum', $candidateRequired);
        self::assertSame(16, $outcome->usage['totalTokens']);
        self::assertSame('receipt', $outcome->data['documentType']);
    }

    public function testOllamaAdapterUsesVisionImagesAndJsonSchema(): void
    {
        $http = new class implements JsonHttpClient {
            /** @var array<string, mixed> */
            public array $payload = [];

            public function post(
                string $url,
                array $headers,
                array $payload,
                int $timeoutSeconds,
                int $maxResponseBytes,
            ): array {
                TestCase::assertSame('http://ollama:11434/api/chat', $url);
                TestCase::assertSame([], $headers);
                $this->payload = $payload;

                return [
                    'done' => true,
                    'message' => ['content' => json_encode(
                        ProviderAdapterContractTest::emptyDocument('stock'),
                        JSON_THROW_ON_ERROR,
                    )],
                    'prompt_eval_count' => 8,
                    'eval_count' => 3,
                ];
            }
        };
        $provider = new OllamaProvider(
            $http,
            new ExtractionSchema(),
            'http://ollama:11434/api/chat',
        );

        $outcome = $provider->extract($this->request('stock', null));

        /** @var list<array<string, mixed>> $messages */
        $messages = $http->payload['messages'];
        /** @var list<string> $images */
        $images = $messages[0]['images'];
        self::assertFalse((bool) $http->payload['stream']);
        self::assertIsArray($http->payload['format']);
        self::assertNotEmpty($images[0]);
        self::assertSame(11, $outcome->usage['totalTokens']);
        self::assertSame('stock', $outcome->data['documentType']);
    }

    /** @return array<string, mixed> */
    public static function emptyDocument(string $kind): array
    {
        return [
            'documentType' => $kind,
            'merchant' => null,
            'receiptNumber' => null,
            'purchaseDate' => null,
            'currency' => null,
            'totalAmount' => null,
            'taxAmount' => null,
            'notes' => null,
            'warnings' => [],
            'candidates' => [],
        ];
    }

    private function request(string $kind, ?string $credential): ExtractionRequest
    {
        return new ExtractionRequest(
            $kind,
            'image/png',
            "\x89PNG\r\n\x1A\n" . str_repeat('x', 20),
            'configured-vision-model',
            $credential,
        );
    }
}
