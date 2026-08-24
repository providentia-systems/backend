<?php

declare(strict_types=1);

namespace ProvidentiaTest\Unit\Catalog;

use DateTimeImmutable;
use Laminas\Diactoros\ServerRequest;
use Laminas\Diactoros\Stream;
use Laminas\Diactoros\UploadedFile;
use Laminas\Diactoros\Uri;
use PHPUnit\Framework\TestCase;
use Providentia\Catalog\Application\CatalogAuditRecorder;
use Providentia\Catalog\Application\CatalogAuthorization;
use Providentia\Catalog\Application\CatalogContributionImageService;
use Providentia\Catalog\Application\CatalogContributionImageStore;
use Providentia\Catalog\Application\CatalogContributionSourceReader;
use Providentia\Catalog\Application\CatalogContributionStore;
use Providentia\Catalog\Application\CatalogGovernanceStore;
use Providentia\Catalog\Application\CatalogImageCipher;
use Providentia\Catalog\Application\CatalogImageSanitizer;
use Providentia\Catalog\Application\CatalogHomeAccess;
use Providentia\Catalog\Application\CatalogStore;
use Providentia\Catalog\Application\EncryptedCatalogImage;
use Providentia\Catalog\Application\SanitizedCatalogImage;
use Providentia\Catalog\Http\CatalogContributionImageHandler;
use Providentia\Identity\Application\AuthenticatedIdentity;
use Providentia\Identity\Http\BearerAuthenticationMiddleware;
use Providentia\SharedKernel\Application\Problem;
use Providentia\SharedKernel\Application\UuidGenerator;
use ProvidentiaTest\Unit\Home\HomeFixedClock;
use ProvidentiaTest\Unit\Home\RecordingTransactionManager;

final class CatalogContributionImageServiceTest extends TestCase
{
    private const HOME_ID = '01912345-6789-7abc-8def-0123456789ab';
    private const USER_ID = '01912345-6789-7abc-9def-0123456789ab';
    private const RECEIPT_ID = '01912345-6789-7abc-adef-0123456789ab';
    private const CONTRIBUTION_ID = '01912345-6789-7abc-bdef-0123456789ab';
    private const HOME_PRODUCT_ID = '01912345-6789-7abc-cdef-0123456789ab';
    private const PRODUCT_ID = '01912345-6789-7abc-ddef-0123456789ab';
    private const ICON_ID = '01912345-6789-7abc-edef-0123456789ab';
    private const ASSET_ID = '01912345-6789-7abc-8def-1123456789ab';
    private const RAW = 'source-jpeg-bytes';
    private const SANITIZED = 'sanitized-webp-bytes';

    public function testUploadStoresOnlySanitizedEncryptedMediaAndReturnsPrivateSourceBinding(): void
    {
        $contributions = $this->createMock(CatalogContributionStore::class);
        $contributions->method('consent')->willReturn($this->consent());
        $contributions->expects(self::once())->method('createContribution')->with(
            self::CONTRIBUTION_ID,
            self::HOME_ID,
            self::RECEIPT_ID,
            'product_image',
            self::HOME_PRODUCT_ID,
            self::callback(static fn (array $payload): bool =>
                $payload['sourceDigest'] === hash('sha256', self::RAW)
                && $payload['assetDigest'] === hash('sha256', self::SANITIZED)
                && $payload['rightsDeclarationVersion']
                    === CatalogContributionImageService::RIGHTS_DECLARATION_VERSION),
            self::USER_ID,
            self::isInstanceOf(DateTimeImmutable::class),
        )->willReturn([
            'outcome' => 'created',
            'record' => $this->record(),
        ]);
        $images = $this->createMock(CatalogContributionImageStore::class);
        $images->expects(self::once())->method('saveQuarantineImage')->with(
            self::CONTRIBUTION_ID,
            hash('sha256', self::SANITIZED),
            'image/webp',
            640,
            480,
            strlen(self::SANITIZED),
            self::isInstanceOf(EncryptedCatalogImage::class),
            self::isInstanceOf(DateTimeImmutable::class),
        );
        $audit = $this->createMock(CatalogAuditRecorder::class);
        $audit->expects(self::once())->method('recordAudit')->with(
            self::anything(),
            self::USER_ID,
            'catalog.contribution-image.submitted',
            'catalog_contribution',
            self::CONTRIBUTION_ID,
            self::HOME_ID,
            self::callback(static fn (string $details): bool =>
                str_contains($details, CatalogContributionImageService::RIGHTS_DECLARATION_VERSION)
                && ! str_contains($details, hash('sha256', self::RAW))),
            self::isInstanceOf(DateTimeImmutable::class),
        );

        $submission = $this->service($contributions, $images, audit: $audit)->upload(
            $this->homeIdentity(),
            self::HOME_ID,
            self::CONTRIBUTION_ID,
            self::HOME_PRODUCT_ID,
            4,
            'Front of the oats packet',
            CatalogContributionImageService::RIGHTS_DECLARATION_VERSION,
            hash('sha256', self::RAW),
            self::RAW,
        );

        self::assertTrue($submission->created);
        self::assertSame(self::CONTRIBUTION_ID, $submission->contribution['id']);
    }

    public function testExactUploadReplayDoesNotDuplicateQuarantineOrAudit(): void
    {
        $contributions = $this->createStub(CatalogContributionStore::class);
        $contributions->method('consent')->willReturn($this->consent());
        $contributions->method('createContribution')->willReturn([
            'outcome' => 'replayed',
            'record' => $this->record(),
        ]);
        $images = $this->createMock(CatalogContributionImageStore::class);
        $images->expects(self::never())->method('saveQuarantineImage');
        $images->method('quarantineImage')->willReturn([
            'assetDigest' => hash('sha256', self::SANITIZED),
        ]);
        $audit = $this->createMock(CatalogAuditRecorder::class);
        $audit->expects(self::never())->method('recordAudit');

        $submission = $this->service($contributions, $images, audit: $audit)->upload(
            $this->homeIdentity(),
            self::HOME_ID,
            self::CONTRIBUTION_ID,
            self::HOME_PRODUCT_ID,
            4,
            'Front of the oats packet',
            CatalogContributionImageService::RIGHTS_DECLARATION_VERSION,
            hash('sha256', self::RAW),
            self::RAW,
        );

        self::assertFalse($submission->created);
    }

    public function testUploadFailsClosedForAStaleConsentBeforeMediaProcessing(): void
    {
        $contributions = $this->createStub(CatalogContributionStore::class);
        $contributions->method('consent')->willReturn($this->consent());
        $sanitizer = $this->createMock(CatalogImageSanitizer::class);
        $sanitizer->expects(self::never())->method('sanitize');

        $this->expectException(Problem::class);
        $this->expectExceptionMessage('Product-image sharing is not currently enabled.');
        $this->service(
            $contributions,
            $this->createStub(CatalogContributionImageStore::class),
            sanitizer: $sanitizer,
        )->upload(
            $this->homeIdentity(),
            self::HOME_ID,
            self::CONTRIBUTION_ID,
            self::HOME_PRODUCT_ID,
            3,
            'Front of the oats packet',
            CatalogContributionImageService::RIGHTS_DECLARATION_VERSION,
            hash('sha256', self::RAW),
            self::RAW,
        );
    }

    public function testCrossHomeSourceIsPrivacySafeAndNeverCreatesAContribution(): void
    {
        $contributions = $this->createMock(CatalogContributionStore::class);
        $contributions->method('consent')->willReturn($this->consent());
        $contributions->expects(self::never())->method('createContribution');
        $sources = $this->createStub(CatalogContributionSourceReader::class);
        $sources->method('activeHomeProduct')->willReturn(null);

        try {
            $this->service(
                $contributions,
                $this->createStub(CatalogContributionImageStore::class),
                sources: $sources,
            )->upload(
                $this->homeIdentity(),
                self::HOME_ID,
                self::CONTRIBUTION_ID,
                self::HOME_PRODUCT_ID,
                4,
                'Front of the oats packet',
                CatalogContributionImageService::RIGHTS_DECLARATION_VERSION,
                hash('sha256', self::RAW),
                self::RAW,
            );
            self::fail('A cross-home source was accepted.');
        } catch (Problem $problem) {
            self::assertSame(404, $problem->status);
            self::assertSame('The requested resource is unavailable.', $problem->getMessage());
        }
    }

    public function testReviewerPreviewDecryptsOnlyTheCurrentRevision(): void
    {
        $images = $this->createStub(CatalogContributionImageStore::class);
        $images->method('quarantineImage')->willReturn([
            'contributionType' => 'product_image',
            'status' => 'approved',
            'revision' => 2,
            'assetDigest' => hash('sha256', self::SANITIZED),
            'mediaType' => 'image/webp',
            'width' => 640,
            'height' => 480,
            'byteSize' => strlen(self::SANITIZED),
            'ciphertext' => 'ciphertext',
            'nonce' => str_repeat('n', 24),
            'keyVersion' => 1,
            'payload' => ['altText' => 'Front of the oats packet'],
        ]);

        $content = $this->service(
            $this->createStub(CatalogContributionStore::class),
            $images,
        )->preview($this->operatorIdentity(CatalogAuthorization::REVIEWER), self::CONTRIBUTION_ID, 2);

        self::assertSame(self::SANITIZED, $content->bytes);
        self::assertSame('image/webp', $content->mediaType);
        self::assertSame('Front of the oats packet', $content->altText);
    }

    public function testMultipartHttpBoundaryRequiresExplicitConfirmationAndReturnsCreatedContribution(): void
    {
        $contributions = $this->createStub(CatalogContributionStore::class);
        $contributions->method('consent')->willReturn($this->consent());
        $contributions->method('createContribution')->willReturn([
            'outcome' => 'created',
            'record' => $this->record(),
        ]);
        $stream = new Stream('php://temp', 'wb+');
        $stream->write(self::RAW);
        $stream->rewind();
        $request = (new ServerRequest(
            [],
            [],
            new Uri('https://api.example.test/api/v1/homes/' . self::HOME_ID . '/catalog-contributions/images'),
            'POST',
            'php://memory',
        ))
            ->withAttribute('homeId', self::HOME_ID)
            ->withAttribute(BearerAuthenticationMiddleware::ATTRIBUTE, $this->homeIdentity())
            ->withParsedBody([
                'submissionId' => self::CONTRIBUTION_ID,
                'sourceEntityId' => self::HOME_PRODUCT_ID,
                'expectedConsentRevision' => 4,
                'altText' => 'Front of the oats packet',
                'sourceDigest' => hash('sha256', self::RAW),
                'rightsDeclarationVersion' => CatalogContributionImageService::RIGHTS_DECLARATION_VERSION,
                'submissionConfirmed' => 'true',
            ])
            ->withUploadedFiles(['image' => new UploadedFile(
                $stream,
                strlen(self::RAW),
                UPLOAD_ERR_OK,
                'oats.jpg',
                'image/jpeg',
            )]);

        $handler = new CatalogContributionImageHandler(
            $this->service(
                $contributions,
                $this->createStub(CatalogContributionImageStore::class),
            ),
            'upload',
            5242880,
        );
        $response = $handler->handle($request);

        self::assertSame(201, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true, 32, JSON_THROW_ON_ERROR);
        self::assertSame(self::CONTRIBUTION_ID, $body['id']);
        self::assertSame(hash('sha256', self::RAW), $body['payload']['sourceDigest']);

        /** @var array<string, mixed> $validBody */
        $validBody = $request->getParsedBody();
        foreach ([
            $request->withParsedBody(array_replace(
                $validBody,
                ['submissionConfirmed' => '1'],
            )),
            $request->withParsedBody(array_replace(
                $validBody,
                ['unexpected' => 'field'],
            )),
            $request->withParsedBody(array_replace(
                $validBody,
                ['sourceDigest' => strtoupper(hash('sha256', self::RAW))],
            )),
        ] as $invalidRequest) {
            try {
                $handler->handle($invalidRequest);
                self::fail('A non-contract multipart request was accepted.');
            } catch (Problem $problem) {
                self::assertSame(422, $problem->status);
            }
        }
    }

    public function testPublicDigestMustMatchTheExactLowercasePathValue(): void
    {
        $images = $this->createMock(CatalogContributionImageStore::class);
        $images->expects(self::never())->method('publicAsset');

        try {
            $this->service($this->createStub(CatalogContributionStore::class), $images)
                ->publicAsset(strtoupper(hash('sha256', self::SANITIZED)));
            self::fail('An uppercase public-asset digest was normalized.');
        } catch (Problem $problem) {
            self::assertSame(404, $problem->status);
        }
    }

    public function testPublicationHandlerRejectsFieldsOutsideTheClosedContract(): void
    {
        $images = $this->createMock(CatalogContributionImageStore::class);
        $images->expects(self::never())->method('imageForPublication');
        $handler = new CatalogContributionImageHandler(
            $this->service($this->createStub(CatalogContributionStore::class), $images),
            'publication',
            5242880,
        );
        $request = (new ServerRequest(
            [],
            [],
            new Uri('https://app.example.test/api/v1/catalog-contributions/'
                . self::CONTRIBUTION_ID . '/image-publication'),
            'PUT',
            'php://memory',
        ))
            ->withAttribute('contributionId', self::CONTRIBUTION_ID)
            ->withAttribute(
                BearerAuthenticationMiddleware::ATTRIBUTE,
                $this->operatorIdentity(CatalogAuthorization::CURATOR),
            )
            ->withParsedBody([
                'productId' => self::PRODUCT_ID,
                'expectedContributionRevision' => 2,
                'expectedIconRevision' => 2,
                'unexpected' => 'ignored',
            ]);

        try {
            $handler->handle($request);
            self::fail('An image-publication request outside the closed JSON contract was accepted.');
        } catch (Problem $problem) {
            self::assertSame(422, $problem->status);
        }
    }

    public function testDecryptionFailureReturnsAStableUnavailableProblem(): void
    {
        $images = $this->createStub(CatalogContributionImageStore::class);
        $images->method('quarantineImage')->willReturn([
            'contributionType' => 'product_image',
            'status' => 'pending',
            'revision' => 1,
            'assetDigest' => hash('sha256', self::SANITIZED),
            'mediaType' => 'image/webp',
            'width' => 640,
            'height' => 480,
            'byteSize' => strlen(self::SANITIZED),
            'ciphertext' => 'corrupt',
            'nonce' => str_repeat('n', 24),
            'keyVersion' => 1,
            'payload' => ['altText' => 'Front of package'],
        ]);
        $cipher = $this->createStub(CatalogImageCipher::class);
        $cipher->method('available')->willReturn(true);
        $cipher->method('decrypt')->willThrowException(new \RuntimeException('secret detail'));

        try {
            $this->service(
                $this->createStub(CatalogContributionStore::class),
                $images,
                cipher: $cipher,
            )->preview($this->operatorIdentity(CatalogAuthorization::REVIEWER), self::CONTRIBUTION_ID, 1);
            self::fail('Corrupt encrypted media was returned.');
        } catch (Problem $problem) {
            self::assertSame(503, $problem->status);
            self::assertStringNotContainsString('secret detail', $problem->getMessage());
        }
    }

    public function testBinaryPreviewBoundaryUsesNoStoreAndIntegrityHeaders(): void
    {
        $images = $this->createStub(CatalogContributionImageStore::class);
        $images->method('quarantineImage')->willReturn([
            'contributionType' => 'product_image',
            'status' => 'pending',
            'revision' => 1,
            'assetDigest' => hash('sha256', self::SANITIZED),
            'mediaType' => 'image/webp',
            'width' => 640,
            'height' => 480,
            'byteSize' => strlen(self::SANITIZED),
            'ciphertext' => 'ciphertext',
            'nonce' => str_repeat('n', 24),
            'keyVersion' => 1,
            'payload' => ['altText' => 'Front of package'],
        ]);
        $request = (new ServerRequest(
            [],
            [],
            new Uri('https://api.example.test/api/v1/catalog-contributions/'
                . self::CONTRIBUTION_ID . '/image-preview?expectedRevision=1'),
            'GET',
            'php://memory',
        ))
            ->withAttribute('contributionId', self::CONTRIBUTION_ID)
            ->withAttribute(
                BearerAuthenticationMiddleware::ATTRIBUTE,
                $this->operatorIdentity(CatalogAuthorization::REVIEWER),
            )
            ->withQueryParams(['expectedRevision' => '1']);

        $response = (new CatalogContributionImageHandler(
            $this->service($this->createStub(CatalogContributionStore::class), $images),
            'preview',
            5242880,
        ))->handle($request);

        self::assertSame('image/webp', $response->getHeaderLine('Content-Type'));
        self::assertSame('private, no-store', $response->getHeaderLine('Cache-Control'));
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        self::assertSame(hash('sha256', self::SANITIZED), $response->getHeaderLine('X-Content-SHA256'));
        self::assertSame(self::SANITIZED, (string) $response->getBody());
    }

    public function testApprovedImagePublishesToAnExplicitProductAndReturnsDurableLink(): void
    {
        $source = $this->publicationSource();
        $images = $this->createMock(CatalogContributionImageStore::class);
        $images->method('imageForPublication')->willReturn($source);
        $images->expects(self::once())->method('savePublicAsset')->willReturn([
            'id' => self::ASSET_ID,
            'assetDigest' => hash('sha256', self::SANITIZED),
        ]);
        $images->expects(self::once())->method('linkPublication')->willReturn(true);
        $images->expects(self::once())->method('deleteQuarantineImage')->with(self::CONTRIBUTION_ID);
        $images->method('publication')->willReturn($this->publication());
        $catalog = $this->createStub(CatalogStore::class);
        $catalog->method('product')->willReturn([
            'id' => self::PRODUCT_ID,
            'canonicalName' => 'Rolled oats',
        ]);
        $governanceStore = $this->createMock(CatalogGovernanceStore::class);
        $governanceStore->expects(self::once())->method('putIcon')->willReturn([
            'id' => self::ICON_ID,
            'revision' => 3,
        ]);
        $audit = $this->createMock(CatalogAuditRecorder::class);
        $audit->expects(self::once())->method('recordAudit')->with(
            self::anything(),
            self::USER_ID,
            'catalog.contribution-image.published',
            'catalog_icon',
            self::ICON_ID,
            null,
            self::callback(static fn (string $details): bool =>
                str_contains($details, self::CONTRIBUTION_ID)
                && str_contains($details, self::PRODUCT_ID)
                && str_contains($details, self::ASSET_ID)),
            self::isInstanceOf(DateTimeImmutable::class),
        );

        $result = $this->service(
            $this->createStub(CatalogContributionStore::class),
            $images,
            catalog: $catalog,
            audit: $audit,
            governanceStore: $governanceStore,
        )->publish(
            $this->operatorIdentity(CatalogAuthorization::CURATOR),
            self::CONTRIBUTION_ID,
            self::PRODUCT_ID,
            2,
            2,
        );

        self::assertSame($this->publication(), $result);
    }

    /** @return array<string, mixed> */
    private function consent(): array
    {
        return [
            'receiptId' => self::RECEIPT_ID,
            'revision' => 4,
            'shareProductIdentity' => false,
            'shareProductImages' => true,
            'shareStorePrices' => false,
        ];
    }

    /** @return array<string, mixed> */
    private function record(): array
    {
        return [
            'id' => self::CONTRIBUTION_ID,
            'contributionType' => 'product_image',
            'payload' => [
                'sourceDigest' => hash('sha256', self::RAW),
                'assetDigest' => hash('sha256', self::SANITIZED),
                'mediaType' => 'image/webp',
                'altText' => 'Front of the oats packet',
                'provenance' => 'homeowner_original',
                'rightsDeclarationVersion' => CatalogContributionImageService::RIGHTS_DECLARATION_VERSION,
                'reuseNoticeVersion' => CatalogContributionImageService::REUSE_NOTICE_VERSION,
            ],
            'status' => 'pending',
            'revision' => 1,
            'createdAt' => '2026-08-24T12:00:00+00:00',
        ];
    }

    /** @return array<string, mixed> */
    private function publicationSource(): array
    {
        return [
            'id' => self::CONTRIBUTION_ID,
            'contributionType' => 'product_image',
            'status' => 'approved',
            'revision' => 2,
            'payload' => ['altText' => 'Front of the oats packet'],
            'assetDigest' => hash('sha256', self::SANITIZED),
            'mediaType' => 'image/webp',
            'width' => 640,
            'height' => 480,
            'byteSize' => strlen(self::SANITIZED),
            'ciphertext' => 'ciphertext',
            'nonce' => str_repeat('n', 24),
            'keyVersion' => 1,
            'publishedProductId' => null,
        ];
    }

    /** @return array<string, mixed> */
    private function publication(): array
    {
        return [
            'contributionId' => self::CONTRIBUTION_ID,
            'contributionRevision' => 2,
            'productId' => self::PRODUCT_ID,
            'productName' => 'Rolled oats',
            'iconId' => self::ICON_ID,
            'iconRevision' => 3,
            'publishedAt' => '2026-08-24T12:00:00+00:00',
        ];
    }

    private function service(
        CatalogContributionStore $contributions,
        CatalogContributionImageStore $images,
        ?CatalogContributionSourceReader $sources = null,
        ?CatalogImageSanitizer $sanitizer = null,
        ?CatalogImageCipher $cipher = null,
        ?CatalogStore $catalog = null,
        ?CatalogAuditRecorder $audit = null,
        ?CatalogGovernanceStore $governanceStore = null,
    ): CatalogContributionImageService {
        if ($sources === null) {
            $sources = $this->createStub(CatalogContributionSourceReader::class);
            $sources->method('activeHomeProduct')->willReturn([
                'productId' => self::PRODUCT_ID,
                'packId' => null,
            ]);
        }
        if ($sanitizer === null) {
            $sanitizer = $this->createStub(CatalogImageSanitizer::class);
            $sanitizer->method('sanitize')->willReturn(new SanitizedCatalogImage(
                self::SANITIZED,
                hash('sha256', self::SANITIZED),
                'image/webp',
                640,
                480,
            ));
        }
        if ($cipher === null) {
            $cipher = $this->createStub(CatalogImageCipher::class);
            $cipher->method('available')->willReturn(true);
            $cipher->method('encrypt')->willReturn(new EncryptedCatalogImage(
                'ciphertext',
                str_repeat('n', 24),
                1,
            ));
            $cipher->method('decrypt')->willReturn(self::SANITIZED);
        }
        $catalog ??= $this->createStub(CatalogStore::class);
        $audit ??= $this->createStub(CatalogAuditRecorder::class);
        $governanceStore ??= $this->createStub(CatalogGovernanceStore::class);
        $ids = $this->createStub(UuidGenerator::class);
        $ids->method('generate')->willReturn(self::ASSET_ID);
        $clock = new HomeFixedClock(new DateTimeImmutable('2026-08-24T12:00:00+00:00'));
        $transactions = new RecordingTransactionManager();

        return new CatalogContributionImageService(
            $contributions,
            $images,
            $sources,
            $sanitizer,
            $cipher,
            $governanceStore,
            $catalog,
            $this->createStub(CatalogHomeAccess::class),
            new CatalogAuthorization(),
            $audit,
            $ids,
            $clock,
            $transactions,
        );
    }

    private function homeIdentity(): AuthenticatedIdentity
    {
        return new AuthenticatedIdentity(self::USER_ID, 'session', 'device', self::HOME_ID, []);
    }

    private function operatorIdentity(string $role): AuthenticatedIdentity
    {
        return new AuthenticatedIdentity(self::USER_ID, 'session', 'device', null, [$role]);
    }
}
