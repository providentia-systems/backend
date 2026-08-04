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

final readonly class GeminiGenerateContentProvider implements AiProvider
{
    public function __construct(
        private JsonHttpClient $http,
        private ExtractionSchema $schema,
        private string $endpointTemplate,
    ) {
    }

    public function id(): string
    {
        return 'gemini';
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
        $endpoint = str_replace('{model}', rawurlencode($request->model), $this->endpointTemplate);
        $response = $this->http->post(
            $endpoint,
            ['x-goog-api-key' => $request->credential],
            [
                'contents' => [[
                    'role' => 'user',
                    'parts' => [[
                        'inlineData' => [
                            'mimeType' => $request->mimeType,
                            'data' => base64_encode($request->bytes),
                        ],
                    ], [
                        'text' => ProviderPrompt::for($request->kind),
                    ]],
                ]],
                'generationConfig' => [
                    'temperature' => 0,
                    'responseMimeType' => 'application/json',
                    'responseJsonSchema' => $this->schema->jsonSchema(),
                ],
            ],
            60,
            1048576,
        );
        $candidates = is_array($response['candidates'] ?? null) ? $response['candidates'] : [];
        $candidate = is_array($candidates[0] ?? null) ? $candidates[0] : [];
        if (($candidate['finishReason'] ?? null) !== 'STOP') {
            throw new AiProviderException('provider_incomplete', 'The provider did not complete the extraction.');
        }
        $content = is_array($candidate['content'] ?? null) ? $candidate['content'] : [];
        $parts = is_array($content['parts'] ?? null) ? $content['parts'] : [];
        $part = is_array($parts[0] ?? null) ? $parts[0] : [];
        $decoded = $this->decode(is_string($part['text'] ?? null) ? $part['text'] : null);
        $usage = is_array($response['usageMetadata'] ?? null) ? $response['usageMetadata'] : [];

        return new ExtractionOutcome($decoded, [
            'inputTokens' => $this->token($usage['promptTokenCount'] ?? null),
            'outputTokens' => $this->token($usage['candidatesTokenCount'] ?? null),
            'totalTokens' => $this->token($usage['totalTokenCount'] ?? null),
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
