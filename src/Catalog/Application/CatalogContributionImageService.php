<?php

declare(strict_types=1);

namespace Providentia\Catalog\Application;

use DateTimeImmutable;
use DomainException;
use Providentia\Identity\Application\AuthenticatedIdentity;
use Providentia\SharedKernel\Application\Clock;
use Providentia\SharedKernel\Application\Problem;
use Providentia\SharedKernel\Application\TransactionManager;
use Providentia\SharedKernel\Application\UuidGenerator;

final class CatalogContributionImageService
{
    public const RIGHTS_DECLARATION_VERSION = 'homeowner_original_public_catalog_v1';
    public const REUSE_NOTICE_VERSION = 'catalog-image-public-reuse-v1';
    private const PUBLIC_PROVENANCE = 'moderated_public_catalog_image_v1';

    public function __construct(
        private readonly CatalogContributionStore $contributions,
        private readonly CatalogContributionImageStore $images,
        private readonly CatalogContributionSourceReader $sources,
        private readonly CatalogImageSanitizer $sanitizer,
        private readonly CatalogImageCipher $cipher,
        private readonly CatalogIconPublisher $icons,
        private readonly CatalogStore $catalog,
        private readonly CatalogHomeAccess $homes,
        private readonly CatalogAuthorization $catalogAuthorization,
        private readonly CatalogAuditRecorder $audit,
        private readonly UuidGenerator $ids,
        private readonly Clock $clock,
        private readonly TransactionManager $transactions,
    ) {
    }

    public function upload(
        AuthenticatedIdentity $identity,
        string $homeId,
        string $submissionId,
        string $sourceEntityId,
        int $expectedConsentRevision,
        string $altText,
        string $rightsDeclarationVersion,
        string $sourceDigest,
        string $uploadedBytes,
    ): CatalogContributionSubmission {
        $this->homes->requireContribution($identity, $homeId);
        $altText = trim($altText);
        if (
            ! $this->isUuid($submissionId)
            || ! $this->isUuid($sourceEntityId)
            || $expectedConsentRevision < 1
            || $altText === ''
            || mb_strlen($altText) > 191
            || preg_match('/[\x00-\x1F\x7F]/', $altText) === 1
            || $rightsDeclarationVersion !== self::RIGHTS_DECLARATION_VERSION
            || preg_match('/^[a-f0-9]{64}$/', $sourceDigest) !== 1
            || ! hash_equals($sourceDigest, hash('sha256', $uploadedBytes))
        ) {
            throw new Problem(422, 'Invalid image contribution', 'Image contribution fields are invalid.');
        }
        $consent = $this->contributions->consent($homeId);
        if (
            $consent === null
            || (int) $consent['revision'] !== $expectedConsentRevision
            || ! (bool) $consent['shareProductImages']
        ) {
            throw new Problem(409, 'Sharing consent required', 'Product-image sharing is not currently enabled.');
        }
        if (! $this->cipher->available()) {
            throw new Problem(503, 'Image contribution unavailable', 'Catalog image encryption is unavailable.');
        }
        try {
            $image = $this->sanitizer->sanitize($uploadedBytes);
        } catch (CatalogImageRejection $rejection) {
            throw new Problem($rejection->status, 'Image rejected', $rejection->getMessage());
        }
        $payload = [
            'sourceDigest' => $sourceDigest,
            'assetDigest' => $image->digest,
            'mediaType' => $image->mediaType,
            'altText' => $altText,
            'provenance' => 'homeowner_original',
            'rightsDeclarationVersion' => self::RIGHTS_DECLARATION_VERSION,
            'reuseNoticeVersion' => self::REUSE_NOTICE_VERSION,
        ];
        $encrypted = $this->cipher->encrypt(
            $image->bytes,
            $this->quarantineAssociatedData($submissionId, $image->digest),
        );
        $now = $this->clock->now();

        return $this->transactions->transactional(function () use (
            $identity,
            $homeId,
            $submissionId,
            $sourceEntityId,
            $consent,
            $payload,
            $image,
            $encrypted,
            $now,
        ): CatalogContributionSubmission {
            if ($this->sources->activeHomeProduct($homeId, $sourceEntityId) === null) {
                throw new Problem(404, 'Not found', 'The requested resource is unavailable.');
            }
            $submission = $this->contributions->createContribution(
                $submissionId,
                $homeId,
                (string) $consent['receiptId'],
                'product_image',
                $sourceEntityId,
                $payload,
                $identity->userId,
                $now,
            );
            if (($submission['outcome'] ?? 'conflict') === 'conflict') {
                throw new Problem(
                    409,
                    'Image contribution conflict',
                    'The submission identifier, image, source, or consent changed.',
                );
            }
            if (($submission['outcome'] ?? null) === 'created') {
                $this->images->saveQuarantineImage(
                    $submissionId,
                    $image->digest,
                    $image->mediaType,
                    $image->width,
                    $image->height,
                    strlen($image->bytes),
                    $encrypted,
                    $now,
                );
                $this->audit->recordAudit(
                    $this->ids->generate(),
                    $identity->userId,
                    'catalog.contribution-image.submitted',
                    'catalog_contribution',
                    $submissionId,
                    $homeId,
                    json_encode([
                        'type' => 'product_image',
                        'consentRevision' => (int) $consent['revision'],
                        'rightsDeclarationVersion' => self::RIGHTS_DECLARATION_VERSION,
                        'reuseNoticeVersion' => self::REUSE_NOTICE_VERSION,
                    ], JSON_THROW_ON_ERROR),
                    $now,
                );
            } else {
                $existing = $this->images->quarantineImage($submissionId);
                $record = $submission['record'] ?? [];
                $intentionallyPurged = in_array(
                    (string) ($record['status'] ?? ''),
                    ['rejected', 'withdrawn'],
                    true,
                ) || $this->images->publication($submissionId) !== null;
                if (
                    ($existing === null && ! $intentionallyPurged)
                    || ($existing !== null && (string) $existing['assetDigest'] !== $image->digest)
                ) {
                    throw new Problem(409, 'Image contribution conflict', 'The stored image is unavailable.');
                }
            }

            $record = $submission['record'] ?? throw new \LogicException('Image submission has no record.');

            return new CatalogContributionSubmission(
                ($submission['outcome'] ?? null) === 'created',
                $record,
            );
        });
    }

    public function preview(
        AuthenticatedIdentity $identity,
        string $contributionId,
        int $expectedRevision,
    ): CatalogImageContent {
        $this->catalogAuthorization->requireReviewer($identity);
        $this->requireCipher();
        if (! $this->isUuid($contributionId) || $expectedRevision < 1) {
            throw new Problem(422, 'Invalid image preview', 'A contribution and current revision are required.');
        }
        $row = $this->images->quarantineImage($contributionId);
        if ($row === null || (string) $row['contributionType'] !== 'product_image') {
            throw new Problem(404, 'Not found', 'The requested resource is unavailable.');
        }
        if ((int) $row['revision'] !== $expectedRevision) {
            throw new Problem(409, 'Contribution conflict', 'The contribution changed since it was read.');
        }

        return $this->contentFromEncrypted($contributionId, $row, false);
    }

    /** @return array<string, mixed> */
    public function publish(
        AuthenticatedIdentity $identity,
        string $contributionId,
        string $productId,
        int $expectedContributionRevision,
        int $expectedIconRevision,
    ): array {
        $this->catalogAuthorization->requireCurator($identity);
        $this->requireCipher();
        if (
            ! $this->isUuid($contributionId)
            || ! $this->isUuid($productId)
            || $expectedContributionRevision < 1
            || $expectedIconRevision < 0
        ) {
            throw new Problem(422, 'Invalid image publication', 'Valid targets and revisions are required.');
        }
        try {
            return $this->transactions->transactional(function () use (
                $identity,
                $contributionId,
                $productId,
                $expectedContributionRevision,
                $expectedIconRevision,
            ): array {
                $source = $this->images->imageForPublication($contributionId);
                $this->assertPublishableSource($source, $expectedContributionRevision);
                if ($source['publishedProductId'] !== null) {
                    return $this->exactPublicationReplay(
                        $contributionId,
                        $productId,
                        $expectedContributionRevision,
                        $expectedIconRevision,
                    );
                }
                if ($source['ciphertext'] === null) {
                    throw new Problem(409, 'Image publication conflict', 'The moderated image is unavailable.');
                }
                $product = $this->catalog->product($productId);
                if ($product === null || (string) ($product['id'] ?? '') !== $productId) {
                    throw new Problem(404, 'Not found', 'The requested resource is unavailable.');
                }
                $content = $this->contentFromEncrypted($contributionId, $source, false);
                $assetId = $this->ids->generate();
                $publicEncrypted = $this->cipher->encrypt(
                    $content->bytes,
                    $this->publicAssociatedData($assetId, $content->digest),
                );
                $asset = $this->images->savePublicAsset(
                    $assetId,
                    $content->digest,
                    $content->mediaType,
                    $content->width,
                    $content->height,
                    strlen($content->bytes),
                    $publicEncrypted,
                    $this->clock->now(),
                );
                try {
                    $icon = $this->icons->putIcon(
                        $this->ids->generate(),
                        'product',
                        $productId,
                        $content->digest,
                        $content->mediaType,
                        $content->altText,
                        $content->width,
                        $content->height,
                        strlen($content->bytes),
                        self::PUBLIC_PROVENANCE,
                        $expectedIconRevision,
                        $identity->userId,
                        $this->ids->generate(),
                        $this->clock->now(),
                    );
                } catch (DomainException $error) {
                    throw new Problem(409, 'Catalog icon not updated', $error->getMessage());
                }
                $publishedAt = $this->clock->now();
                if (! $this->images->linkPublication(
                    $contributionId,
                    $expectedContributionRevision,
                    $productId,
                    (string) $icon['id'],
                    (int) $icon['revision'],
                    (string) $asset['id'],
                    $identity->userId,
                    $publishedAt,
                )) {
                    throw new ConcurrentCatalogImagePublication();
                }
                $this->audit->recordAudit(
                    $this->ids->generate(),
                    $identity->userId,
                    'catalog.contribution-image.published',
                    'catalog_icon',
                    (string) $icon['id'],
                    null,
                    json_encode([
                        'contributionId' => $contributionId,
                        'contributionRevision' => $expectedContributionRevision,
                        'productId' => $productId,
                        'iconRevision' => (int) $icon['revision'],
                        'publicAssetId' => (string) $asset['id'],
                    ], JSON_THROW_ON_ERROR),
                    $publishedAt,
                );
                $this->images->deleteQuarantineImage($contributionId);
                return $this->images->publication($contributionId)
                    ?? throw new \LogicException('Image publication was not persisted.');
            });
        } catch (ConcurrentCatalogImagePublication|Problem $error) {
            try {
                return $this->exactPublicationReplay(
                    $contributionId,
                    $productId,
                    $expectedContributionRevision,
                    $expectedIconRevision,
                );
            } catch (Problem) {
                throw $error;
            }
        }
    }

    public function publicAsset(string $digest): CatalogImageContent
    {
        $this->requireCipher();
        if (preg_match('/^[a-f0-9]{64}$/', $digest) !== 1) {
            throw new Problem(404, 'Not found', 'The requested resource is unavailable.');
        }
        $row = $this->images->publicAsset($digest);
        if ($row === null) {
            throw new Problem(404, 'Not found', 'The requested resource is unavailable.');
        }

        return $this->contentFromEncrypted($digest, $row, true);
    }

    /** @param array<string, mixed>|null $source */
    private function assertPublishableSource(?array $source, int $expectedRevision): void
    {
        if ($source === null) {
            throw new Problem(404, 'Not found', 'The requested resource is unavailable.');
        }
        if (
            (string) $source['contributionType'] !== 'product_image'
            || (string) $source['status'] !== 'approved'
        ) {
            throw new Problem(409, 'Image publication conflict', 'Only an approved image can be published.');
        }
        if ((int) $source['revision'] !== $expectedRevision) {
            throw new Problem(409, 'Contribution conflict', 'The contribution changed since it was read.');
        }
    }

    /** @return array<string, mixed> */
    private function exactPublicationReplay(
        string $contributionId,
        string $productId,
        int $expectedContributionRevision,
        int $expectedIconRevision,
    ): array {
        $source = $this->images->imageForPublication($contributionId);
        $this->assertPublishableSource($source, $expectedContributionRevision);
        $publication = $this->images->publication($contributionId);
        if (
            $publication === null
            || (string) $publication['productId'] !== $productId
            || (int) $publication['contributionRevision'] !== $expectedContributionRevision
            || (int) $publication['iconRevision'] !== $expectedIconRevision + 1
        ) {
            throw new Problem(
                409,
                'Image publication conflict',
                'This contribution was published with different target or revision values.',
            );
        }

        return $publication;
    }

    /** @param array<string, mixed> $row */
    private function contentFromEncrypted(
        string $identity,
        array $row,
        bool $public,
    ): CatalogImageContent {
        $digest = (string) $row['assetDigest'];
        $encrypted = new EncryptedCatalogImage(
            (string) $row['ciphertext'],
            (string) $row['nonce'],
            (int) $row['keyVersion'],
        );
        $associatedData = $public
            ? $this->publicAssociatedData((string) $row['id'], $digest)
            : $this->quarantineAssociatedData($identity, $digest);
        try {
            $bytes = $this->cipher->decrypt($encrypted, $associatedData);
        } catch (\Throwable) {
            throw new Problem(503, 'Catalog image unavailable', 'Catalog image decryption is unavailable.');
        }
        if (hash('sha256', $bytes) !== $digest || strlen($bytes) !== (int) $row['byteSize']) {
            throw new Problem(503, 'Catalog image unavailable', 'Catalog image integrity verification failed.');
        }
        $payload = is_array($row['payload'] ?? null) ? $row['payload'] : [];

        return new CatalogImageContent(
            $bytes,
            (string) $row['mediaType'],
            $digest,
            (int) $row['width'],
            (int) $row['height'],
            (string) ($row['altText'] ?? $payload['altText'] ?? ''),
            (int) ($row[$public ? 'assetRevision' : 'revision'] ?? 1),
        );
    }

    private function quarantineAssociatedData(string $contributionId, string $digest): string
    {
        return 'catalog-contribution-image:' . $contributionId . ':' . $digest;
    }

    private function publicAssociatedData(string $assetId, string $digest): string
    {
        return 'catalog-public-asset:' . $assetId . ':' . $digest;
    }

    private function isUuid(string $value): bool
    {
        return preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $value,
        ) === 1;
    }

    private function requireCipher(): void
    {
        if (! $this->cipher->available()) {
            throw new Problem(503, 'Catalog image unavailable', 'Catalog image encryption is unavailable.');
        }
    }
}
