<?php

declare(strict_types=1);

namespace ProvidentiaTest\Unit\AiIntegration;

use DateTimeImmutable;
use Laminas\Diactoros\ServerRequest;
use Laminas\Diactoros\Stream;
use Laminas\Diactoros\UploadedFile;
use Laminas\Diactoros\Uri;
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
use Providentia\AiIntegration\Http\AiHandler;
use Providentia\Home\Application\HomeAuthorization;
use Providentia\Home\Application\HomeStore;
use Providentia\Identity\Application\AuthenticatedIdentity;
use Providentia\Identity\Http\BearerAuthenticationMiddleware;
use Providentia\SharedKernel\Application\Clock;
use Providentia\SharedKernel\Application\Problem;
use Providentia\SharedKernel\Application\TransactionManager;
use Providentia\SharedKernel\Application\UuidGenerator;

final class AiSettingsPrivacyTest extends TestCase
{
    private const HOME_ID = '01912345-6789-7abc-8def-0123456789ab';
    private const USER_ID = '01912345-6789-7abc-9def-0123456789ab';
    private const RECEIPT_ID = '01912345-6789-7abc-adef-0123456789ab';
    private const PROFILE_ID = '01912345-6789-7abc-bdef-0123456789ab';

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
        $store->method('targetExists')->with(self::HOME_ID, 'receipt', self::RECEIPT_ID)->willReturn(true);
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
            self::RECEIPT_ID,
            true,
            'image/png',
            "\x89PNG\r\n\x1A\n" . str_repeat('x', 20),
        );

        self::assertSame('review_required', $result['status']);
        self::assertSame(0, $result['candidateCount']);
    }

    public function testHttpBoundaryAcceptsImageAndTwoRepeatedImagesArrayParts(): void
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
        $maturity = $this->createStub(AiMaturityStore::class);
        $maturity->method('orchestrationPolicy')->willReturn(null);
        $provider = new class implements AiProvider {
            public int $calls = 0;

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
                $this->calls++;

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
        $ids->method('generate')->willReturn('01912345-6789-7abc-ddef-0123456789ab');
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
            $this->privateMedia($maturity, $this->createStub(MediaStorage::class)),
            $this->authorization(),
            $ids,
            $this->clock(),
            $transactions,
            8_388_608,
            8,
        );
        $request = (new ServerRequest(
            [],
            [],
            new Uri('https://app.example.test/api/v1/homes/' . self::HOME_ID . '/ai/extractions'),
            'POST',
            'php://memory',
        ))
            ->withAttribute('homeId', self::HOME_ID)
            ->withAttribute(BearerAuthenticationMiddleware::ATTRIBUTE, $this->identity())
            ->withParsedBody([
                'kind' => 'receipt',
                'transmissionConsent' => true,
            ])
            // Standard PHP parses repeated wire parts named `images[]` into
            // this PSR-7 `images` list.
            ->withUploadedFiles([
                'image' => $this->pngUpload('a'),
                'images' => [$this->pngUpload('b'), $this->pngUpload('c')],
            ]);

        $handler = new AiHandler($service, 'extractions.create', 8_388_608);
        /** @var array<string, mixed> $validBody */
        $validBody = $request->getParsedBody();
        foreach ([
            $request->withParsedBody([...$validBody, 'unexpected' => 'ignored']),
            $request->withParsedBody([...$validBody, 'transmissionConsent' => '1']),
            $request->withUploadedFiles([
                'image' => $this->pngUpload('a'),
                'images' => array_fill(0, 8, $this->pngUpload('b')),
            ]),
            $request->withUploadedFiles([
                'image' => $this->pngUpload('a'),
                'images' => [$this->pngUpload('b')],
                'unexpected' => $this->pngUpload('c'),
            ]),
        ] as $invalidRequest) {
            try {
                $handler->handle($invalidRequest);
                self::fail('An extraction multipart request outside the contract was accepted.');
            } catch (Problem $problem) {
                self::assertContains($problem->status, [413, 422]);
            }
        }

        $response = $handler->handle($request);
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(201, $response->getStatusCode());
        self::assertSame(3, $body['observationCount']);
        self::assertSame(3, $provider->calls);
    }

    public function testProfileCredentialRevocationIsPrivacySafeAndDoesNotConsultPolicy(): void
    {
        $maturity = $this->createMock(AiMaturityStore::class);
        $maturity->method('providerProfile')
            ->with(self::HOME_ID, self::PROFILE_ID)
            ->willReturn($this->providerProfile());
        $maturity->expects(self::never())->method('orchestrationPolicy');
        $maturity->expects(self::once())
            ->method('revokeProviderProfileCredential')
            ->with(
                '01912345-6789-7abc-cdef-0123456789ab',
                self::HOME_ID,
                self::PROFILE_ID,
                3,
                self::USER_ID,
                self::isInstanceOf(DateTimeImmutable::class),
            )
            ->willReturn(true);
        $ids = $this->createStub(UuidGenerator::class);
        $ids->method('generate')->willReturn('01912345-6789-7abc-cdef-0123456789ab');

        $profile = $this->aiService($maturity, $ids)->revokeProviderProfileCredential(
            $this->identity(),
            self::HOME_ID,
            self::PROFILE_ID,
            3,
        );

        self::assertSame([
            'id' => self::PROFILE_ID,
            'label' => 'Primary',
            'provider' => 'openai',
            'model' => 'gpt-vision',
            'credentialConfigured' => false,
            'lastFour' => null,
            'estimatedCostMicros' => 250,
            'revision' => 4,
        ], $profile);
        foreach (['ciphertext', 'nonce', 'keyVersion', 'credential'] as $secret) {
            self::assertArrayNotHasKey($secret, $profile);
        }
    }

    public function testStoredExtractionRejectsTruthyStringsAsTransmissionConsent(): void
    {
        $request = (new ServerRequest(
            [],
            [],
            new Uri('https://app.example.test/api/v1/homes/' . self::HOME_ID . '/ai/extractions/from-media'),
            'POST',
            'php://memory',
        ))
            ->withAttribute('homeId', self::HOME_ID)
            ->withAttribute(BearerAuthenticationMiddleware::ATTRIBUTE, $this->identity())
            ->withParsedBody([
                'kind' => 'receipt',
                'transmissionConsent' => 'yes',
                'assetIds' => ['01912345-6789-7abc-ddef-0123456789ab'],
            ]);

        try {
            (new AiHandler($this->aiService(), 'extractions.create-stored', 8_388_608))->handle($request);
            self::fail('A truthy JSON string was accepted as transmission consent.');
        } catch (Problem $problem) {
            self::assertSame(422, $problem->status);
            self::assertSame('Transmission consent required', $problem->title);
        }
    }

    public function testAlreadyClearProfileCredentialRevocationIsAnIdempotentCurrentRevisionRead(): void
    {
        $profile = $this->providerProfile();
        $profile['ciphertext'] = null;
        $profile['nonce'] = null;
        $profile['keyVersion'] = null;
        $profile['lastFour'] = null;
        $maturity = $this->createMock(AiMaturityStore::class);
        $maturity->method('providerProfile')->willReturn($profile);
        $maturity->expects(self::never())->method('revokeProviderProfileCredential');

        $result = $this->aiService($maturity)->revokeProviderProfileCredential(
            $this->identity(),
            self::HOME_ID,
            self::PROFILE_ID,
            3,
        );

        self::assertFalse($result['credentialConfigured']);
        self::assertSame(3, $result['revision']);
    }

    public function testProfileCredentialRevocationRejectsAStaleRevisionBeforeWriting(): void
    {
        $maturity = $this->createMock(AiMaturityStore::class);
        $maturity->method('providerProfile')->willReturn($this->providerProfile());
        $maturity->expects(self::never())->method('revokeProviderProfileCredential');

        $this->expectException(Problem::class);
        $this->expectExceptionMessage('changed on another device');
        $this->aiService($maturity)->revokeProviderProfileCredential(
            $this->identity(),
            self::HOME_ID,
            self::PROFILE_ID,
            2,
        );
    }

    public function testProfileCredentialRevocationConcealsAWrongHomeProfile(): void
    {
        $maturity = $this->createMock(AiMaturityStore::class);
        $maturity->method('providerProfile')->with(self::HOME_ID, self::PROFILE_ID)->willReturn(null);
        $maturity->expects(self::never())->method('revokeProviderProfileCredential');

        try {
            $this->aiService($maturity)->revokeProviderProfileCredential(
                $this->identity(),
                self::HOME_ID,
                self::PROFILE_ID,
                3,
            );
            self::fail('A provider profile from another home was exposed.');
        } catch (Problem $problem) {
            self::assertSame(404, $problem->status);
        }
    }

    private function aiService(
        ?AiMaturityStore $maturity = null,
        ?UuidGenerator $ids = null,
    ): AiService
    {
        $store = $this->createStub(AiStore::class);
        $maturity ??= $this->createStub(AiMaturityStore::class);
        $ids ??= $this->createStub(UuidGenerator::class);
        $transactions = $this->createStub(TransactionManager::class);
        $transactions->method('transactional')->willReturnCallback(
            static fn (callable $operation): mixed => $operation(),
        );
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
            $ids,
            $this->clock(),
            $transactions,
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

    private function pngUpload(string $marker): UploadedFile
    {
        $bytes = "\x89PNG\r\n\x1A\n" . str_repeat($marker, 20);
        $stream = new Stream('php://temp', 'wb+');
        $stream->write($bytes);
        $stream->rewind();

        return new UploadedFile($stream, strlen($bytes), UPLOAD_ERR_OK, $marker . '.png', 'image/png');
    }

    /** @return array<string, mixed> */
    private function providerProfile(): array
    {
        return [
            'id' => self::PROFILE_ID,
            'label' => 'Primary',
            'provider' => 'openai',
            'model' => 'gpt-vision',
            'ciphertext' => 'encrypted-secret',
            'nonce' => 'nonce',
            'keyVersion' => 1,
            'lastFour' => '1234',
            'estimatedCostMicros' => 250,
            'status' => 'active',
            'revision' => 3,
        ];
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
