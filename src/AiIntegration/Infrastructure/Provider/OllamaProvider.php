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

final readonly class OllamaProvider implements AiProvider
{
    public function __construct(
        private JsonHttpClient $http,
        private ExtractionSchema $schema,
        private string $endpoint,
    ) {
    }

    public function id(): string
    {
        return 'ollama';
    }

    public function requiresCredential(): bool
    {
        return false;
    }

    public function extract(ExtractionRequest $request): ExtractionOutcome
    {
        $endpoint = $request->endpoint ?? $this->endpoint;
        if ($endpoint === '') {
            throw new AiProviderException(
                'provider_endpoint_missing',
                'This provider needs a profile endpoint or a deployment endpoint.',
            );
        }
        $response = $this->http->post(
            $endpoint,
            [],
            [
                'model' => $request->model,
                'messages' => [[
                    'role' => 'user',
                    'content' => ProviderPrompt::for($request->kind),
                    'images' => [base64_encode($request->bytes)],
                ]],
                'format' => $this->schema->jsonSchema(),
                'stream' => false,
                'options' => ['temperature' => 0],
            ],
            120,
            1048576,
        );
        if (($response['done'] ?? null) !== true) {
            throw new AiProviderException(
                'provider_incomplete',
                'The local provider did not complete the structured extraction.',
            );
        }
        $message = is_array($response['message'] ?? null) ? $response['message'] : [];
        $content = $message['content'] ?? null;
        if (! is_string($content)) {
            throw new AiProviderException('provider_empty_output', 'The provider returned no structured output.');
        }
        try {
            $decoded = json_decode($content, true, 128, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new AiProviderException('provider_invalid_json', 'The provider returned invalid JSON.');
        }
        if (! is_array($decoded) || array_is_list($decoded)) {
            throw new AiProviderException('provider_invalid_json', 'The provider returned an invalid JSON object.');
        }

        $input = $this->token($response['prompt_eval_count'] ?? null);
        $output = $this->token($response['eval_count'] ?? null);

        return new ExtractionOutcome($decoded, [
            'inputTokens' => $input,
            'outputTokens' => $output,
            'totalTokens' => $input === null || $output === null ? null : $input + $output,
        ]);
    }

    private function token(mixed $value): ?int
    {
        return is_int($value) && $value >= 0 ? $value : null;
    }
}
