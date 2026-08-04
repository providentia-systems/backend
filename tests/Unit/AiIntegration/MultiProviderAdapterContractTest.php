<?php

declare(strict_types=1);

namespace ProvidentiaTest\Unit\AiIntegration;

use PHPUnit\Framework\TestCase;
use Providentia\AiIntegration\Application\ExtractionSchema;
use Providentia\AiIntegration\Application\JsonHttpClient;
use Providentia\AiIntegration\Domain\ExtractionRequest;
use Providentia\AiIntegration\Infrastructure\Provider\AnthropicMessagesProvider;
use Providentia\AiIntegration\Infrastructure\Provider\GeminiGenerateContentProvider;
use Providentia\AiIntegration\Infrastructure\Provider\XaiChatCompletionsProvider;

final class MultiProviderAdapterContractTest extends TestCase
{
    public function testAnthropicUsesVisionAndStrictStructuredOutput(): void
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
                TestCase::assertSame('https://api.anthropic.test/v1/messages', $url);
                TestCase::assertSame('secret-key', $headers['x-api-key']);
                $this->payload = $payload;

                return [
                    'stop_reason' => 'end_turn',
                    'content' => [[
                        'type' => 'text',
                        'text' => json_encode(self::document(), JSON_THROW_ON_ERROR),
                    ]],
                    'usage' => ['input_tokens' => 10, 'output_tokens' => 4],
                ];
            }

            /** @return array<string, mixed> */
            private static function document(): array
            {
                return ProviderAdapterContractTest::emptyDocument('receipt');
            }
        };
        $provider = new AnthropicMessagesProvider(
            $http,
            new ExtractionSchema(),
            'https://api.anthropic.test/v1/messages',
        );

        $result = $provider->extract($this->request());

        /** @var array<string, mixed> $outputConfig */
        $outputConfig = $http->payload['output_config'];
        /** @var array<string, mixed> $format */
        $format = $outputConfig['format'];
        /** @var list<array<string, mixed>> $messages */
        $messages = $http->payload['messages'];
        /** @var list<array<string, mixed>> $content */
        $content = $messages[0]['content'];
        self::assertSame('json_schema', $format['type']);
        self::assertSame('image', $content[0]['type']);
        self::assertSame(14, $result->usage['totalTokens']);
    }

    public function testGeminiUsesHeaderCredentialAndResponseSchema(): void
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
                TestCase::assertSame(
                    'https://generativelanguage.test/v1beta/models/vision-model:generateContent',
                    $url,
                );
                TestCase::assertSame('secret-key', $headers['x-goog-api-key']);
                $this->payload = $payload;

                return [
                    'candidates' => [[
                        'finishReason' => 'STOP',
                        'content' => ['parts' => [[
                            'text' => json_encode(
                                ProviderAdapterContractTest::emptyDocument('receipt'),
                                JSON_THROW_ON_ERROR,
                            ),
                        ]]],
                    ]],
                    'usageMetadata' => [
                        'promptTokenCount' => 11,
                        'candidatesTokenCount' => 5,
                        'totalTokenCount' => 16,
                    ],
                ];
            }
        };
        $provider = new GeminiGenerateContentProvider(
            $http,
            new ExtractionSchema(),
            'https://generativelanguage.test/v1beta/models/{model}:generateContent',
        );

        $result = $provider->extract($this->request());

        /** @var array<string, mixed> $generation */
        $generation = $http->payload['generationConfig'];
        self::assertSame('application/json', $generation['responseMimeType']);
        self::assertIsArray($generation['responseJsonSchema']);
        self::assertSame(16, $result->usage['totalTokens']);
    }

    public function testXaiUsesStrictNonStreamingVisionRequest(): void
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
                TestCase::assertSame('https://api.x.ai/v1/chat/completions', $url);
                TestCase::assertSame('Bearer secret-key', $headers['Authorization']);
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
                        'prompt_tokens' => 9,
                        'completion_tokens' => 3,
                        'total_tokens' => 12,
                    ],
                ];
            }
        };
        $provider = new XaiChatCompletionsProvider(
            $http,
            new ExtractionSchema(),
            'https://api.x.ai/v1/chat/completions',
        );

        $result = $provider->extract($this->request());

        /** @var array<string, mixed> $format */
        $format = $http->payload['response_format'];
        /** @var array<string, mixed> $jsonSchema */
        $jsonSchema = $format['json_schema'];
        self::assertFalse((bool) $http->payload['stream']);
        self::assertTrue((bool) $jsonSchema['strict']);
        self::assertSame(12, $result->usage['totalTokens']);
    }

    private function request(): ExtractionRequest
    {
        return new ExtractionRequest(
            'receipt',
            'image/png',
            "\x89PNG\r\n\x1A\n" . str_repeat('x', 20),
            'vision-model',
            'secret-key',
        );
    }
}
