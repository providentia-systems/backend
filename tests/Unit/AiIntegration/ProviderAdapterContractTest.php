<?php

declare(strict_types=1);

namespace ProvidentiaTest\Unit\AiIntegration;

use PHPUnit\Framework\TestCase;
use Providentia\AiIntegration\Application\AiProviderException;
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

    public function testEndpointOwningAdaptersPreferTheProfileEndpointOverTheDeploymentFallback(): void
    {
        $http = new class implements JsonHttpClient {
            /** @var list<string> */
            public array $urls = [];

            public function post(
                string $url,
                array $headers,
                array $payload,
                int $timeoutSeconds,
                int $maxResponseBytes,
            ): array {
                $this->urls[] = $url;
                if (str_contains($url, '/api/chat')) {
                    return [
                        'done' => true,
                        'message' => ['content' => json_encode(
                            ProviderAdapterContractTest::emptyDocument('stock'),
                            JSON_THROW_ON_ERROR,
                        )],
                    ];
                }

                return [
                    'choices' => [[
                        'finish_reason' => 'stop',
                        'message' => ['content' => json_encode(
                            ProviderAdapterContractTest::emptyDocument('receipt'),
                            JSON_THROW_ON_ERROR,
                        )],
                    ]],
                ];
            }
        };
        $compatible = new OpenAiCompatibleProvider(
            $http,
            new ExtractionSchema(),
            'https://deployment.example.test/v1/chat/completions',
        );
        $compatible->extract(new ExtractionRequest(
            'receipt',
            'image/png',
            "\x89PNG\r\n\x1A\n" . str_repeat('x', 20),
            'configured-vision-model',
            'secret-key',
            'https://profile.example.test/v1/chat/completions',
        ));
        // Ollama works from a profile endpoint alone: no deployment endpoint
        // is configured at all.
        $ollama = new OllamaProvider($http, new ExtractionSchema(), '');
        $ollama->extract(new ExtractionRequest(
            'stock',
            'image/png',
            "\x89PNG\r\n\x1A\n" . str_repeat('x', 20),
            'configured-vision-model',
            null,
            'http://192.168.1.10:11434/api/chat',
        ));
        // Without a profile endpoint the deployment endpoint stays the
        // legacy fallback.
        $compatible->extract($this->request('receipt', 'secret-key'));

        self::assertSame([
            'https://profile.example.test/v1/chat/completions',
            'http://192.168.1.10:11434/api/chat',
            'https://deployment.example.test/v1/chat/completions',
        ], $http->urls);
    }

    public function testEndpointOwningAdaptersFailClosedWithoutAnyEndpoint(): void
    {
        $http = new class implements JsonHttpClient {
            public function post(
                string $url,
                array $headers,
                array $payload,
                int $timeoutSeconds,
                int $maxResponseBytes,
            ): array {
                TestCase::fail('No provider request may be attempted without an endpoint.');
            }
        };
        foreach (
            [
                new OpenAiCompatibleProvider($http, new ExtractionSchema(), ''),
                new OllamaProvider($http, new ExtractionSchema(), ''),
            ] as $provider
        ) {
            try {
                $provider->extract($this->request('receipt', 'secret-key'));
                self::fail('An adapter without any endpoint attempted a request.');
            } catch (AiProviderException $error) {
                self::assertSame('provider_endpoint_missing', $error->safeCode);
            }
        }
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
