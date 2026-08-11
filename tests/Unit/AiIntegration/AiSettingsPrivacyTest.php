<?php

declare(strict_types=1);

namespace ProvidentiaTest\Unit\AiIntegration;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Providentia\AiIntegration\Application\AiMaturityStore;
use Providentia\AiIntegration\Application\AiProvider;
use Providentia\AiIntegration\Application\AiProviderRegistry;
use Providentia\AiIntegration\Application\AiService;
use Providentia\AiIntegration\Application\AiStore;
use Providentia\AiIntegration\Application\CredentialCipher;
use Providentia\AiIntegration\Application\ExtractionSchema;
use Providentia\AiIntegration\Application\Media\MediaStorage;
use Providentia\AiIntegration\Application\Media\PrivateMediaService;
use Providentia\AiIntegration\Application\Media\VideoProcessor;
use Providentia\AiIntegration\Application\Orchestration\AiOrchestrator;
use Providentia\AiIntegration\Application\Orchestration\ExtractionReconciler;
use Providentia\AiIntegration\Application\Orchestration\ProviderFailureClassifier;
use Providentia\AiIntegration\Domain\ExtractionOutcome;
use Providentia\AiIntegration\Domain\ExtractionRequest;
use Providentia\Home\Application\HomeAuthorization;
use Providentia\Home\Application\HomeStore;
use Providentia\Identity\Application\AuthenticatedIdentity;
use Providentia\SharedKernel\Application\Clock;
use Providentia\SharedKernel\Application\Problem;
use Providentia\SharedKernel\Application\TransactionManager;
use Providentia\SharedKernel\Application\UuidGenerator;

final class AiSettingsPrivacyTest extends TestCase
{
    private const HOME_ID = '01912345-6789-7abc-8def-0123456789ab';
    private const USER_ID = '01912345-6789-7abc-9def-0123456789ab';

    public function testSettingsDistinguishDirectTransitFromExplicitEncryptedStorage(): void
    {
        $settings = $this->aiService()->settings($this->identity(), self::HOME_ID);

        self::assertFalse($settings['serverPersistsUploadedMedia']);
        self::assertSame([
            'directExtractionUpload' => 'transient_not_persisted',
            'privateMediaStorage' => 'explicit_encrypted_opt_in',
            'privateMediaRetentionOptions' => ['transient', 'retained'],
            'plaintextMediaAtRest' => false,
            'cloudProviderTransmissionRequiresConsent' => true,
        ], $settings['mediaHandling']);
    }

    public function testPrivateMediaUploadRequiresAnExplicitRetentionChoice(): void
    {
        $storage = $this->createMock(MediaStorage::class);
        $storage->expects(self::never())->method('store');
        $maturity = $this->createMock(AiMaturityStore::class);
        $maturity->expects(self::never())->method('insertMediaWithinQuota');

        $this->expectException(Problem::class);
        $this->expectExceptionMessage('Choose transient or retained private media');
        $this->privateMedia($maturity, $storage)->upload(
            $this->identity(),
            self::HOME_ID,
            '',
            'image/png',
            null,
            'not-used-because-retention-fails-first',
        );
    }

    public function testDirectExtractionStoresMetadataButNeverCreatesAPrivateMediaObject(): void
    {
        $store = $this->createMock(AiStore::class);
        $store->method('targetExists')->with(self::HOME_ID, 'receipt', null)->willReturn(true);
        $store->method('settings')->with(self::HOME_ID)->willReturn([
            'mode' => 'server_proxy',
            'provider' => 'synthetic',
            'model' => 'synthetic-vision',
            'revision' => 1,
        ]);
        $store->expects(self::once())->method('startExtraction');
        $store->expects(self::once())->method('completeExtraction');
        $maturity = $this->createMock(AiMaturityStore::class);
        $maturity->method('orchestrationPolicy')->with(self::HOME_ID)->willReturn(null);
        $maturity->expects(self::never())->method('insertMediaWithinQuota');
        $storage = $this->createMock(MediaStorage::class);
        $storage->expects(self::never())->method('store');
        $provider = new class implements AiProvider {
            public function id(): string
            {
                return 'synthetic';
            }

            public function requiresCredential(): bool
            {
                return false;
            }

            public function extract(ExtractionRequest $request): ExtractionOutcome
            {
                return new ExtractionOutcome(AiSettingsPrivacyTest::emptyDocument(), [
                    'inputTokens' => 1,
                    'outputTokens' => 1,
                    'totalTokens' => 2,
                ]);
            }
        };
        $transactions = $this->createStub(TransactionManager::class);
        $transactions->method('transactional')->willReturnCallback(
            static fn (callable $operation): mixed => $operation(),
        );
        $ids = $this->createStub(UuidGenerator::class);
        $ids->method('generate')->willReturn('01912345-6789-7abc-adef-0123456789ab');
        $schema = new ExtractionSchema();
        $service = new AiService(
            $store,
            $maturity,
            new AiProviderRegistry([$provider]),
            $this->createStub(CredentialCipher::class),
            $schema,
            new AiOrchestrator(
                $schema,
                new ProviderFailureClassifier(),
                new ExtractionReconciler(),
            ),
            $this->privateMedia($maturity, $storage),
            $this->authorization(),
            $ids,
            $this->clock(),
            $transactions,
            8_388_608,
            8,
        );

        $result = $service->extract(
            $this->identity(),
            self::HOME_ID,
            'receipt',
            null,
            true,
            'image/png',
            "\x89PNG\r\n\x1A\n" . str_repeat('x', 20),
        );

        self::assertSame('review_required', $result['status']);
        self::assertSame(0, $result['candidateCount']);
    }

    private function aiService(): AiService
    {
        $store = $this->createStub(AiStore::class);
        $maturity = $this->createStub(AiMaturityStore::class);
        $schema = new ExtractionSchema();

        return new AiService(
            $store,
            $maturity,
            new AiProviderRegistry([]),
            $this->createStub(CredentialCipher::class),
            $schema,
            new AiOrchestrator(
                $schema,
                new ProviderFailureClassifier(),
                new ExtractionReconciler(),
            ),
            $this->privateMedia($maturity, $this->createStub(MediaStorage::class)),
            $this->authorization(),
            $this->createStub(UuidGenerator::class),
            $this->clock(),
            $this->createStub(TransactionManager::class),
            8_388_608,
            8,
        );
    }

    private function privateMedia(AiMaturityStore $maturity, MediaStorage $storage): PrivateMediaService
    {
        return new PrivateMediaService(
            $maturity,
            $storage,
            $this->createStub(VideoProcessor::class),
            $this->authorization(),
            $this->createStub(UuidGenerator::class),
            $this->clock(),
            268_435_456,
            8_388_608,
            134_217_728,
            86_400,
            67_108_864,
            8,
        );
    }

    private function authorization(): HomeAuthorization
    {
        $homes = $this->createStub(HomeStore::class);
        $homes->method('membership')->willReturn([
            'status' => 'active',
            'role' => HomeAuthorization::OWNER,
        ]);

        return new HomeAuthorization($homes);
    }

    private function clock(): Clock
    {
        $clock = $this->createStub(Clock::class);
        $clock->method('now')->willReturn(new DateTimeImmutable('2026-08-11T10:00:00+00:00'));

        return $clock;
    }

    private function identity(): AuthenticatedIdentity
    {
        return new AuthenticatedIdentity(self::USER_ID, 'session', 'device', self::HOME_ID, []);
    }

    /** @return array<string, mixed> */
    public static function emptyDocument(): array
    {
        return [
            'documentType' => 'receipt',
            'merchant' => null,
            'receiptNumber' => null,
            'purchaseDate' => null,
            'currency' => null,
            'totalAmount' => null,
            'taxAmount' => null,
            'notes' => null,
            'warnings' => [],
            'candidates' => [],
        ];
    }
}
