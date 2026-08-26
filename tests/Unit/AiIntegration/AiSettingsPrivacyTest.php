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
use Providentia\AiIntegration\Application\AiProviderException;
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
use Providentia\AiIntegration\Application\ProfileEndpointPolicy;
use Providentia\AiIntegration\Application\SensitiveBufferEraser;
use Providentia\AiIntegration\Domain\ExtractionOutcome;
use Providentia\AiIntegration\Domain\ExtractionRequest;
use Providentia\AiIntegration\Http\AiHandler;
use Providentia\AiIntegration\Infrastructure\Security\SodiumSensitiveBufferEraser;
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
    private const OTHER_USER_ID = '01912345-6789-7abc-9def-9876543210ab';
    private const RECEIPT_ID = '01912345-6789-7abc-adef-0123456789ab';
    private const PROFILE_ID = '01912345-6789-7abc-bdef-0123456789ab';
    private const COMPATIBLE_ENDPOINT = 'https://vision.example.test/v1/chat/completions';

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
                new SodiumSensitiveBufferEraser(),
            ),
            $this->privateMedia($maturity, $storage),
            $this->authorization(),
            $ids,
            $this->clock(),
            $transactions,
            8_388_608,
            8,
            new SodiumSensitiveBufferEraser(),
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
        $buffers = new RecordingSensitiveBufferEraser();
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
                $buffers,
            ),
            $this->privateMedia($maturity, $this->createStub(MediaStorage::class)),
            $this->authorization(),
            $ids,
            $this->clock(),
            $transactions,
            8_388_608,
            8,
            $buffers,
        );
        $request = fn (): ServerRequest => (new ServerRequest(
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

        $handler = new AiHandler(
            $service,
            'extractions.create',
            8_388_608,
            $buffers,
        );
        foreach (
            [
                $request()->withParsedBody([
                    'kind' => 'receipt',
                    'transmissionConsent' => true,
                    'unexpected' => 'ignored',
                ]),
                $request()->withParsedBody([
                    'kind' => 'receipt',
                    'transmissionConsent' => '1',
                ]),
                $request()->withUploadedFiles([
                    'image' => $this->pngUpload('a'),
                    'images' => array_fill(0, 8, $this->pngUpload('b')),
                ]),
                $request()->withUploadedFiles([
                    'image' => $this->pngUpload('a'),
                    'images' => [$this->pngUpload('b')],
                    'unexpected' => $this->pngUpload('c'),
                ]),
            ] as $invalidRequest
        ) {
            try {
                $handler->handle($invalidRequest);
                self::fail('An extraction multipart request outside the contract was accepted.');
            } catch (Problem $problem) {
                self::assertContains($problem->status, [413, 422]);
            }
        }

        $validRequest = $request();
        $validUploads = $validRequest->getUploadedFiles();
        $response = $handler->handle($validRequest);
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(201, $response->getStatusCode());
        self::assertSame(3, $body['observationCount']);
        self::assertSame(3, $provider->calls);
        foreach (['a', 'b', 'c'] as $marker) {
            self::assertContains("\x89PNG\r\n\x1A\n" . str_repeat($marker, 20), $buffers->erased);
        }
        $validImage = $validUploads['image'] ?? null;
        $validImages = $validUploads['images'] ?? null;
        self::assertInstanceOf(UploadedFile::class, $validImage);
        self::assertIsArray($validImages);
        self::assertFalse($validImage->getStream()->isReadable());
        foreach ($validImages as $upload) {
            self::assertInstanceOf(UploadedFile::class, $upload);
            self::assertFalse($upload->getStream()->isReadable());
        }
    }

    public function testHttpBoundaryErasesReadBytesAndClosesEveryStreamWhenLaterValidationFails(): void
    {
        $buffers = new RecordingSensitiveBufferEraser();
        $primary = $this->pngUpload('s');
        $shortStream = new Stream('php://temp', 'wb+');
        $shortStream->write('short');
        $shortStream->rewind();
        $short = new UploadedFile($shortStream, 5, UPLOAD_ERR_OK, 'short.png', 'image/png');
        $request = (new ServerRequest(
            [],
            [],
            new Uri('https://app.example.test/api/v1/homes/' . self::HOME_ID . '/ai/extractions'),
            'POST',
            'php://memory',
        ))
            ->withAttribute('homeId', self::HOME_ID)
            ->withAttribute(BearerAuthenticationMiddleware::ATTRIBUTE, $this->identity())
            ->withParsedBody(['kind' => 'stock', 'transmissionConsent' => true])
            ->withUploadedFiles(['image' => $primary, 'images' => [$short]]);

        try {
            (new AiHandler(
                $this->aiService(),
                'extractions.create',
                8_388_608,
                $buffers,
            ))->handle($request);
            self::fail('An undersized additional observation was accepted.');
        } catch (Problem $problem) {
            self::assertSame(413, $problem->status);
        }

        self::assertContains("\x89PNG\r\n\x1A\n" . str_repeat('s', 20), $buffers->erased);
        self::assertFalse($primary->getStream()->isReadable());
        self::assertFalse($short->getStream()->isReadable());
    }

    public function testHttpBoundaryErasesAllBuffersAndClosesStreamsWhenTheProviderFails(): void
    {
        $buffers = new RecordingSensitiveBufferEraser();
        $store = $this->createMock(AiStore::class);
        $store->method('targetExists')->with(self::HOME_ID, 'stock', null)->willReturn(true);
        $store->method('settings')->with(self::HOME_ID)->willReturn([
            'mode' => 'server_proxy',
            'provider' => 'failing',
            'model' => 'synthetic-vision',
            'revision' => 1,
        ]);
        $store->expects(self::once())->method('startExtraction');
        $store->expects(self::once())->method('failExtraction');
        $maturity = $this->createStub(AiMaturityStore::class);
        $maturity->method('orchestrationPolicy')->willReturn(null);
        $provider = new class implements AiProvider {
            public function id(): string
            {
                return 'failing';
            }

            public function requiresCredential(): bool
            {
                return false;
            }

            public function extract(ExtractionRequest $request): never
            {
                throw new AiProviderException('provider_refusal', 'The material was refused.');
            }
        };
        $primary = $this->pngUpload('p');
        $additional = $this->pngUpload('q');
        $request = (new ServerRequest(
            [],
            [],
            new Uri('https://app.example.test/api/v1/homes/' . self::HOME_ID . '/ai/extractions'),
            'POST',
            'php://memory',
        ))
            ->withAttribute('homeId', self::HOME_ID)
            ->withAttribute(BearerAuthenticationMiddleware::ATTRIBUTE, $this->identity())
            ->withParsedBody(['kind' => 'stock', 'transmissionConsent' => true])
            ->withUploadedFiles(['image' => $primary, 'images' => [$additional]]);

        try {
            (new AiHandler(
                $this->directExtractionService($store, $maturity, $provider, $buffers),
                'extractions.create',
                8_388_608,
                $buffers,
            ))->handle($request);
            self::fail('A failed provider request was reported as successful.');
        } catch (Problem $problem) {
            self::assertSame(502, $problem->status);
        }

        foreach (['p', 'q'] as $marker) {
            self::assertContains("\x89PNG\r\n\x1A\n" . str_repeat($marker, 20), $buffers->erased);
        }
        self::assertFalse($primary->getStream()->isReadable());
        self::assertFalse($additional->getStream()->isReadable());
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
            'ownerScope' => 'home',
            'endpoint' => null,
            'credentialConfigured' => false,
            'lastFour' => null,
            'estimatedCostMicros' => 250,
            'revision' => 4,
        ], $profile);
        foreach (['ownerUserId', 'owner_user_id', 'updatedByUserId'] as $ownerField) {
            self::assertArrayNotHasKey($ownerField, $profile);
        }
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
            (new AiHandler(
                $this->aiService(),
                'extractions.create-stored',
                8_388_608,
                new SodiumSensitiveBufferEraser(),
            ))->handle($request);
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

    public function testPrivateProfileCreationBindsOwnerScopedAssociatedDataAndHidesOwnerIds(): void
    {
        $maturity = $this->createStub(AiMaturityStore::class);
        $maturity->method('providerProfile')->willReturn(null);
        $saved = null;
        $maturity->method('saveProviderProfile')->willReturnCallback(
            static function (array $profile) use (&$saved): bool {
                $saved = $profile;

                return true;
            },
        );
        $cipher = $this->createMock(CredentialCipher::class);
        $cipher->method('available')->willReturn(true);
        $cipher->expects(self::once())->method('encrypt')
            ->with(
                'compatible-secret-0001',
                'providentia-ai-profile:v2:' . self::HOME_ID . ':' . self::USER_ID . ':' . self::PROFILE_ID,
            )
            ->willReturn(['ciphertext' => 'cipher', 'nonce' => 'nonce', 'keyVersion' => 1]);

        $result = $this->profileService($maturity, $cipher, HomeAuthorization::MANAGER)->putProviderProfile(
            $this->identity(),
            self::HOME_ID,
            self::PROFILE_ID,
            'My compatible key',
            'openai-compatible',
            'vision',
            'compatible-secret-0001',
            250,
            0,
            'private',
            self::COMPATIBLE_ENDPOINT,
        );

        self::assertSame('private', $result['ownerScope']);
        self::assertSame(self::COMPATIBLE_ENDPOINT, $result['endpoint']);
        self::assertArrayNotHasKey('ownerUserId', $result);
        self::assertIsArray($saved);
        self::assertSame(self::USER_ID, $saved['ownerUserId']);
        self::assertSame(self::COMPATIBLE_ENDPOINT, $saved['endpoint']);
    }

    public function testHomeSharedProfileWritesAreADeliberateHomeOwnerChoice(): void
    {
        $maturity = $this->createMock(AiMaturityStore::class);
        $maturity->method('providerProfile')->willReturn(null);
        $maturity->expects(self::never())->method('saveProviderProfile');

        try {
            $this->profileService($maturity, null, HomeAuthorization::MANAGER)->putProviderProfile(
                $this->identity(),
                self::HOME_ID,
                self::PROFILE_ID,
                'Household key',
                'ollama',
                'llava',
                null,
                0,
                0,
                'home',
            );
            self::fail('A non-owner created a home-shared provider profile.');
        } catch (Problem $problem) {
            self::assertSame(403, $problem->status);
        }

        $owning = $this->createStub(AiMaturityStore::class);
        $owning->method('providerProfile')->willReturn(null);
        $saved = null;
        $owning->method('saveProviderProfile')->willReturnCallback(
            static function (array $profile) use (&$saved): bool {
                $saved = $profile;

                return true;
            },
        );
        $result = $this->profileService($owning, null, HomeAuthorization::OWNER)->putProviderProfile(
            $this->identity(),
            self::HOME_ID,
            self::PROFILE_ID,
            'Household key',
            'ollama',
            'llava',
            null,
            0,
            0,
            'home',
        );

        self::assertSame('home', $result['ownerScope']);
        self::assertIsArray($saved);
        self::assertNull($saved['ownerUserId']);
    }

    public function testAnotherMembersPrivateProfileIsUnaddressableByEveryProfileWrite(): void
    {
        $foreign = $this->providerProfile();
        $foreign['ownerUserId'] = self::OTHER_USER_ID;
        $maturity = $this->createMock(AiMaturityStore::class);
        $maturity->method('providerProfile')->willReturn($foreign);
        $maturity->expects(self::never())->method('saveProviderProfile');
        $maturity->expects(self::never())->method('revokeProviderProfile');
        $maturity->expects(self::never())->method('revokeProviderProfileCredential');
        $service = $this->profileService($maturity, null, HomeAuthorization::OWNER);

        $writes = [
            fn (): array => $service->putProviderProfile(
                $this->identity(),
                self::HOME_ID,
                self::PROFILE_ID,
                'Takeover',
                'openai-compatible',
                'vision',
                'compatible-secret-0001',
                250,
                3,
                'private',
            ),
            function () use ($service): void {
                $service->removeProviderProfile($this->identity(), self::HOME_ID, self::PROFILE_ID, 3);
            },
            fn (): array => $service->revokeProviderProfileCredential(
                $this->identity(),
                self::HOME_ID,
                self::PROFILE_ID,
                3,
            ),
        ];
        foreach ($writes as $write) {
            try {
                $write();
                self::fail('Another member\'s private provider profile was addressable.');
            } catch (Problem $problem) {
                self::assertSame(404, $problem->status);
            }
        }
    }

    public function testHomeSharedProfileDeletionRequiresTheOwnerWhilePrivateOnesStayManageable(): void
    {
        $shared = $this->providerProfile();
        $shared['ownerUserId'] = null;
        $maturity = $this->createMock(AiMaturityStore::class);
        $maturity->method('providerProfile')->willReturn($shared);
        $maturity->expects(self::never())->method('revokeProviderProfile');

        try {
            $this->profileService($maturity, null, HomeAuthorization::MANAGER)->removeProviderProfile(
                $this->identity(),
                self::HOME_ID,
                self::PROFILE_ID,
                3,
            );
            self::fail('A non-owner deleted a home-shared provider profile.');
        } catch (Problem $problem) {
            self::assertSame(403, $problem->status);
        }

        $own = $this->providerProfile();
        $own['ownerUserId'] = self::USER_ID;
        $owned = $this->createMock(AiMaturityStore::class);
        $owned->method('providerProfile')->willReturn($own);
        $owned->method('orchestrationPolicy')->willReturn(null);
        $owned->expects(self::once())->method('revokeProviderProfile')
            ->with(self::HOME_ID, self::PROFILE_ID, 3, self::USER_ID)
            ->willReturn(true);

        $this->profileService($owned, null, HomeAuthorization::MANAGER)->removeProviderProfile(
            $this->identity(),
            self::HOME_ID,
            self::PROFILE_ID,
            3,
        );
    }

    public function testProfileListingsAreScopedToTheRequestingPerson(): void
    {
        $own = $this->providerProfile();
        $own['ownerUserId'] = self::USER_ID;
        $own['endpoint'] = self::COMPATIBLE_ENDPOINT;
        $maturity = $this->createMock(AiMaturityStore::class);
        $maturity->expects(self::once())->method('providerProfiles')
            ->with(self::HOME_ID, self::USER_ID)
            ->willReturn([$own]);

        $profiles = $this->profileService($maturity, null, HomeAuthorization::MEMBER)
            ->providerProfiles($this->identity(), self::HOME_ID);

        self::assertCount(1, $profiles);
        self::assertSame('private', $profiles[0]['ownerScope']);
        self::assertSame(self::COMPATIBLE_ENDPOINT, $profiles[0]['endpoint']);
        self::assertArrayNotHasKey('ownerUserId', $profiles[0]);
    }

    public function testOwnerScopeConversionCannotCarryTheOldCredentialCiphertext(): void
    {
        $own = $this->providerProfile();
        $own['ownerUserId'] = self::USER_ID;
        $own['provider'] = 'openai-compatible';
        $maturity = $this->createMock(AiMaturityStore::class);
        $maturity->method('providerProfile')->willReturn($own);
        $maturity->expects(self::never())->method('saveProviderProfile');

        try {
            $this->profileService($maturity, null, HomeAuthorization::OWNER)->putProviderProfile(
                $this->identity(),
                self::HOME_ID,
                self::PROFILE_ID,
                'Primary',
                'openai-compatible',
                'gpt-vision',
                null,
                250,
                3,
                'home',
            );
            self::fail('An owner-scope change silently reused a differently bound ciphertext.');
        } catch (Problem $problem) {
            self::assertSame(422, $problem->status);
            self::assertSame('AI credential missing', $problem->title);
        }
    }

    public function testProfileEndpointWritePolicyMatrix(): void
    {
        $rejected = [
            // Only the endpoint-owning adapters accept an endpoint at all.
            [false, 'openai', 'secret-credential-0001', 'https://api.example.test/v1'],
            // HTTPS is required for openai-compatible even under the LAN policy.
            [true, 'openai-compatible', 'compatible-secret-0001', 'http://vision.example.test/v1/chat/completions'],
            // Userinfo, query, and fragment parts can never be smuggled in.
            [true, 'openai-compatible', 'compatible-secret-0001', 'https://user:pw@vision.example.test/v1'],
            [true, 'openai-compatible', 'compatible-secret-0001', self::COMPATIBLE_ENDPOINT . '?redirect=x'],
            [true, 'openai-compatible', 'compatible-secret-0001', self::COMPATIBLE_ENDPOINT . '#fragment'],
            // Literal private, loopback, or link-local hosts are always
            // rejected for HTTPS endpoints, for every provider and policy.
            [true, 'openai-compatible', 'compatible-secret-0001', 'https://192.168.1.10/v1/chat/completions'],
            [true, 'ollama', null, 'https://127.0.0.1:11434/api/chat'],
            [false, 'ollama', null, 'https://[::1]:11434/api/chat'],
            // Plain HTTP and private hosts need the explicit Ollama LAN opt-in.
            [false, 'ollama', null, 'http://192.168.1.10:11434/api/chat'],
            [false, 'ollama', null, 'http://ollama.lan:11434/api/chat'],
        ];
        foreach ($rejected as [$lanPolicy, $provider, $credential, $endpoint]) {
            $maturity = $this->createMock(AiMaturityStore::class);
            $maturity->method('providerProfile')->willReturn(null);
            $maturity->expects(self::never())->method('saveProviderProfile');
            try {
                $this->profileService($maturity, null, HomeAuthorization::OWNER, $lanPolicy)->putProviderProfile(
                    $this->identity(),
                    self::HOME_ID,
                    self::PROFILE_ID,
                    'Endpoint under test',
                    $provider,
                    'vision',
                    $credential,
                    0,
                    0,
                    'private',
                    $endpoint,
                );
                self::fail('An endpoint outside the write policy was accepted: ' . $endpoint);
            } catch (Problem $problem) {
                self::assertSame(422, $problem->status, $endpoint);
                self::assertSame('Invalid AI endpoint', $problem->title, $endpoint);
            }
        }

        $accepted = [
            [false, 'openai-compatible', 'compatible-secret-0001', self::COMPATIBLE_ENDPOINT],
            [false, 'ollama', null, 'https://ollama.example.test/api/chat'],
            [true, 'ollama', null, 'http://192.168.1.10:11434/api/chat'],
            [true, 'ollama', null, 'http://127.0.0.1:11434/api/chat'],
        ];
        foreach ($accepted as [$lanPolicy, $provider, $credential, $endpoint]) {
            $maturity = $this->createStub(AiMaturityStore::class);
            $maturity->method('providerProfile')->willReturn(null);
            $saved = null;
            $maturity->method('saveProviderProfile')->willReturnCallback(
                static function (array $profile) use (&$saved): bool {
                    $saved = $profile;

                    return true;
                },
            );
            $result = $this->profileService($maturity, null, HomeAuthorization::OWNER, $lanPolicy)
                ->putProviderProfile(
                    $this->identity(),
                    self::HOME_ID,
                    self::PROFILE_ID,
                    'Endpoint under test',
                    $provider,
                    'vision',
                    $credential,
                    0,
                    0,
                    'private',
                    $endpoint,
                );
            self::assertSame($endpoint, $result['endpoint']);
            self::assertIsArray($saved);
            self::assertSame($endpoint, $saved['endpoint']);
        }
    }

    public function testScansPreferTheRequestingPersonsPrivateProfileOverTheHomeSharedOne(): void
    {
        $provider = new class implements AiProvider {
            /** @var list<string> */
            public array $models = [];

            /** @var list<string|null> */
            public array $endpoints = [];

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
                $this->models[] = $request->model;
                $this->endpoints[] = $request->endpoint;

                return new ExtractionOutcome(AiSettingsPrivacyTest::emptyDocument(), [
                    'inputTokens' => 1,
                    'outputTokens' => 1,
                    'totalTokens' => 2,
                ]);
            }
        };
        $shared = [
            'id' => 'shared-profile',
            'label' => 'Household synthetic',
            'provider' => 'synthetic',
            'model' => 'shared-model',
            'ownerUserId' => null,
            'endpoint' => null,
            'ciphertext' => null,
            'nonce' => null,
            'keyVersion' => null,
            'lastFour' => null,
            'estimatedCostMicros' => 10,
            'status' => 'active',
            'revision' => 1,
        ];
        $private = array_merge($shared, [
            'id' => 'private-profile',
            'label' => 'My synthetic',
            'model' => 'private-model',
            'ownerUserId' => self::USER_ID,
            'endpoint' => 'https://private.example.test/v1/chat/completions',
        ]);
        $store = $this->createMock(AiStore::class);
        $store->method('targetExists')->willReturn(true);
        $store->method('settings')->willReturn([
            'mode' => 'server_proxy',
            'provider' => null,
            'model' => null,
            'revision' => 2,
        ]);
        $store->expects(self::once())->method('startExtraction');
        $store->expects(self::once())->method('completeExtraction');
        $maturity = $this->createMock(AiMaturityStore::class);
        $maturity->method('orchestrationPolicy')->willReturn([
            'extractionProfileIds' => ['shared-profile'],
            'validationProfileId' => null,
            'maxAttempts' => 4,
            'maxTotalTokens' => 50000,
            'maxEstimatedCostMicros' => 1000000,
            'revision' => 1,
        ]);
        $maturity->method('providerProfiles')
            ->with(self::HOME_ID, self::USER_ID)
            ->willReturn([$shared, $private]);
        $attempts = [];
        $maturity->method('appendExtractionAttempt')->willReturnCallback(
            static function (string $extractionId, int $position, int $index, array $attempt) use (&$attempts): void {
                $attempts[] = $attempt;
            },
        );
        $transactions = $this->createStub(TransactionManager::class);
        $transactions->method('transactional')->willReturnCallback(
            static fn (callable $operation): mixed => $operation(),
        );
        $ids = $this->createStub(UuidGenerator::class);
        $ids->method('generate')->willReturn('01912345-6789-7abc-edef-0123456789ab');
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
                new SodiumSensitiveBufferEraser(),
            ),
            $this->privateMedia($maturity, $this->createStub(MediaStorage::class)),
            $this->authorization(),
            $ids,
            $this->clock(),
            $transactions,
            8_388_608,
            8,
            new SodiumSensitiveBufferEraser(),
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
        self::assertSame(['private-model'], $provider->models);
        self::assertSame(['https://private.example.test/v1/chat/completions'], $provider->endpoints);
        self::assertSame('private-profile', $attempts[0]['profileId'] ?? null);
    }

    public function testProfilePutHttpBoundaryForwardsOwnerScopeAndEndpoint(): void
    {
        $maturity = $this->createStub(AiMaturityStore::class);
        $maturity->method('providerProfile')->willReturn(null);
        $saved = null;
        $maturity->method('saveProviderProfile')->willReturnCallback(
            static function (array $profile) use (&$saved): bool {
                $saved = $profile;

                return true;
            },
        );
        $request = (new ServerRequest(
            [],
            [],
            new Uri('https://app.example.test/api/v1/homes/' . self::HOME_ID . '/ai/profiles/' . self::PROFILE_ID),
            'PUT',
            'php://memory',
        ))
            ->withAttribute('homeId', self::HOME_ID)
            ->withAttribute('profileId', self::PROFILE_ID)
            ->withAttribute(BearerAuthenticationMiddleware::ATTRIBUTE, $this->identity())
            ->withParsedBody([
                'label' => 'Household Ollama',
                'provider' => 'ollama',
                'model' => 'llava',
                'estimatedCostMicros' => 0,
                'expectedRevision' => 0,
                'ownerScope' => 'home',
                'endpoint' => 'https://ollama.example.test/api/chat',
            ]);

        $response = (new AiHandler(
            $this->profileService($maturity),
            'profiles.put',
            8_388_608,
            new SodiumSensitiveBufferEraser(),
        ))->handle($request);
        $body = json_decode((string) $response->getBody(), true, 16, JSON_THROW_ON_ERROR);

        self::assertSame(201, $response->getStatusCode());
        self::assertSame('home', $body['ownerScope']);
        self::assertSame('https://ollama.example.test/api/chat', $body['endpoint']);
        self::assertIsArray($saved);
        self::assertNull($saved['ownerUserId']);
        self::assertSame('https://ollama.example.test/api/chat', $saved['endpoint']);
    }

    private function profileService(
        AiMaturityStore $maturity,
        ?CredentialCipher $cipher = null,
        string $role = HomeAuthorization::OWNER,
        bool $allowPrivateNetworkEndpoints = false,
    ): AiService {
        if ($cipher === null) {
            $available = $this->createStub(CredentialCipher::class);
            $available->method('available')->willReturn(true);
            $available->method('encrypt')->willReturn([
                'ciphertext' => 'cipher',
                'nonce' => 'nonce',
                'keyVersion' => 1,
            ]);
            $cipher = $available;
        }
        $transactions = $this->createStub(TransactionManager::class);
        $transactions->method('transactional')->willReturnCallback(
            static fn (callable $operation): mixed => $operation(),
        );
        $schema = new ExtractionSchema();
        $registry = new AiProviderRegistry([
            $this->syntheticProvider('openai', true),
            $this->syntheticProvider('openai-compatible', true),
            $this->syntheticProvider('ollama', false),
        ]);

        return new AiService(
            $this->createStub(AiStore::class),
            $maturity,
            $registry,
            $cipher,
            $schema,
            new AiOrchestrator(
                $schema,
                new ProviderFailureClassifier(),
                new ExtractionReconciler(),
                new SodiumSensitiveBufferEraser(),
            ),
            $this->privateMedia($maturity, $this->createStub(MediaStorage::class)),
            $this->roleAuthorization($role),
            $this->createStub(UuidGenerator::class),
            $this->clock(),
            $transactions,
            8_388_608,
            8,
            new SodiumSensitiveBufferEraser(),
            new ProfileEndpointPolicy($allowPrivateNetworkEndpoints),
        );
    }

    private function syntheticProvider(string $id, bool $requiresCredential): AiProvider
    {
        return new class ($id, $requiresCredential) implements AiProvider {
            public function __construct(
                private readonly string $providerId,
                private readonly bool $requiresCredential,
            ) {
            }

            public function id(): string
            {
                return $this->providerId;
            }

            public function requiresCredential(): bool
            {
                return $this->requiresCredential;
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
    }

    private function roleAuthorization(string $role): HomeAuthorization
    {
        $homes = $this->createStub(HomeStore::class);
        $homes->method('membership')->willReturn([
            'status' => 'active',
            'role' => $role,
        ]);

        return new HomeAuthorization($homes);
    }

    private function aiService(
        ?AiMaturityStore $maturity = null,
        ?UuidGenerator $ids = null,
    ): AiService {
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
                new SodiumSensitiveBufferEraser(),
            ),
            $this->privateMedia($maturity, $this->createStub(MediaStorage::class)),
            $this->authorization(),
            $ids,
            $this->clock(),
            $transactions,
            8_388_608,
            8,
            new SodiumSensitiveBufferEraser(),
        );
    }

    private function directExtractionService(
        AiStore $store,
        AiMaturityStore $maturity,
        AiProvider $provider,
        SensitiveBufferEraser $buffers,
    ): AiService {
        $transactions = $this->createStub(TransactionManager::class);
        $transactions->method('transactional')->willReturnCallback(
            static fn (callable $operation): mixed => $operation(),
        );
        $ids = $this->createStub(UuidGenerator::class);
        $ids->method('generate')->willReturn('01912345-6789-7abc-fdef-0123456789ab');
        $schema = new ExtractionSchema();

        return new AiService(
            $store,
            $maturity,
            new AiProviderRegistry([$provider]),
            $this->createStub(CredentialCipher::class),
            $schema,
            new AiOrchestrator(
                $schema,
                new ProviderFailureClassifier(),
                new ExtractionReconciler(),
                $buffers,
            ),
            $this->privateMedia($maturity, $this->createStub(MediaStorage::class)),
            $this->authorization(),
            $ids,
            $this->clock(),
            $transactions,
            8_388_608,
            8,
            $buffers,
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
