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

final readonly class OpenAiResponsesProvider implements AiProvider
{
    public function __construct(
        private JsonHttpClient $http,
        private ExtractionSchema $schema,
        private string $endpoint,
    ) {
    }

    public function id(): string
    {
        return 'openai';
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
                'store' => false,
                'input' => [[
                    'role' => 'user',
                    'content' => [
                        ['type' => 'input_text', 'text' => ProviderPrompt::for($request->kind)],
                        [
                            'type' => 'input_image',
                            'image_url' => 'data:' . $request->mimeType . ';base64,'
                                . base64_encode($request->bytes),
                            'detail' => 'high',
                        ],
                    ],
                ]],
                'text' => [
                    'format' => [
                        'type' => 'json_schema',
                        'name' => 'providentia_' . $request->kind . '_extraction_v' . ExtractionSchema::VERSION,
                        'strict' => true,
                        'schema' => $this->schema->jsonSchema(),
                    ],
                ],
                'max_output_tokens' => 6000,
            ],
            60,
            1048576,
        );
        if (($response['status'] ?? null) !== 'completed') {
            throw new AiProviderException(
                'provider_incomplete',
                'The provider did not complete the structured extraction.',
            );
        }
        $text = $this->outputText($response);
        try {
            $decoded = json_decode($text, true, 128, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new AiProviderException('provider_invalid_json', 'The provider returned invalid JSON.');
        }
        if (! is_array($decoded) || array_is_list($decoded)) {
            throw new AiProviderException('provider_invalid_json', 'The provider returned an invalid JSON object.');
        }

        $usage = is_array($response['usage'] ?? null) ? $response['usage'] : [];

        return new ExtractionOutcome($decoded, [
            'inputTokens' => $this->token($usage['input_tokens'] ?? null),
            'outputTokens' => $this->token($usage['output_tokens'] ?? null),
            'totalTokens' => $this->token($usage['total_tokens'] ?? null),
        ]);
    }

    /** @param array<string, mixed> $response */
    private function outputText(array $response): string
    {
        $output = is_array($response['output'] ?? null) ? $response['output'] : [];
        foreach ($output as $item) {
            if (! is_array($item) || ($item['type'] ?? null) !== 'message') {
                continue;
            }
            $contents = is_array($item['content'] ?? null) ? $item['content'] : [];
            foreach ($contents as $content) {
                if (! is_array($content)) {
                    continue;
                }
                if (($content['type'] ?? null) === 'refusal') {
                    throw new AiProviderException(
                        'provider_refusal',
                        'The provider declined to process this image.',
                    );
                }
                if (($content['type'] ?? null) === 'output_text' && is_string($content['text'] ?? null)) {
                    return $content['text'];
                }
            }
        }
        throw new AiProviderException('provider_empty_output', 'The provider returned no structured output.');
    }

    private function token(mixed $value): ?int
    {
        return is_int($value) && $value >= 0 ? $value : null;
    }
}
