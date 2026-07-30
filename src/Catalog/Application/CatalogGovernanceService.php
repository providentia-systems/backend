<?php

declare(strict_types=1);

namespace Providentia\Catalog\Application;

use DomainException;
use Providentia\Identity\Application\AuthenticatedIdentity;
use Providentia\SharedKernel\Application\Clock;
use Providentia\SharedKernel\Application\Problem;
use Providentia\SharedKernel\Application\TransactionManager;
use Providentia\SharedKernel\Application\UuidGenerator;

final class CatalogGovernanceService
{
    public function __construct(
        private readonly CatalogGovernanceStore $catalog,
        private readonly CatalogAuthorization $authorization,
        private readonly UuidGenerator $ids,
        private readonly Clock $clock,
        private readonly TransactionManager $transactions,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{id: string, status: string, revision: int}
     */
    public function submit(
        AuthenticatedIdentity $identity,
        string $type,
        array $payload,
    ): array {
        $payload = $this->sanitize($type, $payload);
        $normalizedKey = $this->normalizedKey($type, $payload);
        $conflict = $this->catalog->conflictFor($type, $normalizedKey, $payload);
        $id = $this->ids->generate();
        $status = $conflict === null ? 'pending' : 'conflict';
        $this->transactions->transactional(function () use (
            $id,
            $type,
            $normalizedKey,
            $payload,
            $status,
            $conflict,
            $identity,
        ): void {
            $this->catalog->createProposal(
                $id,
                $type,
                $normalizedKey,
                $payload,
                $status,
                $conflict === null ? null : (string) $conflict['entityId'],
                $identity->userId,
                $this->clock->now(),
            );
        });

        return ['id' => $id, 'status' => $status, 'revision' => 1];
    }

    /** @return list<array<string, mixed>> */
    public function workbench(
        AuthenticatedIdentity $identity,
        string $queue,
        int $limit,
        int $offset,
    ): array {
        $this->authorization->requireReviewer($identity);
        if (! in_array($queue, ['proposals', 'duplicates', 'aliases', 'barcodes', 'icons', 'merges'], true)) {
            throw new Problem(422, 'Invalid workbench queue', 'The catalog workbench queue is not supported.');
        }

        return $this->catalog->workbench(
            $queue,
            min(100, max(1, $limit)),
            max(0, $offset),
        );
    }

    /** @return array{status: string, entityType: string|null, entityId: string|null} */
    public function decideProposal(
        AuthenticatedIdentity $identity,
        string $proposalId,
        string $decision,
        string $reason,
        int $expectedRevision,
    ): array {
        $this->authorization->requireReviewer($identity);
        if (! in_array($decision, ['approve', 'reject'], true)) {
            throw new Problem(422, 'Invalid moderation decision', 'Decision must be approve or reject.');
        }
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 500 || $expectedRevision < 1) {
            throw new Problem(422, 'Invalid moderation decision', 'A reason and current revision are required.');
        }

        return $this->transactions->transactional(function () use (
            $identity,
            $proposalId,
            $decision,
            $reason,
            $expectedRevision,
        ): array {
            $proposal = $this->catalog->proposal($proposalId);
            if ($proposal === null) {
                throw new Problem(404, 'Not found', 'The requested resource is unavailable.');
            }
            if ((int) $proposal['revision'] !== $expectedRevision) {
                throw new Problem(409, 'Revision conflict', 'The catalog proposal changed.');
            }
            $currentStatus = (string) $proposal['moderationStatus'];
            if (! in_array($currentStatus, ['pending', 'conflict'], true)) {
                throw new Problem(409, 'Proposal already decided', 'The catalog proposal is no longer pending.');
            }
            if ($decision === 'approve' && $currentStatus === 'conflict') {
                throw new Problem(
                    409,
                    'Conflict review required',
                    'Resolve the duplicate, alias, or barcode conflict before publishing.',
                );
            }
            $resolved = null;
            try {
                if ($decision === 'approve') {
                    $resolved = $this->catalog->publishProposal(
                        $proposal,
                        $this->ids->generate(),
                        $identity->userId,
                        $this->clock->now(),
                    );
                }
            } catch (DomainException $error) {
                throw new Problem(422, 'Proposal cannot be published', $error->getMessage());
            }
            if (
                ! $this->catalog->decideProposal(
                    $proposalId,
                    $decision,
                    $reason,
                    $expectedRevision,
                    $resolved['entityType'] ?? null,
                    $resolved['entityId'] ?? null,
                    $identity->userId,
                    $this->ids->generate(),
                    $this->clock->now(),
                )
            ) {
                throw new Problem(409, 'Revision conflict', 'The catalog proposal changed.');
            }

            return [
                'status' => $decision === 'approve' ? 'approved' : 'rejected',
                'entityType' => $resolved['entityType'] ?? null,
                'entityId' => $resolved['entityId'] ?? null,
            ];
        });
    }

    public function keepExisting(
        AuthenticatedIdentity $identity,
        string $conflictId,
        string $reason,
        int $expectedRevision,
    ): void {
        $this->authorization->requireReviewer($identity);
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 500 || $expectedRevision < 1) {
            throw new Problem(422, 'Invalid conflict decision', 'A reason and current revision are required.');
        }
        $resolved = $this->transactions->transactional(
            fn (): bool => $this->catalog->resolveConflictKeepingExisting(
                $conflictId,
                $expectedRevision,
                $reason,
                $identity->userId,
                $this->ids->generate(),
                $this->clock->now(),
            ),
        );
        if (! $resolved) {
            throw new Problem(409, 'Revision conflict', 'The catalog conflict changed.');
        }
    }

    /**
     * @param array<string, mixed> $input
     * @return array{id: string, revision: int}
     */
    public function putIcon(
        AuthenticatedIdentity $identity,
        string $targetType,
        string $targetId,
        array $input,
    ): array {
        $this->authorization->requireCurator($identity);
        if (! in_array($targetType, ['product', 'category'], true)) {
            throw new Problem(422, 'Invalid catalog icon', 'Icon target must be a product or category.');
        }
        $digest = mb_strtolower(trim((string) ($input['assetDigest'] ?? '')));
        $mediaType = mb_strtolower(trim((string) ($input['mediaType'] ?? '')));
        $altText = trim((string) ($input['altText'] ?? ''));
        $provenance = trim((string) ($input['provenance'] ?? ''));
        $width = (int) ($input['width'] ?? 0);
        $height = (int) ($input['height'] ?? 0);
        $byteSize = (int) ($input['byteSize'] ?? 0);
        $expectedRevision = (int) ($input['expectedRevision'] ?? -1);
        if (
            preg_match('/^[a-f0-9]{64}$/', $digest) !== 1
            || ! in_array($mediaType, ['image/png', 'image/webp', 'image/svg+xml'], true)
            || $altText === ''
            || mb_strlen($altText) > 191
            || $provenance === ''
            || mb_strlen($provenance) > 191
            || $width < 16
            || $width > 4096
            || $height < 16
            || $height > 4096
            || $byteSize < 1
            || $byteSize > 5242880
            || $expectedRevision < 0
        ) {
            throw new Problem(422, 'Invalid catalog icon', 'Icon metadata is incomplete or outside policy.');
        }

        try {
            return $this->transactions->transactional(fn (): array => $this->catalog->putIcon(
                $this->ids->generate(),
                $targetType,
                $targetId,
                $digest,
                $mediaType,
                $altText,
                $width,
                $height,
                $byteSize,
                $provenance,
                $expectedRevision,
                $identity->userId,
                $this->ids->generate(),
                $this->clock->now(),
            ));
        } catch (DomainException $error) {
            throw new Problem(409, 'Catalog icon not updated', $error->getMessage());
        }
    }

    /**
     * @param list<string> $duplicateIds
     * @return array<string, mixed>
     */
    public function previewMerge(
        AuthenticatedIdentity $identity,
        string $survivorId,
        array $duplicateIds,
    ): array {
        $this->authorization->requireCurator($identity);
        $duplicateIds = $this->mergeIds($survivorId, $duplicateIds);

        return $this->catalog->mergePreview($survivorId, $duplicateIds);
    }

    /**
     * @param array<string, int> $duplicateRevisions
     * @return array<string, mixed>
     */
    public function applyMerge(
        AuthenticatedIdentity $identity,
        string $survivorId,
        int $expectedSurvivorRevision,
        array $duplicateRevisions,
        string $reason,
    ): array {
        $this->authorization->requireCurator($identity);
        $duplicateIds = $this->mergeIds($survivorId, array_keys($duplicateRevisions));
        $reason = trim($reason);
        if ($expectedSurvivorRevision < 1 || $reason === '' || mb_strlen($reason) > 500) {
            throw new Problem(422, 'Invalid merge', 'Current revisions and a concise reason are required.');
        }
        foreach ($duplicateIds as $id) {
            if (($duplicateRevisions[$id] ?? 0) < 1) {
                throw new Problem(422, 'Invalid merge', 'Every duplicate requires its current revision.');
            }
        }
        $preview = $this->catalog->mergePreview($survivorId, $duplicateIds);
        if (($preview['eligible'] ?? false) !== true) {
            throw new Problem(409, 'Merge blocked', 'Resolve every reported merge conflict first.');
        }

        try {
            return $this->transactions->transactional(fn (): array => $this->catalog->applyMerge(
                $this->ids->generate(),
                $survivorId,
                $expectedSurvivorRevision,
                $duplicateRevisions,
                $reason,
                $identity->userId,
                $this->clock->now(),
            ));
        } catch (DomainException $error) {
            throw new Problem(409, 'Merge not applied', $error->getMessage());
        }
    }

    /** @return array<string, mixed> */
    public function reverseMerge(
        AuthenticatedIdentity $identity,
        string $mergeId,
        int $expectedRevision,
        string $reason,
    ): array {
        $this->authorization->requireCurator($identity);
        $reason = trim($reason);
        if ($expectedRevision < 1 || $reason === '' || mb_strlen($reason) > 500) {
            throw new Problem(422, 'Invalid merge reversal', 'A current revision and reason are required.');
        }
        try {
            return $this->transactions->transactional(fn (): array => $this->catalog->reverseMerge(
                $mergeId,
                $expectedRevision,
                $reason,
                $identity->userId,
                $this->clock->now(),
            ));
        } catch (DomainException $error) {
            throw new Problem(409, 'Merge not reversed', $error->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function sanitize(string $type, array $payload): array
    {
        $definitions = [
            'product' => ['canonicalName', 'brand', 'categoryId'],
            'pack' => ['productId', 'originalPackText', 'unitId', 'amount', 'multiplicity'],
            'alias' => ['productId', 'variantId', 'packId', 'rawAlias'],
            'barcode' => ['packId', 'barcode', 'barcodeType'],
        ];
        if (! isset($definitions[$type])) {
            throw new Problem(422, 'Invalid proposal', 'Proposal type is not supported.');
        }
        $actual = array_keys($payload);
        sort($actual);
        $expected = $definitions[$type];
        sort($expected);
        if ($actual !== $expected) {
            throw new Problem(422, 'Invalid proposal', 'Proposal fields must match the sanitized contract.');
        }
        foreach (array_keys($payload) as $key) {
            if (preg_match('/home|price|quantity|receipt|image|media|note|location|stock|user/i', $key) === 1) {
                throw new Problem(422, 'Invalid proposal', 'Private household fields are forbidden.');
            }
        }

        return match ($type) {
            'product' => $this->productPayload($payload),
            'pack' => $this->packPayload($payload),
            'alias' => $this->aliasPayload($payload),
            'barcode' => $this->barcodePayload($payload),
        };
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function productPayload(array $payload): array
    {
        $name = trim((string) $payload['canonicalName']);
        $brand = trim((string) $payload['brand']);
        $categoryId = trim((string) $payload['categoryId']);
        if ($name === '' || mb_strlen($name) > 191 || mb_strlen($brand) > 120 || $categoryId === '') {
            throw new Problem(422, 'Invalid proposal', 'Product identity fields are invalid.');
        }

        return ['canonicalName' => $name, 'brand' => $brand, 'categoryId' => $categoryId];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function packPayload(array $payload): array
    {
        $productId = trim((string) $payload['productId']);
        $packText = trim((string) $payload['originalPackText']);
        $unitId = $payload['unitId'] === null ? null : trim((string) $payload['unitId']);
        $unitId = $unitId === '' ? null : $unitId;
        $amount = $payload['amount'] === null ? null : trim((string) $payload['amount']);
        $multiplicity = (int) $payload['multiplicity'];
        if (
            $productId === ''
            || $packText === ''
            || mb_strlen($packText) > 191
            || ($amount !== null && preg_match('/^(?:0|[1-9]\d{0,11})(?:\.\d{1,8})?$/', $amount) !== 1)
            || (($unitId === null) !== ($amount === null))
            || $multiplicity < 1
            || $multiplicity > 1000
        ) {
            throw new Problem(422, 'Invalid proposal', 'Pack identity fields are invalid.');
        }

        return [
            'productId' => $productId,
            'originalPackText' => $packText,
            'unitId' => $unitId,
            'amount' => $amount,
            'multiplicity' => $multiplicity,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function aliasPayload(array $payload): array
    {
        $productId = trim((string) $payload['productId']);
        $alias = trim((string) $payload['rawAlias']);
        $variantId = $payload['variantId'] === null ? null : trim((string) $payload['variantId']);
        $packId = $payload['packId'] === null ? null : trim((string) $payload['packId']);
        if ($productId === '' || $alias === '' || mb_strlen($alias) > 191) {
            throw new Problem(422, 'Invalid proposal', 'Alias identity fields are invalid.');
        }

        return [
            'productId' => $productId,
            'variantId' => $variantId === '' ? null : $variantId,
            'packId' => $packId === '' ? null : $packId,
            'rawAlias' => $alias,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function barcodePayload(array $payload): array
    {
        $packId = trim((string) $payload['packId']);
        $barcode = preg_replace('/[\s-]+/', '', (string) $payload['barcode']) ?? '';
        $barcodeType = mb_strtolower(trim((string) $payload['barcodeType']));
        if (
            $packId === ''
            || ! $this->validBarcode($barcode, $barcodeType)
        ) {
            throw new Problem(422, 'Invalid proposal', 'Barcode identity fields are invalid.');
        }

        return ['packId' => $packId, 'barcode' => $barcode, 'barcodeType' => $barcodeType];
    }

    /** @param array<string, mixed> $payload */
    private function normalizedKey(string $type, array $payload): string
    {
        $value = match ($type) {
            'product' => (string) $payload['categoryId'] . '|'
                . (string) $payload['canonicalName'] . '|'
                . (string) $payload['brand'],
            'pack' => (string) $payload['productId'] . '|' . (string) $payload['originalPackText'],
            'alias' => (string) $payload['rawAlias'],
            'barcode' => (string) $payload['barcode'],
            default => throw new \LogicException('Unsupported catalog proposal type.'),
        };

        return mb_substr($this->normalize((string) $value), 0, 191);
    }

    /**
     * @param list<string> $duplicateIds
     * @return list<string>
     */
    private function mergeIds(string $survivorId, array $duplicateIds): array
    {
        $duplicateIds = array_values(array_unique(array_filter(array_map('trim', $duplicateIds))));
        /** @var list<string> $duplicateIds */
        if (
            $survivorId === ''
            || $duplicateIds === []
            || count($duplicateIds) > 20
            || in_array($survivorId, $duplicateIds, true)
        ) {
            throw new Problem(422, 'Invalid merge', 'Choose one survivor and one to twenty distinct duplicates.');
        }

        return $duplicateIds;
    }

    private function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = preg_replace('/[^\p{L}\p{N}|]+/u', ' ', $value) ?? $value;

        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }

    private function validBarcode(string $barcode, string $type): bool
    {
        $lengths = [
            'gtin-8' => 8,
            'gtin-12' => 12,
            'gtin-13' => 13,
            'gtin-14' => 14,
        ];
        if ($type === 'other') {
            return preg_match('/^[0-9A-Za-z]{6,64}$/', $barcode) === 1;
        }
        if (! isset($lengths[$type]) || strlen($barcode) !== $lengths[$type]) {
            return false;
        }
        if (preg_match('/^\d+$/', $barcode) !== 1) {
            return false;
        }
        $sum = 0;
        $digits = str_split(substr($barcode, 0, -1));
        $distanceFromCheckDigit = count($digits);
        foreach ($digits as $index => $digit) {
            $sum += (int) $digit * (($distanceFromCheckDigit - $index) % 2 === 1 ? 3 : 1);
        }

        return (10 - ($sum % 10)) % 10 === (int) substr($barcode, -1);
    }
}
