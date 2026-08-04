<?php

declare(strict_types=1);

namespace Providentia\Catalog\Application;

use JsonException;
use Providentia\Home\Application\HomeAuthorization;
use Providentia\Home\Application\HomePermission;
use Providentia\Identity\Application\AuthenticatedIdentity;
use Providentia\SharedKernel\Application\ChangeFeedWriter;
use Providentia\SharedKernel\Application\Clock;
use Providentia\SharedKernel\Application\Problem;
use Providentia\SharedKernel\Application\TransactionManager;
use Providentia\SharedKernel\Application\UuidGenerator;

final class CatalogImportService
{
    public const CONFIRMATION = 'apply_catalog_records';
    public const MAX_ROWS = 500;
    public const MAX_BYTES = 1_048_576;

    /** @var list<string> */
    private const ALLOWED_FIELDS = [
        'recordType',
        'productId',
        'packId',
        'barcode',
        'name',
        'brand',
        'privateName',
        'packText',
    ];

    /** @var list<string> */
    private const FORBIDDEN_MUTATION_FIELDS = [
        'quantity',
        'quantities',
        'stock',
        'onhand',
        'on_hand',
        'price',
        'prices',
        'unitprice',
        'unit_price',
        'currency',
        'storeprice',
        'store_price',
    ];

    public function __construct(
        private readonly CatalogImportStore $store,
        private readonly HomeAuthorization $homes,
        private readonly UuidGenerator $ids,
        private readonly Clock $clock,
        private readonly TransactionManager $transactions,
        private readonly ChangeFeedWriter $changes,
    ) {
    }

    /**
     * @param list<mixed> $records
     * @return array<string, mixed>
     */
    public function stage(
        AuthenticatedIdentity $identity,
        string $homeId,
        string $idempotencyKey,
        array $records,
    ): array {
        $this->homes->requirePermission($identity, $homeId, HomePermission::CATALOG_IMPORT);
        $idempotencyKey = trim($idempotencyKey);
        if (strlen($idempotencyKey) < 8 || strlen($idempotencyKey) > 128) {
            throw new Problem(422, 'Invalid catalog import', 'An 8 to 128 character Idempotency-Key is required.');
        }
        if ($records === [] || count($records) > self::MAX_ROWS) {
            throw new Problem(422, 'Invalid catalog import', 'The import must contain between 1 and 500 rows.');
        }

        try {
            $canonical = json_encode($this->canonicalize($records), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        } catch (JsonException) {
            throw new Problem(422, 'Invalid catalog import', 'The import rows must contain valid JSON values.');
        }
        if (strlen($canonical) > self::MAX_BYTES) {
            throw new Problem(413, 'Catalog import too large', 'The normalized import exceeds 1 MiB.');
        }

        $keyHash = hash('sha256', $idempotencyKey);
        $contentHash = hash('sha256', $canonical);
        $existing = $this->store->findByIdempotency($homeId, $keyHash);
        if ($existing !== null) {
            if (! hash_equals((string) $existing['contentHash'], $contentHash)) {
                throw new Problem(
                    409,
                    'Idempotency conflict',
                    'The Idempotency-Key was already used for different rows.',
                );
            }
            $existing['replayed'] = true;

            return $existing;
        }

        $seen = [];
        $staged = [];
        foreach ($records as $position => $record) {
            $row = $this->stageRow($homeId, (int) $position, $record);
            if ($row['errorCode'] === null) {
                /** @var string $deduplicationKey */
                $deduplicationKey = $row['deduplicationKey'];
                if (isset($seen[$deduplicationKey])) {
                    $row = $this->errorRow(
                        (int) $position,
                        is_array($record) ? $record : [],
                        'duplicate_in_batch',
                        'The row duplicates an earlier normalized catalog identity in this batch.',
                    );
                } else {
                    $seen[$deduplicationKey] = true;
                }
            }
            $staged[] = $row;
        }

        $id = $this->ids->generate();
        $at = $this->clock->now();
        $created = $this->transactions->transactional(fn (): bool => $this->store->createBatch(
            $id,
            $homeId,
            $identity->userId,
            $keyHash,
            $contentHash,
            $staged,
            $at,
        ));
        if (! $created) {
            $existing = $this->store->findByIdempotency($homeId, $keyHash);
            if ($existing === null || ! hash_equals((string) $existing['contentHash'], $contentHash)) {
                throw new Problem(409, 'Catalog import conflict', 'The import could not be staged safely.');
            }
            $existing['replayed'] = true;

            return $existing;
        }

        $batch = $this->store->batch($homeId, $id);
        if ($batch === null) {
            throw new \LogicException('A staged catalog import could not be read back.');
        }
        $batch['replayed'] = false;

        return $batch;
    }

    /** @return array<string, mixed> */
    public function get(AuthenticatedIdentity $identity, string $homeId, string $batchId): array
    {
        $this->homes->requirePermission($identity, $homeId, HomePermission::CATALOG_IMPORT);
        $batch = $this->store->batch($homeId, $batchId);
        if ($batch === null) {
            throw new Problem(404, 'Not found', 'The requested resource is unavailable.');
        }

        return $batch;
    }

    /** @return array<string, mixed> */
    public function confirm(
        AuthenticatedIdentity $identity,
        string $homeId,
        string $batchId,
        int $expectedRevision,
        string $confirmation,
    ): array {
        $this->homes->requirePermission($identity, $homeId, HomePermission::CATALOG_IMPORT);
        if ($confirmation !== self::CONFIRMATION || $expectedRevision < 1) {
            throw new Problem(
                422,
                'Explicit import confirmation required',
                'Supply the staged revision and the apply_catalog_records confirmation value.',
            );
        }
        $batch = $this->store->batch($homeId, $batchId);
        if ($batch === null) {
            throw new Problem(404, 'Not found', 'The requested resource is unavailable.');
        }
        if ((string) $batch['status'] === 'confirmed') {
            $batch['replayed'] = true;

            return $batch;
        }
        if ((string) $batch['status'] !== 'staged' || (int) $batch['revision'] !== $expectedRevision) {
            throw new Problem(409, 'Catalog import conflict', 'The staged import changed or is no longer confirmable.');
        }
        if ((int) $batch['validCount'] < 1) {
            throw new Problem(409, 'No valid catalog rows', 'Correct the row errors and stage a new import.');
        }

        try {
            $at = $this->clock->now();
            $outcome = $this->transactions->transactional(function () use (
                $homeId,
                $batchId,
                $expectedRevision,
                $identity,
                $at,
            ): array {
                $outcome = $this->store->confirmBatch(
                    $homeId,
                    $batchId,
                    $expectedRevision,
                    $identity->userId,
                    $at,
                );
                foreach ($outcome['imported'] as $imported) {
                    $this->changes->put(
                        $homeId,
                        $identity->userId,
                        'inventory-home-product',
                        $imported['id'],
                        1,
                        [
                            'productId' => $imported['productId'],
                            'packId' => $imported['packId'],
                            'privateName' => $imported['privateName'],
                            'originalPackText' => $imported['originalPackText'],
                            'status' => 'active',
                        ],
                        $at,
                    );
                }

                return $outcome;
            });
            $confirmed = $outcome['confirmed'];
        } catch (\DomainException $error) {
            throw new Problem(409, 'Catalog import conflict', $error->getMessage());
        }
        $result = $this->store->batch($homeId, $batchId);
        if (! $confirmed && ($result === null || (string) $result['status'] !== 'confirmed')) {
            throw new Problem(409, 'Catalog import conflict', 'The staged import changed during confirmation.');
        }
        if ($result === null) {
            throw new \LogicException('A confirmed catalog import could not be read back.');
        }
        $result['replayed'] = ! $confirmed;

        return $result;
    }

    /** @return array<string, mixed> */
    private function stageRow(string $homeId, int $position, mixed $record): array
    {
        if (! is_array($record) || array_is_list($record)) {
            return $this->errorRow($position, [], 'invalid_row', 'Each row must be a JSON object.');
        }
        $forbidden = $this->forbiddenField($record);
        if ($forbidden !== null) {
            return $this->errorRow(
                $position,
                $record,
                'unsupported_mutation',
                sprintf('Field "%s" belongs to a separate stock or price workflow.', $forbidden),
            );
        }
        foreach (array_keys($record) as $field) {
            if (! is_string($field) || ! in_array($field, self::ALLOWED_FIELDS, true)) {
                return $this->errorRow(
                    $position,
                    $record,
                    'unsupported_field',
                    'The row contains an unsupported field.',
                );
            }
        }

        $strings = [];
        foreach (self::ALLOWED_FIELDS as $field) {
            $value = $record[$field] ?? null;
            if ($value !== null && ! is_string($value)) {
                return $this->errorRow(
                    $position,
                    $record,
                    'invalid_field_type',
                    'Catalog import fields must be strings.',
                );
            }
            $strings[$field] = $value === null ? '' : trim($value);
        }
        $type = $strings['recordType'];
        if (! in_array($type, ['catalog_product_reference', 'home_product'], true)) {
            return $this->errorRow(
                $position,
                $record,
                'invalid_record_type',
                'recordType must be catalog_product_reference or home_product.',
            );
        }
        foreach (['productId', 'packId'] as $field) {
            if ($strings[$field] !== '' && ! $this->isUuid($strings[$field])) {
                return $this->errorRow($position, $record, 'invalid_identifier', $field . ' must be a UUID.');
            }
        }
        if (strlen($strings['barcode']) > 64) {
            return $this->errorRow($position, $record, 'invalid_barcode', 'barcode may not exceed 64 characters.');
        }
        foreach (['name', 'privateName', 'packText'] as $field) {
            if (mb_strlen($strings[$field]) > 191) {
                return $this->errorRow(
                    $position,
                    $record,
                    'field_too_long',
                    $field . ' may not exceed 191 characters.',
                );
            }
        }
        if (mb_strlen($strings['brand']) > 120) {
            return $this->errorRow($position, $record, 'field_too_long', 'brand may not exceed 120 characters.');
        }
        if (
            $strings['productId'] === ''
            && $strings['packId'] === ''
            && $strings['barcode'] === ''
            && $strings['name'] === ''
            && $strings['privateName'] === ''
        ) {
            return $this->errorRow($position, $record, 'missing_identity', 'A product identifier or name is required.');
        }

        $privateName = $strings['privateName'] !== '' ? $strings['privateName'] : $strings['name'];
        $normalizedName = $this->normalize($strings['name']);
        $normalizedBrand = $this->normalize($strings['brand']);
        $normalizedPrivateName = $this->normalize($privateName);
        $match = $this->store->resolve(
            $homeId,
            $strings['productId'] === '' ? null : $strings['productId'],
            $strings['packId'] === '' ? null : $strings['packId'],
            $strings['barcode'] === '' ? null : $strings['barcode'],
            $normalizedName,
            $normalizedBrand,
            $normalizedPrivateName,
        );

        return $this->resolvedRow(
            $position,
            $type,
            $record,
            $privateName,
            $normalizedPrivateName,
            $strings['packText'],
            $match,
        );
    }

    /**
     * @param array<string, mixed> $record
     * @param array<string, mixed> $match
     * @return array<string, mixed>
     */
    private function resolvedRow(
        int $position,
        string $type,
        array $record,
        string $privateName,
        string $normalizedPrivateName,
        string $packText,
        array $match,
    ): array {
        if ((string) $match['resolution'] === 'error') {
            return $this->errorRow(
                $position,
                $record,
                (string) ($match['errorCode'] ?? 'catalog_resolution_failed'),
                (string) ($match['errorDetail'] ?? 'The catalog identity could not be resolved.'),
            );
        }
        if ((string) $match['resolution'] === 'no_match' && $type === 'catalog_product_reference') {
            return $this->errorRow(
                $position,
                $record,
                'catalog_match_required',
                'No published global catalog product matches this reference.',
            );
        }
        if ((string) $match['resolution'] === 'no_match' && $normalizedPrivateName === '') {
            return $this->errorRow(
                $position,
                $record,
                'private_name_required',
                'A private home product name is required.',
            );
        }

        $productId = isset($match['productId']) ? (string) $match['productId'] : null;
        $packId = isset($match['packId']) ? (string) $match['packId'] : null;
        $homeProductId = isset($match['homeProductId']) ? (string) $match['homeProductId'] : null;
        $resolution = match ((string) $match['resolution']) {
            'existing_home' => 'already_present',
            'global_match' => 'link_catalog',
            default => 'create_private',
        };
        $deduplicationKey = $productId !== null
            ? 'catalog:' . $productId . ':' . ($packId ?? '-')
            : 'private:' . $normalizedPrivateName;

        return [
            'position' => $position,
            'recordType' => $type,
            'payload' => $this->safePayload($record),
            'resolution' => $resolution,
            'targetHomeProductId' => $resolution === 'already_present' ? null : $this->ids->generate(),
            'matchedHomeProductId' => $homeProductId,
            'productId' => $productId,
            'packId' => $packId,
            'privateName' => $resolution === 'create_private' ? $privateName : null,
            'normalizedPrivateName' => $resolution === 'create_private' ? $normalizedPrivateName : null,
            'packText' => $packText === '' ? null : $packText,
            'deduplicationKey' => $deduplicationKey,
            'errorCode' => null,
            'errorDetail' => null,
        ];
    }

    /**
     * @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    private function errorRow(int $position, array $record, string $code, string $detail): array
    {
        return [
            'position' => $position,
            'recordType' => is_string($record['recordType'] ?? null) ? $record['recordType'] : 'invalid',
            'payload' => $this->safePayload($record),
            'resolution' => 'error',
            'targetHomeProductId' => null,
            'matchedHomeProductId' => null,
            'productId' => null,
            'packId' => null,
            'privateName' => null,
            'normalizedPrivateName' => null,
            'packText' => null,
            'deduplicationKey' => null,
            'errorCode' => $code,
            'errorDetail' => $detail,
        ];
    }

    /** @param array<string, mixed> $record */
    private function forbiddenField(array $record): ?string
    {
        foreach ($record as $key => $value) {
            $normalized = strtolower(str_replace(['-', ' '], '_', (string) $key));
            if (
                in_array(str_replace('_', '', $normalized), self::FORBIDDEN_MUTATION_FIELDS, true)
                || in_array($normalized, self::FORBIDDEN_MUTATION_FIELDS, true)
            ) {
                return (string) $key;
            }
            if (is_array($value) && ! array_is_list($value)) {
                $nested = $this->forbiddenField($value);
                if ($nested !== null) {
                    return (string) $key . '.' . $nested;
                }
            }
        }

        return null;
    }

    private function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value) ?? $value;

        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }

    /**
     * @param array<string, mixed> $record
     * @return array<string, string>
     */
    private function safePayload(array $record): array
    {
        $safe = [];
        foreach (self::ALLOWED_FIELDS as $field) {
            if (isset($record[$field]) && is_string($record[$field])) {
                $safe[$field] = trim($record[$field]);
            }
        }

        return $safe;
    }

    private function isUuid(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value) === 1;
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as $key => $child) {
            $value[$key] = $this->canonicalize($child);
        }

        return $value;
    }
}
