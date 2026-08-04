<?php

declare(strict_types=1);

namespace Providentia\AiIntegration\Infrastructure\Factory;

use Doctrine\DBAL\Connection;
use Providentia\AiIntegration\Application\AiProvider;
use Providentia\AiIntegration\Application\AiMaturityStore;
use Providentia\AiIntegration\Application\AiProviderRegistry;
use Providentia\AiIntegration\Application\AiService;
use Providentia\AiIntegration\Application\AiStore;
use Providentia\AiIntegration\Application\CredentialCipher;
use Providentia\AiIntegration\Application\ExtractionSchema;
use Providentia\AiIntegration\Application\JsonHttpClient;
use Providentia\AiIntegration\Application\Media\MediaStorage;
use Providentia\AiIntegration\Application\Media\PrivateMediaService;
use Providentia\AiIntegration\Application\Media\VideoProcessor;
use Providentia\AiIntegration\Application\Orchestration\AiOrchestrator;
use Providentia\AiIntegration\Application\Orchestration\ExtractionReconciler;
use Providentia\AiIntegration\Application\Orchestration\ProviderFailureClassifier;
use Providentia\AiIntegration\Http\AiHandler;
use Providentia\AiIntegration\Http\PrivateMediaHandler;
use Providentia\AiIntegration\Infrastructure\Cli\VideoProcessCommand;
use Providentia\AiIntegration\Infrastructure\Doctrine\DbalAiStore;
use Providentia\AiIntegration\Infrastructure\Http\EndpointPolicy;
use Providentia\AiIntegration\Infrastructure\Http\StreamJsonHttpClient;
use Providentia\AiIntegration\Infrastructure\Media\EncryptedFilesystemMediaStorage;
use Providentia\AiIntegration\Infrastructure\Media\FfmpegVideoProcessor;
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
            $requestedName === ProviderFailureClassifier::class => new ProviderFailureClassifier(),
            $requestedName === ExtractionReconciler::class => new ExtractionReconciler(),
            $requestedName === AiOrchestrator::class => new AiOrchestrator(
                $container->get(ExtractionSchema::class),
                $container->get(ProviderFailureClassifier::class),
                $container->get(ExtractionReconciler::class),
                (int) $ai['orchestration_max_attempts'],
            ),
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
            $requestedName === EncryptedFilesystemMediaStorage::class => new EncryptedFilesystemMediaStorage(
                (string) $ai['media_root'],
                (string) $ai['media_kek'],
                (int) $ai['media_key_version'],
            ),
            $requestedName === FfmpegVideoProcessor::class => new FfmpegVideoProcessor(
                (string) $ai['ffprobe_binary'],
                (string) $ai['ffmpeg_binary'],
                (int) $ai['max_video_bytes'],
                (int) $ai['max_video_duration_seconds'],
                (int) $ai['max_video_frames'],
                (int) $ai['max_image_bytes'],
                (int) $ai['video_processing_timeout_seconds'],
            ),
            $requestedName === PrivateMediaService::class => new PrivateMediaService(
                $container->get(AiMaturityStore::class),
                $container->get(MediaStorage::class),
                $container->get(VideoProcessor::class),
                $container->get(HomeAuthorization::class),
                $container->get(UuidGenerator::class),
                $container->get(Clock::class),
                (int) $ai['media_default_quota_bytes'],
                (int) $ai['max_image_bytes'],
                (int) $ai['max_video_bytes'],
                (int) $ai['media_transient_ttl_seconds'],
                (int) $ai['media_max_export_bytes'],
                (int) $ai['max_images'],
            ),
            $requestedName === VideoProcessCommand::class => new VideoProcessCommand(
                $container->get(PrivateMediaService::class),
            ),
            $requestedName === AiService::class => new AiService(
                $container->get(AiStore::class),
                $container->get(AiMaturityStore::class),
                $container->get(AiProviderRegistry::class),
                $container->get(CredentialCipher::class),
                $container->get(ExtractionSchema::class),
                $container->get(AiOrchestrator::class),
                $container->get(PrivateMediaService::class),
                $container->get(HomeAuthorization::class),
                $container->get(UuidGenerator::class),
                $container->get(Clock::class),
                $container->get(TransactionManager::class),
                (int) $ai['max_image_bytes'],
                (int) $ai['max_images'],
            ),
            str_starts_with($requestedName, 'ai.media.') => new PrivateMediaHandler(
                $container->get(PrivateMediaService::class),
                substr($requestedName, strlen('ai.')),
                max((int) $ai['max_image_bytes'], (int) $ai['max_video_bytes']),
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
