<?php

declare(strict_types=1);

namespace Providentia\AiIntegration;

use Providentia\AiIntegration\Application\AiProviderRegistry;
use Providentia\AiIntegration\Application\AiMaturityStore;
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
use Providentia\AiIntegration\Application\SensitiveBufferEraser;
use Providentia\AiIntegration\Infrastructure\Cli\VideoProcessCommand;
use Providentia\AiIntegration\Infrastructure\Doctrine\DbalAiStore;
use Providentia\AiIntegration\Infrastructure\Factory\AiIntegrationFactory;
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
use Providentia\AiIntegration\Infrastructure\Security\SodiumSensitiveBufferEraser;

final class ConfigProvider
{
    /** @return array<string, mixed> */
    public function __invoke(): array
    {
        return [
            'dependencies' => [
                'aliases' => [
                    AiStore::class => DbalAiStore::class,
                    AiMaturityStore::class => DbalAiStore::class,
                    CredentialCipher::class => NativeCredentialCipher::class,
                    JsonHttpClient::class => StreamJsonHttpClient::class,
                    MediaStorage::class => EncryptedFilesystemMediaStorage::class,
                    VideoProcessor::class => FfmpegVideoProcessor::class,
                    SensitiveBufferEraser::class => SodiumSensitiveBufferEraser::class,
                ],
                'factories' => [
                    DbalAiStore::class => AiIntegrationFactory::class,
                    NativeCredentialCipher::class => AiIntegrationFactory::class,
                    SodiumSensitiveBufferEraser::class => AiIntegrationFactory::class,
                    EndpointPolicy::class => AiIntegrationFactory::class,
                    StreamJsonHttpClient::class => AiIntegrationFactory::class,
                    ExtractionSchema::class => AiIntegrationFactory::class,
                    ProviderFailureClassifier::class => AiIntegrationFactory::class,
                    ExtractionReconciler::class => AiIntegrationFactory::class,
                    AiOrchestrator::class => AiIntegrationFactory::class,
                    EncryptedFilesystemMediaStorage::class => AiIntegrationFactory::class,
                    FfmpegVideoProcessor::class => AiIntegrationFactory::class,
                    PrivateMediaService::class => AiIntegrationFactory::class,
                    VideoProcessCommand::class => AiIntegrationFactory::class,
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
                    'ai.profiles.list' => AiIntegrationFactory::class,
                    'ai.profiles.put' => AiIntegrationFactory::class,
                    'ai.profiles.delete' => AiIntegrationFactory::class,
                    'ai.profiles.credential.delete' => AiIntegrationFactory::class,
                    'ai.policy.get' => AiIntegrationFactory::class,
                    'ai.policy.put' => AiIntegrationFactory::class,
                    'ai.extractions.create' => AiIntegrationFactory::class,
                    'ai.extractions.create-stored' => AiIntegrationFactory::class,
                    'ai.extractions.get' => AiIntegrationFactory::class,
                    'ai.candidates.review' => AiIntegrationFactory::class,
                    'ai.observations.review' => AiIntegrationFactory::class,
                    'ai.discrepancies.review' => AiIntegrationFactory::class,
                    'ai.media.upload' => AiIntegrationFactory::class,
                    'ai.media.list' => AiIntegrationFactory::class,
                    'ai.media.download' => AiIntegrationFactory::class,
                    'ai.media.delete' => AiIntegrationFactory::class,
                    'ai.media.retention' => AiIntegrationFactory::class,
                    'ai.media.export' => AiIntegrationFactory::class,
                ],
            ],
            'laminas-cli' => [
                'commands' => [
                    'ai:video:process' => VideoProcessCommand::class,
                ],
            ],
        ];
    }
}
