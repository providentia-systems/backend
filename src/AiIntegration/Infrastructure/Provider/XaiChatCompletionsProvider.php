<?php

declare(strict_types=1);

namespace Providentia\AiIntegration\Infrastructure\Provider;

use JsonException;
use Providentia\AiIntegration\Application\AiProvider;
use Providentia\AiIntegration\Application\AiProviderException;
use Providentia\AiIntegration\Application\ExtractionSchema;
use Providentia\AiIntegration\Application\JsonHttpClient;
use Providentia\AiIntegration\Domain\ExtractionOutcome;
use Providentia\AiIntegration\Domain\ExtractionRequest;

final readonly class XaiChatCompletionsProvider implements AiProvider
{
    public function __construct(
        private JsonHttpClient $http,
        private ExtractionSchema $schema,
        private string $endpoint,
    ) {
    }

    public function id(): string
    {
        return 'xai';
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
                    'content' => [[
                        'type' => 'text',
                        'text' => ProviderPrompt::for($request->kind),
                    ], [
                        'type' => 'image_url',
                        'image_url' => [
                            'url' => 'data:' . $request->mimeType . ';base64,' . base64_encode($request->bytes),
                        ],
                    ]],
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
        if (($choice['finish_reason'] ?? null) !== 'stop') {
            throw new AiProviderException('provider_incomplete', 'The provider did not complete the extraction.');
        }
        $message = is_array($choice['message'] ?? null) ? $choice['message'] : [];
        $decoded = $this->decode(is_string($message['content'] ?? null) ? $message['content'] : null);
        $usage = is_array($response['usage'] ?? null) ? $response['usage'] : [];

        return new ExtractionOutcome($decoded, [
            'inputTokens' => $this->token($usage['prompt_tokens'] ?? null),
            'outputTokens' => $this->token($usage['completion_tokens'] ?? null),
            'totalTokens' => $this->token($usage['total_tokens'] ?? null),
        ]);
    }

    /** @return array<string, mixed> */
    private function decode(?string $text): array
    {
        if ($text === null) {
            throw new AiProviderException('provider_empty_output', 'The provider returned no structured output.');
        }
        try {
            $decoded = json_decode($text, true, 128, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new AiProviderException('provider_invalid_json', 'The provider returned invalid JSON.');
        }
        if (! is_array($decoded) || array_is_list($decoded)) {
            throw new AiProviderException('provider_invalid_json', 'The provider returned an invalid JSON object.');
        }

        return $decoded;
    }

    private function token(mixed $value): ?int
    {
        return is_int($value) && $value >= 0 ? $value : null;
    }
}
