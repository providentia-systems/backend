<?php

declare(strict_types=1);

namespace Providentia\AiIntegration\Infrastructure\Factory;

use Doctrine\DBAL\Connection;
use Providentia\AiIntegration\Application\AiProvider;
use Providentia\AiIntegration\Application\AiProviderRegistry;
use Providentia\AiIntegration\Application\AiService;
use Providentia\AiIntegration\Application\AiStore;
use Providentia\AiIntegration\Application\CredentialCipher;
use Providentia\AiIntegration\Application\ExtractionSchema;
use Providentia\AiIntegration\Application\JsonHttpClient;
use Providentia\AiIntegration\Http\AiHandler;
use Providentia\AiIntegration\Infrastructure\Doctrine\DbalAiStore;
use Providentia\AiIntegration\Infrastructure\Http\EndpointPolicy;
use Providentia\AiIntegration\Infrastructure\Http\StreamJsonHttpClient;
use Providentia\AiIntegration\Infrastructure\Provider\AnthropicMessagesProvider;
use Providentia\AiIntegration\Infrastructure\Provider\GeminiGenerateContentProvider;
use Providentia\AiIntegration\Infrastructure\Provider\OllamaProvider;
use Providentia\AiIntegration\Infrastructure\Provider\OpenAiCompatibleProvider;
use Providentia\AiIntegration\Infrastructure\Provider\OpenAiResponsesProvider;
use Providentia\AiIntegration\Infrastructure\Provider\XaiChatCompletionsProvider;
use Providentia\AiIntegration\Infrastructure\Security\NativeCredentialCipher;
use Providentia\Home\Application\HomeAuthorization;
use Providentia\SharedKernel\Application\Clock;
use Providentia\SharedKernel\Application\TransactionManager;
use Providentia\SharedKernel\Application\UuidGenerator;
use Psr\Container\ContainerInterface;

final class AiIntegrationFactory
{
    public function __invoke(ContainerInterface $container, string $requestedName): object
    {
        /** @var array<string, mixed> $config */
        $config = $container->get('config');
        /** @var array<string, mixed> $ai */
        $ai = $config['ai'];

        return match (true) {
            $requestedName === DbalAiStore::class => new DbalAiStore($container->get(Connection::class)),
            $requestedName === ExtractionSchema::class => new ExtractionSchema(),
            $requestedName === NativeCredentialCipher::class => new NativeCredentialCipher(
                (string) $ai['credential_kek'],
                (int) $ai['credential_key_version'],
            ),
            $requestedName === EndpointPolicy::class => new EndpointPolicy(
                $this->allowedHosts($ai),
                (bool) $ai['allow_private_endpoints'],
            ),
            $requestedName === StreamJsonHttpClient::class => new StreamJsonHttpClient(
                $container->get(EndpointPolicy::class),
            ),
            $requestedName === OpenAiResponsesProvider::class => new OpenAiResponsesProvider(
                $container->get(JsonHttpClient::class),
                $container->get(ExtractionSchema::class),
                (string) $ai['openai_endpoint'],
            ),
            $requestedName === AnthropicMessagesProvider::class => new AnthropicMessagesProvider(
                $container->get(JsonHttpClient::class),
                $container->get(ExtractionSchema::class),
                (string) $ai['anthropic_endpoint'],
            ),
            $requestedName === GeminiGenerateContentProvider::class => new GeminiGenerateContentProvider(
                $container->get(JsonHttpClient::class),
                $container->get(ExtractionSchema::class),
                (string) $ai['gemini_endpoint_template'],
            ),
            $requestedName === XaiChatCompletionsProvider::class => new XaiChatCompletionsProvider(
                $container->get(JsonHttpClient::class),
                $container->get(ExtractionSchema::class),
                (string) $ai['xai_endpoint'],
            ),
            $requestedName === OpenAiCompatibleProvider::class => new OpenAiCompatibleProvider(
                $container->get(JsonHttpClient::class),
                $container->get(ExtractionSchema::class),
                (string) $ai['compatible_endpoint'],
            ),
            $requestedName === OllamaProvider::class => new OllamaProvider(
                $container->get(JsonHttpClient::class),
                $container->get(ExtractionSchema::class),
                (string) $ai['ollama_endpoint'],
            ),
            $requestedName === AiProviderRegistry::class => new AiProviderRegistry(
                $this->providers($container, $ai),
            ),
            $requestedName === AiService::class => new AiService(
                $container->get(AiStore::class),
                $container->get(AiProviderRegistry::class),
                $container->get(CredentialCipher::class),
                $container->get(ExtractionSchema::class),
                $container->get(HomeAuthorization::class),
                $container->get(UuidGenerator::class),
                $container->get(Clock::class),
                $container->get(TransactionManager::class),
                (int) $ai['max_image_bytes'],
            ),
            str_starts_with($requestedName, 'ai.') => new AiHandler(
                $container->get(AiService::class),
                substr($requestedName, strlen('ai.')),
                (int) $ai['max_image_bytes'],
            ),
            default => throw new \LogicException('Unsupported AI integration service: ' . $requestedName),
        };
    }

    /**
     * @param array<string, mixed> $ai
     * @return list<AiProvider>
     */
    private function providers(ContainerInterface $container, array $ai): array
    {
        if (! (bool) $ai['server_proxy_enabled']) {
            return [];
        }
        /** @var OpenAiResponsesProvider $openAi */
        $openAi = $container->get(OpenAiResponsesProvider::class);
        /** @var AnthropicMessagesProvider $anthropic */
        $anthropic = $container->get(AnthropicMessagesProvider::class);
        /** @var GeminiGenerateContentProvider $gemini */
        $gemini = $container->get(GeminiGenerateContentProvider::class);
        /** @var XaiChatCompletionsProvider $xai */
        $xai = $container->get(XaiChatCompletionsProvider::class);
        $providers = [$openAi, $anthropic, $gemini, $xai];
        if ($ai['compatible_endpoint'] !== '') {
            /** @var OpenAiCompatibleProvider $compatible */
            $compatible = $container->get(OpenAiCompatibleProvider::class);
            $providers[] = $compatible;
        }
        if ($ai['ollama_endpoint'] !== '') {
            /** @var OllamaProvider $ollama */
            $ollama = $container->get(OllamaProvider::class);
            $providers[] = $ollama;
        }

        return $providers;
    }

    /**
     * @param array<string, mixed> $ai
     * @return list<string>
     */
    private function allowedHosts(array $ai): array
    {
        $hosts = [];
        foreach (
            [
                'openai_endpoint',
                'anthropic_endpoint',
                'gemini_endpoint_template',
                'xai_endpoint',
                'compatible_endpoint',
                'ollama_endpoint',
            ] as $key
        ) {
            if ($ai[$key] === '') {
                continue;
            }
            $host = parse_url((string) $ai[$key], PHP_URL_HOST);
            if (is_string($host) && $host !== '') {
                $hosts[] = mb_strtolower($host);
            }
        }

        return array_values(array_unique($hosts));
    }
}
