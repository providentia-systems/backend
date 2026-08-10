<?php

declare(strict_types=1);

namespace Providentia\Catalog\Application;

use DateTimeImmutable;
use Providentia\Home\Application\HomeAuditRecorder;
use Providentia\Home\Application\HomeAuthorization;
use Providentia\Home\Application\HomePermission;
use Providentia\Identity\Application\AuthenticatedIdentity;
use Providentia\SharedKernel\Application\Clock;
use Providentia\SharedKernel\Application\Problem;
use Providentia\SharedKernel\Application\TransactionManager;
use Providentia\SharedKernel\Application\UuidGenerator;

final class CatalogContributionService
{
    public const NOTICE_VERSION = 'catalog-sharing-v1';

    /** @var list<string> */
    private const CONTRIBUTION_TYPES = ['product_identity', 'product_image', 'store_price'];

    public function __construct(
        private readonly CatalogContributionStore $store,
        private readonly HomeAuthorization $homes,
        private readonly CatalogAuthorization $catalogAuthorization,
        private readonly HomeAuditRecorder $audit,
        private readonly UuidGenerator $ids,
        private readonly Clock $clock,
        private readonly TransactionManager $transactions,
    ) {
    }

    /** @return array<string, mixed> */
    public function consent(AuthenticatedIdentity $identity, string $homeId): array
    {
        $this->homes->requirePermission($identity, $homeId, HomePermission::HOME_READ);

        return $this->store->consent($homeId) ?? [
            'homeId' => $homeId,
            'shareProductIdentity' => false,
            'shareProductImages' => false,
            'shareStorePrices' => false,
            'noticeVersion' => self::NOTICE_VERSION,
            'revision' => 0,
        ];
    }

    /** @return array<string, mixed> */
    public function configureConsent(
        AuthenticatedIdentity $identity,
        string $homeId,
        bool $shareProductIdentity,
        bool $shareProductImages,
        bool $shareStorePrices,
        string $noticeVersion,
        int $expectedRevision,
    ): array {
        $this->homes->requirePermission($identity, $homeId, HomePermission::CATALOG_CONSENT_MANAGE);
        if ($noticeVersion !== self::NOTICE_VERSION || $expectedRevision < 0) {
            throw new Problem(422, 'Invalid sharing consent', 'A current notice and consent revision are required.');
        }
        $now = $this->clock->now();
        $receiptId = $this->ids->generate();
        $this->transactions->transactional(function () use (
            $receiptId,
            $homeId,
            $shareProductIdentity,
            $shareProductImages,
            $shareStorePrices,
            $noticeVersion,
            $expectedRevision,
            $identity,
            $now,
        ): void {
            if (
                ! $this->store->saveConsent(
                    $receiptId,
                    $homeId,
                    $shareProductIdentity,
                    $shareProductImages,
                    $shareStorePrices,
                    $noticeVersion,
                    $expectedRevision,
                    $identity->userId,
                    $now,
                )
            ) {
                throw new Problem(409, 'Consent conflict', 'The sharing consent changed since it was read.');
            }
            $this->recordAudit(
                $identity,
                'catalog.sharing-consent.changed',
                'catalog_contribution_consent',
                $homeId,
                $homeId,
                ['revision' => $expectedRevision + 1],
                $now,
            );
        });

        return [
            'homeId' => $homeId,
            'shareProductIdentity' => $shareProductIdentity,
            'shareProductImages' => $shareProductImages,
            'shareStorePrices' => $shareStorePrices,
            'noticeVersion' => $noticeVersion,
            'revision' => $expectedRevision + 1,
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array{id: string, status: string, revision: int}
     */
    public function submit(
        AuthenticatedIdentity $identity,
        string $homeId,
        string $type,
        ?string $sourceEntityId,
        int $expectedConsentRevision,
        array $input,
    ): array {
        $this->homes->requirePermission($identity, $homeId, HomePermission::CATALOG_CONTRIBUTE);
        $consent = $this->store->consent($homeId);
        if (
            $consent === null
            || (int) $consent['revision'] !== $expectedConsentRevision
            || ! $this->allows($consent, $type)
        ) {
            throw new Problem(409, 'Sharing consent required', 'The requested contribution is not currently enabled.');
        }
        $sourceEntityId = $sourceEntityId === null ? null : trim($sourceEntityId);
        if ($sourceEntityId !== null && (strlen($sourceEntityId) !== 36 || mb_strlen($sourceEntityId) > 36)) {
            throw new Problem(422, 'Invalid catalog contribution', 'The source reference is invalid.');
        }
        $payload = $this->sanitize($type, $input);
        $id = $this->ids->generate();
        $now = $this->clock->now();
        $this->transactions->transactional(function () use (
            $id,
            $homeId,
            $consent,
            $type,
            $sourceEntityId,
            $payload,
            $identity,
            $now,
        ): void {
            if (
                ! $this->store->createContribution(
                    $id,
                    $homeId,
                    (string) $consent['receiptId'],
                    $type,
                    $sourceEntityId,
                    $payload,
                    $identity->userId,
                    $now,
                )
            ) {
                throw new Problem(
                    409,
                    'Sharing consent changed',
                    'The contribution was not submitted because consent changed.',
                );
            }
            $this->recordAudit(
                $identity,
                'catalog.contribution.submitted',
                'catalog_contribution',
                $id,
                $homeId,
                ['type' => $type, 'consentRevision' => (int) $consent['revision']],
                $now,
            );
        });

        return ['id' => $id, 'status' => 'pending', 'revision' => 1];
    }

    /** @return list<array<string, mixed>> */
    public function contributions(
        AuthenticatedIdentity $identity,
        string $homeId,
        int $limit,
        int $offset,
    ): array {
        $this->homes->requirePermission($identity, $homeId, HomePermission::HOME_READ);

        return $this->store->contributionsForHome($homeId, min(100, max(1, $limit)), max(0, $offset));
    }

    /** @return list<array<string, mixed>> */
    public function reviewQueue(
        AuthenticatedIdentity $identity,
        string $status,
        int $limit,
        int $offset,
    ): array {
        $this->catalogAuthorization->requireReviewer($identity);
        if (! in_array($status, ['pending', 'approved', 'rejected', 'withdrawn'], true)) {
            throw new Problem(422, 'Invalid contribution queue', 'The requested moderation queue is invalid.');
        }

        return array_map(
            fn (array $row): array => $this->reviewProjection($row),
            $this->store->reviewQueue($status, min(100, max(1, $limit)), max(0, $offset)),
        );
    }

    /** @return list<array<string, mixed>> */
    public function published(?string $type, int $limit, int $offset): array
    {
        $type = $type === null || trim($type) === '' ? null : trim($type);
        if ($type !== null && ! in_array($type, self::CONTRIBUTION_TYPES, true)) {
            throw new Problem(422, 'Invalid contribution type', 'The requested contribution type is invalid.');
        }

        return array_map(
            fn (array $row): array => $this->publishedProjection($row),
            $this->store->published($type, min(100, max(1, $limit)), max(0, $offset)),
        );
    }

    public function decide(
        AuthenticatedIdentity $identity,
        string $id,
        string $decision,
        string $reason,
        int $expectedRevision,
    ): void {
        $this->catalogAuthorization->requireReviewer($identity);
        $reason = trim($reason);
        if (
            ! in_array($decision, ['approved', 'rejected'], true)
            || $reason === ''
            || mb_strlen($reason) > 500
            || $expectedRevision < 1
        ) {
            throw new Problem(422, 'Invalid moderation decision', 'A supported decision and reason are required.');
        }
        if ($this->store->contribution($id) === null) {
            throw new Problem(404, 'Not found', 'The requested resource is unavailable.');
        }
        if (
            ! $this->store->decide(
                $id,
                $decision,
                $reason,
                $expectedRevision,
                $identity->userId,
                $this->clock->now(),
            )
        ) {
            throw new Problem(409, 'Contribution conflict', 'The contribution changed or is no longer pending.');
        }
    }

    /** @param array<string, mixed> $consent */
    private function allows(array $consent, string $type): bool
    {
        return match ($type) {
            'product_identity' => (bool) $consent['shareProductIdentity'],
            'product_image' => (bool) $consent['shareProductImages'],
            'store_price' => (bool) $consent['shareStorePrices'],
            default => false,
        };
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, string>
     */
    private function sanitize(string $type, array $input): array
    {
        return match ($type) {
            'product_identity' => $this->productIdentity($input),
            'product_image' => $this->productImage($input),
            'store_price' => $this->storePrice($input),
            default => throw new Problem(422, 'Invalid catalog contribution', 'The contribution type is invalid.'),
        };
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, string>
     */
    private function productIdentity(array $input): array
    {
        $name = trim((string) ($input['canonicalName'] ?? ''));
        $brand = trim((string) ($input['brand'] ?? ''));
        $category = trim((string) ($input['categoryLabel'] ?? ''));
        $barcode = trim((string) ($input['barcode'] ?? ''));
        $pack = trim((string) ($input['packText'] ?? ''));
        if (
            $name === '' || mb_strlen($name) > 191 || mb_strlen($brand) > 120
            || mb_strlen($category) > 191 || mb_strlen($pack) > 191
            || ($barcode !== '' && preg_match('/^[0-9A-Za-z-]{4,32}$/', $barcode) !== 1)
        ) {
            throw new Problem(422, 'Invalid catalog contribution', 'Product identity fields are invalid.');
        }

        return array_filter([
            'canonicalName' => $name,
            'brand' => $brand,
            'categoryLabel' => $category,
            'barcode' => $barcode,
            'packText' => $pack,
        ], static fn (string $value): bool => $value !== '');
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, string>
     */
    private function productImage(array $input): array
    {
        $digest = strtolower(trim((string) ($input['assetDigest'] ?? '')));
        $mediaType = strtolower(trim((string) ($input['mediaType'] ?? '')));
        $altText = trim((string) ($input['altText'] ?? ''));
        $provenance = trim((string) ($input['provenance'] ?? ''));
        if (
            preg_match('/^[a-f0-9]{64}$/', $digest) !== 1
            || ! in_array($mediaType, ['image/jpeg', 'image/png', 'image/webp'], true)
            || $altText === '' || mb_strlen($altText) > 191
            || $provenance === '' || mb_strlen($provenance) > 191
        ) {
            throw new Problem(422, 'Invalid catalog contribution', 'Product image metadata is invalid.');
        }

        return [
            'assetDigest' => $digest,
            'mediaType' => $mediaType,
            'altText' => $altText,
            'provenance' => $provenance,
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, string>
     */
    private function storePrice(array $input): array
    {
        $productId = trim((string) ($input['productId'] ?? ''));
        $storeName = trim((string) ($input['storeName'] ?? ''));
        $storeLocation = trim((string) ($input['storeLocation'] ?? ''));
        $price = trim((string) ($input['price'] ?? ''));
        $currency = strtoupper(trim((string) ($input['currency'] ?? '')));
        $observedOn = trim((string) ($input['observedOn'] ?? ''));
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $observedOn);
        if (
            strlen($productId) !== 36 || $storeName === '' || mb_strlen($storeName) > 191
            || mb_strlen($storeLocation) > 191 || preg_match('/^(?:0|[1-9][0-9]{0,11})\.[0-9]{2,4}$/', $price) !== 1
            || preg_match('/^[A-Z]{3}$/', $currency) !== 1 || $date === false
            || $date->format('Y-m-d') !== $observedOn
        ) {
            throw new Problem(422, 'Invalid catalog contribution', 'Store-price fields are invalid.');
        }

        return array_filter([
            'productId' => $productId,
            'storeName' => $storeName,
            'storeLocation' => $storeLocation,
            'price' => $price,
            'currency' => $currency,
            'observedOn' => $observedOn,
        ], static fn (string $value): bool => $value !== '');
    }

    /**
     * Defence-in-depth allowlist for moderator responses. The persistence
     * query already omits attribution; this projection prevents a future
     * store implementation from accidentally returning it.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function reviewProjection(array $row): array
    {
        return array_filter([
            'id' => (string) ($row['id'] ?? ''),
            'contributionType' => (string) ($row['contributionType'] ?? ''),
            'payload' => $this->publishedPayload(
                (string) ($row['contributionType'] ?? ''),
                $row['payload'] ?? [],
            ),
            'status' => (string) ($row['status'] ?? ''),
            'revision' => (int) ($row['revision'] ?? 0),
            'consentNoticeVersion' => (string) ($row['consentNoticeVersion'] ?? ''),
            'consentRevision' => (int) ($row['consentRevision'] ?? 0),
            'createdAt' => (string) ($row['createdAt'] ?? ''),
        ], static fn (mixed $value): bool => $value !== '');
    }

    /**
     * @param array<string, mixed> $row
     * @return array{contributionType: string, payload: array<string, string>, publishedAt: string}
     */
    private function publishedProjection(array $row): array
    {
        $type = (string) ($row['contributionType'] ?? '');

        return [
            'contributionType' => $type,
            'payload' => $this->publishedPayload($type, $row['payload'] ?? []),
            'publishedAt' => $this->publishedDate($row['publishedAt'] ?? null),
        ];
    }

    private function publishedDate(mixed $value): string
    {
        if (! is_string($value) || trim($value) === '') {
            return '';
        }
        try {
            return (new DateTimeImmutable($value, new \DateTimeZone('UTC')))
                ->setTimezone(new \DateTimeZone('UTC'))
                ->format(DATE_ATOM);
        } catch (\Exception) {
            return '';
        }
    }

    /**
     * @return array<string, string>
     */
    private function publishedPayload(string $type, mixed $payload): array
    {
        if (is_string($payload)) {
            try {
                $payload = json_decode($payload, true, 32, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                $payload = [];
            }
        }
        if (! is_array($payload)) {
            return [];
        }
        $allowed = match ($type) {
            'product_identity' => ['canonicalName', 'brand', 'categoryLabel', 'barcode', 'packText'],
            'product_image' => ['assetDigest', 'mediaType', 'altText', 'provenance'],
            'store_price' => ['productId', 'storeName', 'storeLocation', 'price', 'currency', 'observedOn'],
            default => [],
        };
        $projected = [];
        foreach ($allowed as $key) {
            if (isset($payload[$key]) && is_string($payload[$key])) {
                $projected[$key] = $payload[$key];
            }
        }

        return $projected;
    }

    /** @param array<string, mixed> $details */
    private function recordAudit(
        AuthenticatedIdentity $identity,
        string $action,
        string $targetType,
        string $targetId,
        string $homeId,
        array $details,
        DateTimeImmutable $at,
    ): void {
        $this->audit->recordAudit(
            $this->ids->generate(),
            $identity->userId,
            $action,
            $targetType,
            $targetId,
            $homeId,
            json_encode($details, JSON_THROW_ON_ERROR),
            $at,
        );
    }
}
