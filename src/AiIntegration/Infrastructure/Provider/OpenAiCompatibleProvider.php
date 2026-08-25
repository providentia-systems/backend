<?php

declare(strict_types=1);

namespace Providentia\AiIntegration\Infrastructure\Provider;

use Providentia\AiIntegration\Application\AiProvider;
use Providentia\AiIntegration\Application\AiProviderException;
use Providentia\AiIntegration\Application\ExtractionSchema;
use Providentia\AiIntegration\Application\JsonHttpClient;
use Providentia\AiIntegration\Domain\ExtractionOutcome;
use Providentia\AiIntegration\Domain\ExtractionRequest;
use Providentia\AiIntegration\Infrastructure\Http\ProviderJsonDecoder;

final readonly class OpenAiCompatibleProvider implements AiProvider
{
    public function __construct(
        private JsonHttpClient $http,
        private ExtractionSchema $schema,
        private string $endpoint,
    ) {
    }

    public function id(): string
    {
        return 'openai-compatible';
    }

    public function requiresCredential(): bool
    {
        return true;
    }

    public function extract(ExtractionRequest $request): ExtractionOutcome
    {
        if ($request->credential === null) {
            throw new AiProviderException('provider_credential_missing', 'The provider credential is missing.');
        }
        $response = $this->http->post(
            $this->endpoint,
            ['Authorization' => 'Bearer ' . $request->credential],
            [
                'model' => $request->model,
                'messages' => [[
                    'role' => 'user',
                    'content' => [
                        ['type' => 'text', 'text' => ProviderPrompt::for($request->kind)],
                        [
                            'type' => 'image_url',
                            'image_url' => [
                                'url' => 'data:' . $request->mimeType . ';base64,'
                                    . base64_encode($request->bytes),
                            ],
                        ],
                    ],
                ]],
                'response_format' => [
                    'type' => 'json_schema',
                    'json_schema' => [
                        'name' => 'providentia_' . $request->kind . '_extraction_v' . ExtractionSchema::VERSION,
                        'strict' => true,
                        'schema' => $this->schema->jsonSchema(),
                    ],
                ],
                'stream' => false,
            ],
            60,
            1048576,
        );
        $choices = is_array($response['choices'] ?? null) ? $response['choices'] : [];
        $choice = is_array($choices[0] ?? null) ? $choices[0] : [];
        $finishReason = $choice['finish_reason'] ?? null;
        if ($finishReason !== null && $finishReason !== 'stop') {
            throw new AiProviderException(
                'provider_incomplete',
                'The provider did not complete the structured extraction.',
            );
        }
        $message = is_array($choice['message'] ?? null) ? $choice['message'] : [];
        $content = $message['content'] ?? null;
        if (! is_string($content)) {
            throw new AiProviderException('provider_empty_output', 'The provider returned no structured output.');
        }
        $decoded = ProviderJsonDecoder::structuredOutput($content);

        $usage = is_array($response['usage'] ?? null) ? $response['usage'] : [];

        return new ExtractionOutcome($decoded, [
            'inputTokens' => $this->token($usage['prompt_tokens'] ?? null),
            'outputTokens' => $this->token($usage['completion_tokens'] ?? null),
            'totalTokens' => $this->token($usage['total_tokens'] ?? null),
        ]);
    }

    private function token(mixed $value): ?int
    {
        return is_int($value) && $value >= 0 ? $value : null;
    }
}
