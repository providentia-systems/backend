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

final readonly class AnthropicMessagesProvider implements AiProvider
{
    public function __construct(
        private JsonHttpClient $http,
        private ExtractionSchema $schema,
        private string $endpoint,
    ) {
    }

    public function id(): string
    {
        return 'anthropic';
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
            [
                'x-api-key' => $request->credential,
                'anthropic-version' => '2023-06-01',
            ],
            [
                'model' => $request->model,
                'max_tokens' => 6000,
                'temperature' => 0,
                'messages' => [[
                    'role' => 'user',
                    'content' => [[
                        'type' => 'image',
                        'source' => [
                            'type' => 'base64',
                            'media_type' => $request->mimeType,
                            'data' => base64_encode($request->bytes),
                        ],
                    ], [
                        'type' => 'text',
                        'text' => ProviderPrompt::for($request->kind),
                    ]],
                ]],
                'output_config' => [
                    'format' => [
                        'type' => 'json_schema',
                        'schema' => $this->schema->jsonSchema(),
                    ],
                ],
            ],
            60,
            1048576,
        );
        if (($response['stop_reason'] ?? null) !== 'end_turn') {
            throw new AiProviderException('provider_incomplete', 'The provider did not complete the extraction.');
        }
        $content = is_array($response['content'] ?? null) ? $response['content'] : [];
        $text = null;
        foreach ($content as $block) {
            if (is_array($block) && ($block['type'] ?? null) === 'text' && is_string($block['text'] ?? null)) {
                $text = $block['text'];
                break;
            }
        }
        $decoded = $this->decode($text);
        $usage = is_array($response['usage'] ?? null) ? $response['usage'] : [];
        $input = $this->token($usage['input_tokens'] ?? null);
        $output = $this->token($usage['output_tokens'] ?? null);

        return new ExtractionOutcome($decoded, [
            'inputTokens' => $input,
            'outputTokens' => $output,
            'totalTokens' => $input === null || $output === null ? null : $input + $output,
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
