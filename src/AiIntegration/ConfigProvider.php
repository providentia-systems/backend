<?php

declare(strict_types=1);

namespace Providentia\AiIntegration;

use Providentia\AiIntegration\Application\AiProviderRegistry;
use Providentia\AiIntegration\Application\AiService;
use Providentia\AiIntegration\Application\AiStore;
use Providentia\AiIntegration\Application\CredentialCipher;
use Providentia\AiIntegration\Application\ExtractionSchema;
use Providentia\AiIntegration\Application\JsonHttpClient;
use Providentia\AiIntegration\Infrastructure\Doctrine\DbalAiStore;
use Providentia\AiIntegration\Infrastructure\Factory\AiIntegrationFactory;
use Providentia\AiIntegration\Infrastructure\Http\EndpointPolicy;
use Providentia\AiIntegration\Infrastructure\Http\StreamJsonHttpClient;
use Providentia\AiIntegration\Infrastructure\Provider\AnthropicMessagesProvider;
use Providentia\AiIntegration\Infrastructure\Provider\GeminiGenerateContentProvider;
use Providentia\AiIntegration\Infrastructure\Provider\OllamaProvider;
use Providentia\AiIntegration\Infrastructure\Provider\OpenAiCompatibleProvider;
use Providentia\AiIntegration\Infrastructure\Provider\OpenAiResponsesProvider;
use Providentia\AiIntegration\Infrastructure\Provider\XaiChatCompletionsProvider;
use Providentia\AiIntegration\Infrastructure\Security\NativeCredentialCipher;

final class ConfigProvider
{
    /** @return array<string, mixed> */
    public function __invoke(): array
    {
        return [
            'dependencies' => [
                'aliases' => [
                    AiStore::class => DbalAiStore::class,
                    CredentialCipher::class => NativeCredentialCipher::class,
                    JsonHttpClient::class => StreamJsonHttpClient::class,
                ],
                'factories' => [
                    DbalAiStore::class => AiIntegrationFactory::class,
                    NativeCredentialCipher::class => AiIntegrationFactory::class,
                    EndpointPolicy::class => AiIntegrationFactory::class,
                    StreamJsonHttpClient::class => AiIntegrationFactory::class,
                    ExtractionSchema::class => AiIntegrationFactory::class,
                    OpenAiResponsesProvider::class => AiIntegrationFactory::class,
                    AnthropicMessagesProvider::class => AiIntegrationFactory::class,
                    GeminiGenerateContentProvider::class => AiIntegrationFactory::class,
                    XaiChatCompletionsProvider::class => AiIntegrationFactory::class,
                    OpenAiCompatibleProvider::class => AiIntegrationFactory::class,
                    OllamaProvider::class => AiIntegrationFactory::class,
                    AiProviderRegistry::class => AiIntegrationFactory::class,
                    AiService::class => AiIntegrationFactory::class,
                    'ai.settings.get' => AiIntegrationFactory::class,
                    'ai.settings.put' => AiIntegrationFactory::class,
                    'ai.credentials.put' => AiIntegrationFactory::class,
                    'ai.credentials.delete' => AiIntegrationFactory::class,
                    'ai.extractions.create' => AiIntegrationFactory::class,
                    'ai.extractions.get' => AiIntegrationFactory::class,
                    'ai.candidates.review' => AiIntegrationFactory::class,
                ],
            ],
        ];
    }
}
