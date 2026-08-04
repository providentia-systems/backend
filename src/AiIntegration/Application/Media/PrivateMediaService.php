<?php

declare(strict_types=1);

namespace Providentia\AiIntegration\Application\Media;

use DateInterval;
use Providentia\AiIntegration\Application\AiMaturityStore;
use Providentia\AiIntegration\Application\AiProviderException;
use Providentia\Home\Application\HomeAuthorization;
use Providentia\Home\Application\HomePermission;
use Providentia\Identity\Application\AuthenticatedIdentity;
use Providentia\SharedKernel\Application\Clock;
use Providentia\SharedKernel\Application\Problem;
use Providentia\SharedKernel\Application\UuidGenerator;
use Throwable;

final readonly class PrivateMediaService
{
    public function __construct(
        private AiMaturityStore $store,
        private MediaStorage $storage,
        private VideoProcessor $video,
        private HomeAuthorization $authorization,
        private UuidGenerator $ids,
        private Clock $clock,
        private int $defaultQuotaBytes,
        private int $maxImageBytes,
        private int $maxVideoBytes,
        private int $transientTtlSeconds,
        private int $maxExportBytes,
        private int $maxImages,
    ) {
    }

    /** @return array<string, mixed> */
    public function upload(
        AuthenticatedIdentity $identity,
        string $homeId,
        string $retention,
        string $declaredMimeType,
        ?string $originalName,
        string $bytes,
    ): array {
        $this->authorization->requirePermission($identity, $homeId, HomePermission::AI_USE);
        if (! in_array($retention, ['transient', 'retained'], true)) {
            throw new Problem(422, 'Invalid media retention', 'Choose transient or retained private media.');
        }
        [$mimeType, $purpose] = $this->verifiedType($declaredMimeType, $bytes);
        $limit = $purpose === 'video' ? $this->maxVideoBytes : $this->maxImageBytes;
        if (strlen($bytes) < 16 || strlen($bytes) > $limit) {
            throw new Problem(413, 'Media rejected', 'Private media size is outside the configured limit.');
        }
        $digest = hash('sha256', $bytes);
        $duplicate = $this->store->activeMediaByDigest($homeId, $digest);
        if ($duplicate !== null) {
            $this->store->recordObservationDecision(
                $this->ids->generate(),
                $homeId,
                null,
                'exact_digest',
                'asset:' . $duplicate['id'],
                'upload:' . $digest,
                ['sha256' => $digest],
                'confirmed_duplicate',
                $this->clock->now(),
            );

            return [
                'id' => $duplicate['id'],
                'duplicateOf' => $duplicate['id'],
                'sha256' => $digest,
                'plaintextBytes' => (int) $duplicate['plaintextBytes'],
                'processingStatus' => $duplicate['processingStatus'],
            ];
        }
        $quota = $this->store->mediaQuota($homeId, $this->defaultQuotaBytes);
        $id = $this->ids->generate();
        $object = null;
        $expires = $retention === 'transient'
            ? $this->clock->now()->add(new DateInterval('PT' . $this->transientTtlSeconds . 'S'))
            : null;
        try {
            $object = $this->storage->store($homeId, $id, $bytes);
            $inserted = $this->store->insertMediaWithinQuota(
                $id,
                $homeId,
                null,
                $retention,
                $purpose,
                $mimeType,
                $this->safeName($originalName),
                $object,
                null,
                null,
                $purpose === 'video' ? 'queued' : 'ready',
                $identity->userId,
                $expires,
                $this->defaultQuotaBytes,
                $this->clock->now(),
            );
            if (! $inserted) {
                $this->storage->delete($object);
                $duplicate = $this->store->activeMediaByDigest($homeId, $digest);
                if ($duplicate === null) {
                    throw new Problem(413, 'Private media quota exceeded', 'Delete media or increase the household quota.');
                }

                return [
                    'id' => $duplicate['id'],
                    'duplicateOf' => $duplicate['id'],
                    'sha256' => $digest,
                    'plaintextBytes' => (int) $duplicate['plaintextBytes'],
                    'processingStatus' => $duplicate['processingStatus'],
                ];
            }
        } catch (Throwable $error) {
            if ($object instanceof EncryptedMediaObject) {
                $this->storage->delete($object);
            }
            throw $error;
        }

        return [
            'id' => $id,
            'duplicateOf' => null,
            'mimeType' => $mimeType,
            'retention' => $retention,
            'sha256' => $object->sha256,
            'plaintextBytes' => $object->plaintextBytes,
            'processingStatus' => $purpose === 'video' ? 'queued' : 'ready',
            'quotaBytes' => $quota,
            'usageBytes' => $this->store->mediaUsage($homeId),
        ];
    }

    /** @return array{items: list<array<string, mixed>>, quotaBytes: int, usageBytes: int} */
    public function list(
        AuthenticatedIdentity $identity,
        string $homeId,
        int $limit = 50,
        ?string $beforeId = null,
    ): array {
        $this->authorization->requirePermission($identity, $homeId, HomePermission::AI_READ);

        return [
            'items' => $this->store->listMedia($homeId, max(1, min(200, $limit)), $beforeId),
            'quotaBytes' => $this->store->mediaQuota($homeId, $this->defaultQuotaBytes),
            'usageBytes' => $this->store->mediaUsage($homeId),
        ];
    }

    /** @return array{metadata: array<string, mixed>, bytes: string} */
    public function download(AuthenticatedIdentity $identity, string $homeId, string $assetId): array
    {
        $this->authorization->requirePermission($identity, $homeId, HomePermission::AI_READ);
        $asset = $this->requiredAsset($homeId, $assetId);

        return ['metadata' => $this->publicAsset($asset), 'bytes' => $this->readAsset($asset)];
    }

    public function delete(AuthenticatedIdentity $identity, string $homeId, string $assetId): void
    {
        $this->authorization->requirePermission($identity, $homeId, HomePermission::AI_USE);
        $asset = $this->requiredAsset($homeId, $assetId);
        $this->requireOwnerOrManager($identity, $homeId, $asset);
        foreach ($this->store->derivedMedia($homeId, $assetId) as $derived) {
            $this->deleteAsset($derived);
        }
        $this->deleteAsset($asset);
    }

    public function updateRetention(
        AuthenticatedIdentity $identity,
        string $homeId,
        string $assetId,
        string $retention,
        int $expectedRevision,
    ): void {
        $this->authorization->requirePermission($identity, $homeId, HomePermission::AI_USE);
        if (! in_array($retention, ['transient', 'retained'], true) || $expectedRevision < 1) {
            throw new Problem(422, 'Invalid media retention', 'Choose transient or retained private media.');
        }
        $asset = $this->requiredAsset($homeId, $assetId);
        $this->requireOwnerOrManager($identity, $homeId, $asset);
        $expires = $retention === 'transient'
            ? $this->clock->now()->add(new DateInterval('PT' . $this->transientTtlSeconds . 'S'))
            : null;
        if (! $this->store->updateMediaRetention(
            $homeId,
            $assetId,
            $retention,
            $expires,
            $expectedRevision,
            $this->clock->now(),
        )) {
            throw new Problem(409, 'Revision conflict', 'The private-media retention changed on another device.');
        }
        $this->store->updateDerivedMediaRetention(
            $homeId,
            $assetId,
            $retention,
            $expires,
            $this->clock->now(),
        );
    }

    /** @return list<array<string, mixed>> */
    public function export(AuthenticatedIdentity $identity, string $homeId, int $limit = 100): array
    {
        $this->authorization->requirePermission($identity, $homeId, HomePermission::AI_READ);
        $assets = $this->store->listMedia($homeId, max(1, min(100, $limit)));
        $total = 0;
        $result = [];
        foreach ($assets as $listed) {
            $asset = $this->requiredAsset($homeId, (string) $listed['id']);
            $total += (int) $asset['plaintextBytes'];
            if ($total > $this->maxExportBytes) {
                throw new Problem(413, 'Media export too large', 'Request a smaller private-media export page.');
            }
            $result[] = [
                'metadata' => $this->publicAsset($asset),
                'contentBase64' => base64_encode($this->readAsset($asset)),
            ];
        }

        return $result;
    }

    /** @param non-empty-list<string> $assetIds @return non-empty-list<array{mimeType: string, bytes: string}> */
    public function extractionImages(
        AuthenticatedIdentity $identity,
        string $homeId,
        array $assetIds,
    ): array {
        $this->authorization->requirePermission($identity, $homeId, HomePermission::AI_USE);
        if ($assetIds === [] || count($assetIds) > $this->maxImages) {
            throw new Problem(422, 'Invalid extraction media', 'Choose a supported number of private images.');
        }
        $images = [];
        foreach (array_values(array_unique($assetIds)) as $assetId) {
            $asset = $this->requiredAsset($homeId, $assetId);
            if (! in_array($asset['purpose'], ['image', 'derived_frame'], true)
                || $asset['processingStatus'] !== 'ready'
            ) {
                throw new Problem(409, 'Media not ready', 'Only ready image assets can be extracted.');
            }
            $images[] = ['mimeType' => (string) $asset['mimeType'], 'bytes' => $this->readAsset($asset)];
        }

        return $images;
    }

    public function processVideoOnce(): bool
    {
        $asset = $this->store->claimQueuedVideo($this->clock->now());
        if ($asset === null) {
            return false;
        }
        try {
            $processed = $this->video->extractFrames($this->readAsset($asset));
            foreach ($processed['frames'] as $frame) {
                $this->storeDerivedFrame($asset, $frame);
            }
            $this->store->finishVideo(
                (string) $asset['homeId'],
                (string) $asset['id'],
                $processed['durationMs'],
                null,
                $this->clock->now(),
            );
        } catch (Throwable $error) {
            foreach ($this->store->derivedMedia((string) $asset['homeId'], (string) $asset['id']) as $derived) {
                $this->deleteAsset($derived);
            }
            $detail = $error instanceof AiProviderException
                ? $error->safeDetail
                : 'The isolated video worker failed safely.';
            $this->store->finishVideo(
                (string) $asset['homeId'],
                (string) $asset['id'],
                null,
                $detail,
                $this->clock->now(),
            );
        }

        return true;
    }

    public function purgeExpired(int $limit = 100): int
    {
        $expired = $this->store->expiredMedia($this->clock->now(), max(1, min(500, $limit)));
        foreach ($expired as $asset) {
            $this->deleteAsset($asset);
        }

        return count($expired);
    }

    /** @param array<string, mixed> $asset @param array{offsetMs: int, mimeType: string, bytes: string} $frame */
    private function storeDerivedFrame(array $asset, array $frame): void
    {
        $digest = hash('sha256', $frame['bytes']);
        $duplicate = $this->store->activeMediaByDigest((string) $asset['homeId'], $digest);
        if ($duplicate !== null) {
            $this->store->recordObservationDecision(
                $this->ids->generate(),
                (string) $asset['homeId'],
                null,
                'exact_digest',
                'asset:' . $duplicate['id'],
                'video:' . $asset['id'] . ':offset:' . $frame['offsetMs'],
                ['sha256' => $digest],
                'confirmed_duplicate',
                $this->clock->now(),
            );
            return;
        }
        $id = $this->ids->generate();
        $homeId = (string) $asset['homeId'];
        $object = null;
        try {
            $object = $this->storage->store($homeId, $id, $frame['bytes']);
            $inserted = $this->store->insertMediaWithinQuota(
                $id,
                $homeId,
                (string) $asset['id'],
                (string) $asset['retention'],
                'derived_frame',
                $frame['mimeType'],
                null,
                $object,
                null,
                $frame['offsetMs'],
                'ready',
                (string) $asset['createdByUserId'],
                $asset['expiresAt'] === null ? null : new \DateTimeImmutable((string) $asset['expiresAt']),
                $this->defaultQuotaBytes,
                $this->clock->now(),
            );
            if (! $inserted) {
                $this->storage->delete($object);
                $duplicate = $this->store->activeMediaByDigest($homeId, $digest);
                if ($duplicate !== null) {
                    return;
                }
                throw new AiProviderException('media_quota_exceeded', 'Derived frames exceed the household quota.');
            }
        } catch (Throwable $error) {
            if ($object instanceof EncryptedMediaObject) {
                $this->storage->delete($object);
            }
            throw $error;
        }
    }

    /** @return array{0: string, 1: string} */
    private function verifiedType(string $declared, string $bytes): array
    {
        $detected = match (true) {
            str_starts_with($bytes, "\xFF\xD8\xFF") => 'image/jpeg',
            str_starts_with($bytes, "\x89PNG\r\n\x1A\n") => 'image/png',
            substr($bytes, 0, 4) === 'RIFF' && substr($bytes, 8, 4) === 'WEBP' => 'image/webp',
            substr($bytes, 4, 4) === 'ftyp' => 'video/mp4',
            str_starts_with($bytes, "\x1A\x45\xDF\xA3") => 'video/webm',
            default => null,
        };
        if ($detected === null || ($declared !== '' && $declared !== $detected)) {
            throw new Problem(415, 'Media rejected', 'The private media type could not be verified.');
        }

        return [$detected, str_starts_with($detected, 'video/') ? 'video' : 'image'];
    }

    /** @return array<string, mixed> */
    private function requiredAsset(string $homeId, string $assetId): array
    {
        $asset = $this->store->media($homeId, $assetId);
        if ($asset === null) {
            throw new Problem(404, 'Not found', 'The requested private media is unavailable.');
        }

        return $asset;
    }

    /** @param array<string, mixed> $asset */
    private function readAsset(array $asset): string
    {
        return $this->storage->read(
            (string) $asset['homeId'],
            (string) $asset['id'],
            $this->object($asset),
        );
    }

    /** @param array<string, mixed> $asset */
    private function object(array $asset): EncryptedMediaObject
    {
        return new EncryptedMediaObject(
            (string) $asset['objectKey'],
            (string) $asset['wrappedKey'],
            (string) $asset['wrapNonce'],
            (int) $asset['keyVersion'],
            (string) $asset['sha256'],
            (int) $asset['plaintextBytes'],
        );
    }

    /** @param array<string, mixed> $asset @return array<string, mixed> */
    private function publicAsset(array $asset): array
    {
        unset($asset['objectKey'], $asset['wrappedKey'], $asset['wrapNonce'], $asset['keyVersion']);

        return $asset;
    }

    private function safeName(?string $name): ?string
    {
        if ($name === null || trim($name) === '') {
            return null;
        }

        return mb_substr(basename(str_replace('\\', '/', trim($name))), 0, 191);
    }

    /** @param array<string, mixed> $asset */
    private function requireOwnerOrManager(
        AuthenticatedIdentity $identity,
        string $homeId,
        array $asset,
    ): void {
        if ((string) $asset['createdByUserId'] !== $identity->userId) {
            $this->authorization->requirePermission($identity, $homeId, HomePermission::AI_MANAGE);
        }
    }

    /** @param array<string, mixed> $asset */
    private function deleteAsset(array $asset): void
    {
        if (! $this->store->deleteMediaWithinQuota(
            (string) $asset['homeId'],
            (string) $asset['id'],
            (int) $asset['plaintextBytes'],
            $this->clock->now(),
        )) {
            throw new Problem(409, 'Media changed', 'The private media changed while it was deleted.');
        }
        $this->storage->delete($this->object($asset));
    }
}
