<?php

declare(strict_types=1);

namespace Providentia\Catalog\Infrastructure\Doctrine;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use DomainException;
use Providentia\Catalog\Application\CatalogGovernanceStore;
use Providentia\Catalog\Application\CatalogMergeHomeProductGateway;
use Providentia\SharedKernel\Application\UuidGenerator;

final class DbalCatalogGovernanceStore implements CatalogGovernanceStore, \Providentia\Catalog\Application\CatalogMaintenanceStore
{
    public function __construct(
        private readonly Connection $connection,
        private readonly UuidGenerator $ids,
        private readonly CatalogMergeHomeProductGateway $homeProducts,
    ) {
    }

    /** @return array{table: string, fields: array<string, array{string, int, bool}>} */
    private function entityDefinition(string $type): array
    {
        return match ($type) {
            'category' => [
                'table' => 'categories',
                'fields' => ['canonicalName' => ['canonical_name', 191, false]],
            ],
            'product' => [
                'table' => 'products',
                'fields' => [
                    'canonicalName' => ['canonical_name', 191, false],
                    'brand' => ['brand', 120, true],
                    'categoryId' => ['category_id', 36, false],
                ],
            ],
            'unit' => [
                'table' => 'units',
                'fields' => [
                    'symbol' => ['symbol', 32, false],
                    'name' => ['name', 80, false],
                    'dimension' => ['dimension', 40, false],
                    'baseFactor' => ['base_factor', 21, false],
                ],
            ],
            'pack' => [
                'table' => 'product_packs',
                'fields' => [
                    'productId' => ['product_id', 36, false],
                    'variantId' => ['variant_id', 36, true],
                    'unitId' => ['unit_id', 36, true],
                    'originalPackText' => ['original_pack_text', 191, false],
                    'amount' => ['amount', 21, true],
                    'multiplicity' => ['multiplicity', 4, false],
                ],
            ],
            'variant' => [
                'table' => 'product_variants',
                'fields' => [
                    'productId' => ['product_id', 36, false],
                    'canonicalLabel' => ['canonical_label', 191, false],
                    'attributesJson' => ['attributes_json', 8000, false],
                ],
            ],
            'alias' => [
                'table' => 'product_aliases',
                'fields' => [
                    'productId' => ['product_id', 36, false],
                    'variantId' => ['variant_id', 36, true],
                    'packId' => ['pack_id', 36, true],
                    'rawAlias' => ['raw_alias', 191, false],
                ],
            ],
            'barcode' => [
                'table' => 'product_barcodes',
                'fields' => [
                    'packId' => ['pack_id', 36, false],
                    'barcode' => ['barcode', 64, false],
                    'barcodeType' => ['barcode_type', 24, false],
                ],
            ],
            'identity-rule' => [
                'table' => 'product_identity_rules',
                'fields' => [
                    'ruleKey' => ['rule_key', 80, false],
                    'family' => ['family', 191, false],
                    'ruleDefinition' => ['rule_definition', 16000, false],
                    'provenance' => ['provenance', 191, false],
                ],
            ],
            default => throw new DomainException('Unsupported catalog entity.'),
        };
    }

    public function entities(string $type, int $offset): array
    {
        $definition = $this->entityDefinition($type);
        $rows = $this->connection->fetchAllAssociative(
            'SELECT * FROM ' .
                $definition['table'] .
                ($type === 'alias' ? " WHERE scope = 'global' AND home_id IS NULL" : '') .
                ' ORDER BY id LIMIT 100 OFFSET ' .
                max(0, $offset),
        );
        return array_map(fn(array $row): array => $this->entityRepresentation($type, $row), $rows);
    }

    /** @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function entityRepresentation(string $type, array $row): array
    {
        $fields = [];
        foreach ($this->entityDefinition($type)['fields'] as $key => [$column]) {
            $fields[$key] = $row[$column] === null ? null : (string) $row[$column];
        }
        return [
            'id' => (string) $row['id'],
            'type' => $type,
            'status' => $row['status'] === 'approved' ? 'published' : (string) $row['status'],
            'revision' => (int) $row['revision'],
            'fields' => $fields,
        ];
    }

    public function saveEntity(
        string $type,
        string $id,
        array $fields,
        string $status,
        int $expectedRevision,
        string $reason,
        string $actorId,
        DateTimeImmutable $at,
    ): array {
        $definition = $this->entityDefinition($type);
        $table = $definition['table'];
        $actual = array_keys($fields);
        $expected = array_keys($definition['fields']);
        sort($actual);
        sort($expected);
        if ($actual !== $expected) {
            throw new DomainException('Supply exactly the documented fields for this entity.');
        }
        $values = [];
        foreach ($definition['fields'] as $key => [$column, $maximum, $nullable]) {
            $value = $fields[$key];
            if ($value === null && $nullable && $key !== 'brand') {
                $values[$column] = null;
                continue;
            }
            if (!is_string($value) || mb_strlen($value) > $maximum || (!$nullable && trim($value) === '')) {
                throw new DomainException('A catalog field is missing or exceeds its supported length.');
            }
            $values[$column] = trim($value);
        }
        $before = $this->one($this->forUpdate('SELECT * FROM ' . $table . ' WHERE id = :id'), ['id' => $id]);
        if (($before === null ? 0 : (int) $before['revision']) !== $expectedRevision) {
            throw new DomainException('This entity changed. Reload its current revision before editing.');
        }
        if (
            $type === 'alias' &&
            $before !== null &&
            ($before['scope'] !== 'global' || $before['home_id'] !== null)
        ) {
            throw new DomainException('Private aliases cannot be managed through the global catalog.');
        }
        if (
            $before !== null &&
            !in_array($before['status'], ['published', 'approved', 'pending-normalization', 'archived'], true)
        ) {
            throw new DomainException('Use the existing governance workflow for this entity state.');
        }
        $this->validateEntityReferences($type, $values, $before, $status, $id);
        foreach (
            [
                'canonical_name' => 'normalized_name',
                'brand' => 'normalized_brand',
                'canonical_label' => 'normalized_label',
                'raw_alias' => 'normalized_alias',
            ]
            as $source => $target
        ) {
            if (isset($values[$source])) {
                $values[$target] = $this->normalize((string) $values[$source]);
            }
        }
        if ($type === 'category' && $before === null) {
            $values['parent_id'] = null;
        }
        if ($type === 'pack') {
            $values['source_key'] = $before['source_key'] ?? 'catalog-maintenance:' . $id;
        }
        if ($type === 'alias') {
            $values += ['scope' => 'global', 'home_id' => null, 'approval_source' => 'curator'];
        }
        if ($type === 'barcode') {
            $values['verification_status'] = 'verified';
        }
        $storedStatus =
            $status === 'published' && in_array($type, ['alias', 'identity-rule'], true)
                ? 'approved'
                : $status;
        $values += [
            'status' => $storedStatus,
            'revision' => $expectedRevision + 1,
            'updated_at' => $this->date($at),
        ];
        try {
            if ($before === null) {
                $this->connection->insert($table, $values + ['id' => $id, 'created_at' => $this->date($at)]);
            } elseif (
                $this->connection->update($table, $values, ['id' => $id, 'revision' => $expectedRevision]) !==
                1
            ) {
                throw new DomainException('The catalog entity changed concurrently.');
            }
        } catch (UniqueConstraintViolationException) {
            throw new DomainException('A catalog entity with this identity already exists.');
        }
        $after = array_merge($before ?? [], $values, ['id' => $id]);
        $this->recordRevision(
            $this->ids->generate(),
            $type,
            $id,
            $before,
            $after,
            $reason,
            $actorId,
            $this->ids->generate(),
            $at,
        );
        if ($type === 'product' && $before === null && $status === 'published') {
            $this->saveEntity(
                'pack',
                $this->ids->generate(),
                [
                    'productId' => $id,
                    'variantId' => null,
                    'unitId' => null,
                    'originalPackText' => 'Unspecified pack',
                    'amount' => null,
                    'multiplicity' => '1',
                ],
                'published',
                0,
                'Initial selectable pack: ' . $reason,
                $actorId,
                $at,
            );
        }
        return $this->entityRepresentation($type, $after);
    }

    /** @param array<string, mixed> $values
     * @param array<string, mixed>|null $before
     */
    private function validateEntityReferences(
        string $type,
        array &$values,
        ?array $before,
        string $status,
        string $id,
    ): void {
        foreach (
            [
                'category_id' => 'categories',
                'product_id' => 'products',
                'variant_id' => 'product_variants',
                'pack_id' => 'product_packs',
                'unit_id' => 'units',
            ]
            as $field => $table
        ) {
            if (!isset($values[$field])) {
                continue;
            }
            $reference = $this->one($this->forUpdate('SELECT * FROM ' . $table . ' WHERE id = :id'), [
                'id' => $values[$field],
            ]);
            if ($reference === null || ($status === 'published' && $reference['status'] !== 'published')) {
                throw new DomainException('Choose currently published catalog references.');
            }
            if (
                isset($values['product_id'], $reference['product_id']) &&
                $values['product_id'] !== $reference['product_id']
            ) {
                throw new DomainException('The variant or pack belongs to another product.');
            }
        }
        if ($type === 'unit') {
            \Providentia\Catalog\Domain\PackMeasure::normalize('1', (string) $values['base_factor'], 1);
            if (preg_match('/^0+(?:\.0+)?$/', (string) $values['base_factor']) === 1) {
                throw new DomainException('Unit conversion factors must be positive.');
            }
            if (
                $before !== null &&
                ($before['dimension'] !== $values['dimension'] ||
                    (string) $before['base_factor'] !== $values['base_factor'])
            ) {
                $this->requireUnused('product_packs', 'unit_id', $id);
            }
        }
        if ($type === 'pack') {
            $multiplicity = filter_var($values['multiplicity'], FILTER_VALIDATE_INT);
            if (
                $multiplicity === false ||
                $multiplicity < 1 ||
                $multiplicity > 1000 ||
                ($values['amount'] === null) !== ($values['unit_id'] === null)
            ) {
                throw new DomainException(
                    'A pack requires a paired amount/unit and multiplicity from 1 to 1000.',
                );
            }
            $values['normalized_base_amount'] =
                $values['amount'] === null
                    ? null
                    : \Providentia\Catalog\Domain\PackMeasure::normalize(
                        (string) $values['amount'],
                        (string) $this->connection->fetchOne('SELECT base_factor FROM units WHERE id = ?', [
                            $values['unit_id'],
                        ]),
                        $multiplicity,
                    );
            if ($before !== null) {
                foreach (['product_id', 'variant_id', 'unit_id', 'amount', 'multiplicity'] as $field) {
                    if ((string) $before[$field] !== (string) $values[$field]) {
                        $this->requireUnused('home_products', 'pack_id', $id);
                    }
                }
            }
        }
        if ($type === 'identity-rule') {
            try {
                $rule = json_decode((string) $values['rule_definition'], false, 32, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw new DomainException('Identity rule definitions must be JSON objects.');
            }
            if (!($rule instanceof \stdClass)) {
                throw new DomainException('Identity rule definitions must be JSON objects.');
            }
        }
        if ($type === 'variant') {
            try {
                $attributes = json_decode(
                    (string) $values['attributes_json'],
                    false,
                    32,
                    JSON_THROW_ON_ERROR,
                );
            } catch (\JsonException) {
                throw new DomainException('Variant attributes must be a JSON object.');
            }
            if (!($attributes instanceof \stdClass)) {
                throw new DomainException('Variant attributes must be a JSON object.');
            }
            if ($before !== null && $before['product_id'] !== $values['product_id']) {
                $this->requireUnused('product_packs', 'variant_id', $id);
                $this->requireUnused('product_aliases', 'variant_id', $id);
            }
        }
        if ($type === 'barcode') {
            $lengths = ['gtin-8' => 8, 'gtin-12' => 12, 'gtin-13' => 13, 'gtin-14' => 14];
            $code = (string) $values['barcode'];
            $kind = (string) $values['barcode_type'];
            if ($kind === 'other') {
                if (preg_match('/^[0-9A-Za-z]{6,64}$/', $code) !== 1) {
                    throw new DomainException('Invalid barcode.');
                }
            } else {
                if (!isset($lengths[$kind]) || strlen($code) !== $lengths[$kind] || !ctype_digit($code)) {
                    throw new DomainException('Invalid GTIN format.');
                }
                $sum = 0;
                for ($i = strlen($code) - 2, $weight = 3; $i >= 0; --$i, $weight = 4 - $weight) {
                    $sum += (int) $code[$i] * $weight;
                }
                if ((10 - ($sum % 10)) % 10 !== (int) substr($code, -1)) {
                    throw new DomainException('Invalid GTIN check digit.');
                }
            }
        }
        if ($type === 'alias' && $status === 'published') {
            $existing = $this->connection->fetchOne(
                "SELECT id FROM product_aliases WHERE scope = 'global' AND normalized_alias = ? AND status = 'approved' AND id <> ?",
                [$this->normalize((string) $values['raw_alias']), $id],
            );
            if ($existing !== false) {
                throw new DomainException('Resolve the existing alias conflict before publishing.');
            }
        }
        if ($status === 'archived') {
            $references = match ($type) {
                'category' => [['products', 'category_id']],
                'product' => [
                    ['product_packs', 'product_id'],
                    ['product_variants', 'product_id'],
                    ['product_aliases', 'product_id'],
                    ['home_products', 'product_id'],
                ],
                'unit' => [['product_packs', 'unit_id']],
                'pack' => [
                    ['product_barcodes', 'pack_id'],
                    ['product_aliases', 'pack_id'],
                    ['home_products', 'pack_id'],
                ],
                'variant' => [['product_packs', 'variant_id'], ['product_aliases', 'variant_id']],
                default => [],
            };
            foreach ($references as [$table, $column]) {
                $this->requireUnused($table, $column, $id, true);
            }
        }
    }

    private function requireUnused(string $table, string $column, string $id, bool $activeOnly = false): void
    {
        $used = $this->connection->fetchOne(
            'SELECT id FROM ' .
                $table .
                ' WHERE ' .
                $column .
                ' = ?' .
                ($activeOnly ? " AND status <> 'archived'" : '') .
                ' LIMIT 1',
            [$id],
        );
        if ($used !== false) {
            throw new DomainException(
                'This entity is in use. Retire its active dependents or create a new identity to preserve history.',
            );
        }
    }

    /**
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>|null
     */
    public function conflictFor(string $type, string $normalizedKey, array $payload): ?array
    {
        $row = match ($type) {
            'category' => $this->one(
                'SELECT id AS entityId FROM categories
                 WHERE normalized_name = :name AND status <> :archived',
                [
                    'name' => $this->normalize((string) $payload['canonicalName']),
                    'archived' => 'archived',
                ],
            ),
            'product' => $this->one(
                'SELECT id AS entityId FROM products
                 WHERE category_id = :category AND normalized_name = :name
                   AND normalized_brand = :brand AND status <> :archived',
                [
                    'category' => $payload['categoryId'],
                    'name' => $this->normalize((string) $payload['canonicalName']),
                    'brand' => $this->normalize((string) $payload['brand']),
                    'archived' => 'archived',
                ],
            ),
            'pack' => $this->one(
                'SELECT id AS entityId FROM product_packs
                 WHERE product_id = :product AND LOWER(original_pack_text) = :pack
                   AND status <> :archived',
                [
                    'product' => $payload['productId'],
                    'pack' => mb_strtolower((string) $payload['originalPackText']),
                    'archived' => 'archived',
                ],
            ),
            'alias' => $this->one(
                'SELECT id AS entityId FROM product_aliases
                 WHERE scope = :scope AND normalized_alias = :alias
                   AND status = :status',
                [
                    'scope' => 'global',
                    'alias' => $this->normalize((string) $payload['rawAlias']),
                    'status' => 'approved',
                ],
            ),
            'barcode' => $this->one(
                'SELECT id AS entityId FROM product_barcodes WHERE barcode = :barcode',
                ['barcode' => $payload['barcode']],
            ),
            default => null,
        };
        if ($row === null) {
            return null;
        }
        $row['conflictKey'] = $normalizedKey;
        $row['conflictType'] = match ($type) {
            'category', 'product', 'pack' => 'duplicate',
            'alias' => 'alias',
            'barcode' => 'barcode',
            default => 'unknown',
        };

        return $row;
    }

    public function createProposal(
        string $id,
        string $type,
        string $normalizedKey,
        array $payload,
        string $status,
        ?string $duplicateEntityId,
        string $actorUserId,
        DateTimeImmutable $at,
    ): void {
        $now = $this->date($at);
        $this->connection->insert('catalog_proposals', [
            'id' => $id,
            'proposal_json' => $this->json($payload),
            'proposal_type' => $type,
            'normalized_key' => $normalizedKey,
            'moderation_status' => $status,
            'submitted_by_user_id' => $actorUserId,
            'duplicate_entity_id' => $duplicateEntityId,
            'resolved_entity_type' => null,
            'resolved_entity_id' => null,
            'moderation_reason' => null,
            'reviewed_by_user_id' => '',
            'reviewed_at' => null,
            'revision' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        if ($duplicateEntityId === null) {
            return;
        }
        $conflictType = match ($type) {
            'category', 'product', 'pack' => 'duplicate',
            'alias' => 'alias',
            'barcode' => 'barcode',
            default => 'unknown',
        };
        $this->connection->insert('catalog_conflicts', [
            'id' => $this->ids->generate(),
            'conflict_type' => $conflictType,
            'conflict_key' => $normalizedKey,
            'proposal_id' => $id,
            'existing_entity_id' => $duplicateEntityId,
            'candidate_entity_id' => null,
            'details_json' => $this->json([
                'proposalType' => $type,
                'reason' => 'exact-deterministic-match',
            ]),
            'status' => 'open',
            'revision' => 1,
            'reviewed_by_user_id' => null,
            'reviewed_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function proposal(string $id): ?array
    {
        $row = $this->one(
            'SELECT id, proposal_type AS proposalType, proposal_json AS proposalJson,
                    normalized_key AS normalizedKey,
                    moderation_status AS moderationStatus,
                    duplicate_entity_id AS duplicateEntityId, revision,
                    created_at AS createdAt, updated_at AS updatedAt
             FROM catalog_proposals WHERE id = :id',
            ['id' => $id],
        );
        if ($row === null) {
            return null;
        }
        $row['payload'] = $this->decode((string) $row['proposalJson']);
        unset($row['proposalJson']);

        return $row;
    }

    public function proposalSourceEligible(string $proposalId): bool
    {
        $sql = 'SELECT c.moderation_status AS contributionStatus, c.revision,
                       l.contribution_revision AS linkedRevision
                FROM catalog_contribution_proposals l
                INNER JOIN catalog_contributions c ON c.id = l.contribution_id
                WHERE l.proposal_id = :proposal';
        if (! $this->connection->getDatabasePlatform() instanceof SQLitePlatform) {
            $sql .= ' FOR UPDATE';
        }
        $row = $this->one($sql, ['proposal' => $proposalId]);
        if ($row === null) {
            return true;
        }

        return (string) $row['contributionStatus'] === 'approved'
            && (int) $row['revision'] === (int) $row['linkedRevision'];
    }

    public function workbench(string $queue, int $limit, int $offset): array
    {
        if ($queue === 'proposals') {
            $rows = $this->connection->fetchAllAssociative(
                'SELECT id, proposal_type AS proposalType, proposal_json AS proposalJson,
                        moderation_status AS moderationStatus,
                        duplicate_entity_id AS duplicateEntityId, revision,
                        created_at AS createdAt, updated_at AS updatedAt
                 FROM catalog_proposals
                 WHERE moderation_status IN (:pending, :conflict)
                 ORDER BY created_at, id
                 LIMIT ' . $limit . ' OFFSET ' . $offset,
                ['pending' => 'pending', 'conflict' => 'conflict'],
            );
            foreach ($rows as &$row) {
                $row['payload'] = $this->decode((string) $row['proposalJson']);
                unset($row['proposalJson']);
            }
            unset($row);

            return $rows;
        }
        if (in_array($queue, ['duplicates', 'aliases', 'barcodes'], true)) {
            $type = $queue === 'duplicates'
                ? 'duplicate'
                : ($queue === 'aliases' ? 'alias' : 'barcode');
            $rows = $this->connection->fetchAllAssociative(
                'SELECT c.id, c.conflict_type AS conflictType,
                        c.conflict_key AS conflictKey, c.proposal_id AS proposalId,
                        c.existing_entity_id AS existingEntityId,
                        c.candidate_entity_id AS candidateEntityId,
                        c.details_json AS detailsJson, c.status, c.revision,
                        c.created_at AS createdAt, c.updated_at AS updatedAt,
                        p.proposal_type AS proposalType, p.proposal_json AS proposalJson
                 FROM catalog_conflicts c
                 LEFT JOIN catalog_proposals p ON p.id = c.proposal_id
                 WHERE c.conflict_type = :type AND c.status = :status
                 ORDER BY c.created_at, c.id
                 LIMIT ' . $limit . ' OFFSET ' . $offset,
                ['type' => $type, 'status' => 'open'],
            );
            foreach ($rows as &$row) {
                $row['details'] = $this->decode((string) $row['detailsJson']);
                $row['proposal'] = $row['proposalJson'] === null
                    ? null
                    : $this->decode((string) $row['proposalJson']);
                unset($row['detailsJson'], $row['proposalJson']);
            }
            unset($row);

            return $rows;
        }
        if ($queue === 'icons') {
            return $this->connection->fetchAllAssociative(
                "SELECT 'product' AS targetType, p.id AS targetId,
                        p.canonical_name AS canonicalName, p.revision
                 FROM products p
                 WHERE p.status = :published
                   AND NOT EXISTS (
                       SELECT 1 FROM catalog_icons i
                       WHERE i.target_type = 'product' AND i.target_id = p.id
                         AND i.status = :active
                   )
                 ORDER BY p.canonical_name, p.id
                 LIMIT " . $limit . ' OFFSET ' . $offset,
                ['published' => 'published', 'active' => 'active'],
            );
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, survivor_id AS survivorId, merged_ids_json AS mergedIdsJson,
                    reason, status, revision, applied_at AS appliedAt,
                    reversed_at AS reversedAt, created_at AS createdAt
             FROM catalog_merge_events
             ORDER BY created_at DESC, id DESC
             LIMIT ' . $limit . ' OFFSET ' . $offset,
        );
        foreach ($rows as &$row) {
            $row['mergedIds'] = $this->decodeList((string) $row['mergedIdsJson']);
            unset($row['mergedIdsJson']);
        }
        unset($row);

        return $rows;
    }

    /**
     *
     * @param array<string, mixed> $proposal
     *
     * @return array{entityType: string, entityId: string}
     */
    public function publishProposal(
        array $proposal,
        string $entityId,
        string $actorUserId,
        DateTimeImmutable $at,
    ): array {
        /** @var array<string, mixed> $payload */
        $payload = $proposal['payload'];
        $type = (string) $proposal['proposalType'];
        $now = $this->date($at);

        if ($type === 'category') {
            try {
                $this->connection->insert('categories', [
                    'id' => $entityId,
                    'parent_id' => null,
                    'canonical_name' => $payload['canonicalName'],
                    'normalized_name' => $this->normalize((string) $payload['canonicalName']),
                    'status' => 'published',
                    'revision' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } catch (UniqueConstraintViolationException) {
                throw new DomainException('A matching category was published concurrently.');
            }

            return ['entityType' => 'category', 'entityId' => $entityId];
        }
        if ($type === 'product') {
            if (! $this->exists('categories', (string) $payload['categoryId'], 'published')) {
                throw new DomainException('The proposed category is unavailable.');
            }
            try {
                $this->connection->insert('products', [
                    'id' => $entityId,
                    'category_id' => $payload['categoryId'],
                    'canonical_name' => $payload['canonicalName'],
                    'normalized_name' => $this->normalize((string) $payload['canonicalName']),
                    'brand' => $payload['brand'],
                    'normalized_brand' => $this->normalize((string) $payload['brand']),
                    'status' => 'published',
                    'revision' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } catch (UniqueConstraintViolationException) {
                throw new DomainException('A matching product was published concurrently.');
            }

            if (array_key_exists('packText', $payload)) {
                $packId = $this->ids->generate();
                $this->saveEntity('pack', $packId, [
                    'productId' => $entityId, 'variantId' => null, 'unitId' => null,
                    'originalPackText' => trim((string) $payload['packText']) ?: 'Unspecified pack',
                    'amount' => null, 'multiplicity' => '1',
                ], 'published', 0, 'Pack from approved product contribution', $actorUserId, $at);
                if (isset($payload['barcode']) && trim((string) $payload['barcode']) !== '') {
                    $barcode = trim((string) $payload['barcode']);
                    $kind = ctype_digit($barcode) && in_array(strlen($barcode), [8, 12, 13, 14], true)
                        ? 'gtin-' . strlen($barcode) : 'other';
                    $this->saveEntity('barcode', $this->ids->generate(), [
                        'packId' => $packId, 'barcode' => $barcode, 'barcodeType' => $kind,
                    ], 'published', 0, 'Barcode from approved product contribution', $actorUserId, $at);
                }
            }
            return ['entityType' => 'product', 'entityId' => $entityId];
        }
        if ($type === 'pack') {
            if (! $this->exists('products', (string) $payload['productId'], 'published')) {
                throw new DomainException('The proposed product is unavailable.');
            }
            $baseAmount = null;
            if ($payload['unitId'] !== null) {
                $unit = $this->one(
                    'SELECT base_factor AS baseFactor FROM units
                     WHERE id = :id AND status = :status',
                    ['id' => $payload['unitId'], 'status' => 'published'],
                );
                if ($unit === null) {
                    throw new DomainException('The proposed unit is unavailable.');
                }
                if ($payload['amount'] !== null) {
                    $baseAmount = \Providentia\Catalog\Domain\PackMeasure::normalize(
                        (string) $payload['amount'], (string) $unit['baseFactor'], (int) $payload['multiplicity'],
                    );
                }
            }
            $this->connection->insert('product_packs', [
                'id' => $entityId,
                'product_id' => $payload['productId'],
                'variant_id' => null,
                'unit_id' => $payload['unitId'],
                'source_key' => 'catalog-proposal:' . $proposal['id'],
                'original_pack_text' => $payload['originalPackText'],
                'amount' => $payload['amount'],
                'normalized_base_amount' => $baseAmount,
                'multiplicity' => $payload['multiplicity'],
                'status' => 'published',
                'revision' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return ['entityType' => 'pack', 'entityId' => $entityId];
        }
        if ($type === 'alias') {
            $productId = (string) $payload['productId'];
            if (! $this->exists('products', $productId, 'published')) {
                throw new DomainException('The proposed product is unavailable.');
            }
            $this->assertOptionalChild('product_variants', $payload['variantId'], $productId);
            $this->assertOptionalChild('product_packs', $payload['packId'], $productId);
            $this->connection->insert('product_aliases', [
                'id' => $entityId,
                'scope' => 'global',
                'home_id' => null,
                'product_id' => $productId,
                'variant_id' => $payload['variantId'],
                'pack_id' => $payload['packId'],
                'raw_alias' => $payload['rawAlias'],
                'normalized_alias' => $this->normalize((string) $payload['rawAlias']),
                'approval_source' => 'moderated-catalog-proposal:' . $proposal['id'],
                'status' => 'approved',
                'revision' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return ['entityType' => 'alias', 'entityId' => $entityId];
        }
        if ($type === 'barcode') {
            if (! $this->exists('product_packs', (string) $payload['packId'], 'published')) {
                throw new DomainException('The proposed pack is unavailable.');
            }
            $this->connection->insert('product_barcodes', [
                'id' => $entityId,
                'pack_id' => $payload['packId'],
                'barcode' => $payload['barcode'],
                'barcode_type' => $payload['barcodeType'],
                'verification_status' => 'verified',
                'status' => 'published',
                'revision' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return ['entityType' => 'barcode', 'entityId' => $entityId];
        }

        throw new DomainException('The proposal type cannot be published.');
    }

    public function decideProposal(
        string $proposalId,
        string $decision,
        string $reason,
        int $expectedRevision,
        ?string $resolvedEntityType,
        ?string $resolvedEntityId,
        string $actorUserId,
        string $revisionId,
        DateTimeImmutable $at,
    ): bool {
        $now = $this->date($at);
        $status = $decision === 'approve' ? 'approved' : 'rejected';
        $allowedStatus = $decision === 'approve' ? 'pending' : null;
        $sql = 'UPDATE catalog_proposals
                SET moderation_status = :status, moderation_reason = :reason,
                    resolved_entity_type = :entity_type, resolved_entity_id = :entity_id,
                    reviewed_by_user_id = :actor, reviewed_at = :reviewed,
                    revision = revision + 1, updated_at = :updated
                WHERE id = :id AND revision = :revision';
        $parameters = [
            'status' => $status,
            'reason' => $reason,
            'entity_type' => $resolvedEntityType,
            'entity_id' => $resolvedEntityId,
            'actor' => $actorUserId,
            'reviewed' => $now,
            'updated' => $now,
            'id' => $proposalId,
            'revision' => $expectedRevision,
        ];
        if ($allowedStatus !== null) {
            $sql .= ' AND moderation_status = :allowed_status';
            $parameters['allowed_status'] = $allowedStatus;
        } else {
            $sql .= ' AND moderation_status IN (:pending, :conflict)';
            $parameters['pending'] = 'pending';
            $parameters['conflict'] = 'conflict';
        }
        if ($this->connection->executeStatement($sql, $parameters) !== 1) {
            return false;
        }
        $this->connection->executeStatement(
            'UPDATE catalog_conflicts
             SET status = :status, reviewed_by_user_id = :actor,
                 reviewed_at = :reviewed, revision = revision + 1, updated_at = :updated
             WHERE proposal_id = :proposal AND status = :open',
            [
                'status' => 'resolved',
                'actor' => $actorUserId,
                'reviewed' => $now,
                'updated' => $now,
                'proposal' => $proposalId,
                'open' => 'open',
            ],
        );
        $this->recordRevision(
            $revisionId,
            'catalog_proposal',
            $proposalId,
            ['status' => $allowedStatus ?? 'pending-or-conflict'],
            ['status' => $status, 'resolvedEntityType' => $resolvedEntityType, 'resolvedEntityId' => $resolvedEntityId],
            $reason,
            $actorUserId,
            $proposalId,
            $at,
        );

        return true;
    }

    public function resolveConflictKeepingExisting(
        string $conflictId,
        int $expectedRevision,
        string $reason,
        string $actorUserId,
        string $revisionId,
        DateTimeImmutable $at,
    ): bool {
        $conflict = $this->one(
            'SELECT id, proposal_id AS proposalId, existing_entity_id AS existingEntityId,
                    conflict_type AS conflictType, revision
             FROM catalog_conflicts
             WHERE id = :id AND status = :status',
            ['id' => $conflictId, 'status' => 'open'],
        );
        if ($conflict === null || (int) $conflict['revision'] !== $expectedRevision) {
            return false;
        }
        $now = $this->date($at);
        if (
            $this->connection->executeStatement(
                'UPDATE catalog_conflicts
                 SET status = :resolved, reviewed_by_user_id = :actor,
                     reviewed_at = :reviewed, revision = revision + 1, updated_at = :updated
                 WHERE id = :id AND status = :open AND revision = :revision',
                [
                    'resolved' => 'resolved',
                    'actor' => $actorUserId,
                    'reviewed' => $now,
                    'updated' => $now,
                    'id' => $conflictId,
                    'open' => 'open',
                    'revision' => $expectedRevision,
                ],
            ) !== 1
        ) {
            return false;
        }
        if ($conflict['proposalId'] !== null) {
            $this->connection->executeStatement(
                'UPDATE catalog_proposals
                 SET moderation_status = :rejected, moderation_reason = :reason,
                     resolved_entity_type = :entity_type, resolved_entity_id = :entity_id,
                     reviewed_by_user_id = :actor, reviewed_at = :reviewed,
                     revision = revision + 1, updated_at = :updated
                 WHERE id = :id AND moderation_status = :conflict',
                [
                    'rejected' => 'rejected',
                    'reason' => $reason,
                    'entity_type' => $conflict['conflictType'],
                    'entity_id' => $conflict['existingEntityId'],
                    'actor' => $actorUserId,
                    'reviewed' => $now,
                    'updated' => $now,
                    'id' => $conflict['proposalId'],
                    'conflict' => 'conflict',
                ],
            );
        }
        $this->recordRevision(
            $revisionId,
            'catalog_conflict',
            $conflictId,
            ['status' => 'open'],
            ['status' => 'resolved', 'resolution' => 'keep-existing'],
            $reason,
            $actorUserId,
            $conflictId,
            $at,
        );

        return true;
    }

    public function putIcon(
        string $id,
        string $targetType,
        string $targetId,
        string $assetDigest,
        string $mediaType,
        string $altText,
        int $width,
        int $height,
        int $byteSize,
        string $provenance,
        int $expectedRevision,
        string $actorUserId,
        string $revisionId,
        DateTimeImmutable $at,
    ): array {
        $table = $targetType === 'product' ? 'products' : 'categories';
        if (! $this->exists($table, $targetId, 'published')) {
            throw new DomainException('The icon target is unavailable.');
        }
        $asset = $this->one(
            'SELECT media_type AS mediaType, width, height, byte_size AS byteSize
             FROM catalog_public_assets WHERE asset_digest = :digest',
            ['digest' => $assetDigest],
        );
        if (
            $asset === null
            || (string) $asset['mediaType'] !== $mediaType
            || (int) $asset['width'] !== $width
            || (int) $asset['height'] !== $height
            || (int) $asset['byteSize'] !== $byteSize
        ) {
            throw new DomainException('The icon asset is unavailable or its metadata does not match.');
        }
        $existing = $this->one(
            'SELECT id, revision, asset_digest AS assetDigest
             FROM catalog_icons
             WHERE target_type = :type AND target_id = :target AND status = :status
             ORDER BY created_at DESC',
            ['type' => $targetType, 'target' => $targetId, 'status' => 'active'],
        );
        $now = $this->date($at);
        if ($existing === null) {
            if ($expectedRevision !== 0) {
                throw new DomainException('The icon revision is stale.');
            }
            try {
                $this->connection->insert('catalog_icons', [
                    'id' => $id,
                    'target_type' => $targetType,
                    'target_id' => $targetId,
                    'asset_digest' => $assetDigest,
                    'media_type' => $mediaType,
                    'provenance' => $provenance,
                    'status' => 'active',
                    'revision' => 1,
                    'alt_text' => $altText,
                    'width' => $width,
                    'height' => $height,
                    'byte_size' => $byteSize,
                    'updated_by_user_id' => $actorUserId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } catch (UniqueConstraintViolationException) {
                throw new DomainException('The icon was created concurrently.');
            }
            $iconId = $id;
            $revision = 1;
            $before = null;
        } else {
            if ((int) $existing['revision'] !== $expectedRevision) {
                throw new DomainException('The icon revision is stale.');
            }
            $iconId = (string) $existing['id'];
            if (
                $this->connection->executeStatement(
                    'UPDATE catalog_icons
                     SET asset_digest = :digest, media_type = :media, alt_text = :alt,
                         width = :width, height = :height, byte_size = :bytes,
                         provenance = :provenance, revision = revision + 1,
                         updated_by_user_id = :actor, updated_at = :updated
                     WHERE id = :id AND revision = :revision AND status = :status',
                    [
                        'digest' => $assetDigest,
                        'media' => $mediaType,
                        'alt' => $altText,
                        'width' => $width,
                        'height' => $height,
                        'bytes' => $byteSize,
                        'provenance' => $provenance,
                        'actor' => $actorUserId,
                        'updated' => $now,
                        'id' => $iconId,
                        'revision' => $expectedRevision,
                        'status' => 'active',
                    ],
                ) !== 1
            ) {
                throw new DomainException('The icon revision is stale.');
            }
            $revision = $expectedRevision + 1;
            $before = ['assetDigest' => $existing['assetDigest']];
        }
        $this->recordRevision(
            $revisionId,
            'catalog_icon',
            $iconId,
            $before,
            ['assetDigest' => $assetDigest, 'targetType' => $targetType, 'targetId' => $targetId],
            'Catalog icon metadata updated',
            $actorUserId,
            $iconId,
            $at,
        );

        return ['id' => $iconId, 'revision' => $revision];
    }

    public function mergePreview(string $survivorId, array $duplicateIds): array
    {
        $products = [];
        $conflicts = [];
        $counts = [
            'variants' => 0,
            'packs' => 0,
            'aliases' => 0,
            'icons' => 0,
            'homeReferences' => 0,
        ];
        $survivor = $this->productIdentity($survivorId);
        if ($survivor === null || (string) $survivor['status'] !== 'published') {
            $conflicts[] = 'survivor-unavailable';
        } else {
            $products[] = $survivor;
            if ($this->activeIncomingRedirects($survivorId) > 0) {
                $conflicts[] = 'survivor-has-active-redirects';
            }
        }
        $comparedIds = [$survivorId];
        foreach ($duplicateIds as $duplicateId) {
            $duplicate = $this->productIdentity($duplicateId);
            if ($duplicate === null || (string) $duplicate['status'] !== 'published') {
                $conflicts[] = 'duplicate-unavailable:' . $duplicateId;
                continue;
            }
            $products[] = $duplicate;
            foreach ($comparedIds as $comparedId) {
                if ($this->variantCollision($comparedId, $duplicateId)) {
                    $conflicts[] = 'variant-label-collision:' . $comparedId . ':' . $duplicateId;
                }
                if ($this->packCollision($comparedId, $duplicateId)) {
                    $conflicts[] = 'pack-identity-collision:' . $comparedId . ':' . $duplicateId;
                }
                if ($this->aliasCollision($comparedId, $duplicateId)) {
                    $conflicts[] = 'approved-alias-collision:' . $comparedId . ':' . $duplicateId;
                }
            }
            $comparedIds[] = $duplicateId;
            $counts['variants'] += $this->count('product_variants', 'product_id', $duplicateId);
            $counts['packs'] += $this->count('product_packs', 'product_id', $duplicateId);
            $counts['aliases'] += $this->count('product_aliases', 'product_id', $duplicateId);
            $counts['homeReferences'] += count($this->homeProducts->references($duplicateId));
            $counts['icons'] += (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM catalog_icons
                 WHERE target_type = :type AND target_id = :id AND status = :status',
                ['type' => 'product', 'id' => $duplicateId, 'status' => 'active'],
            );
        }

        return [
            'survivorId' => $survivorId,
            'duplicateIds' => $duplicateIds,
            'eligible' => $conflicts === [],
            'products' => $products,
            'affectedCounts' => $counts,
            'conflicts' => $conflicts,
        ];
    }

    public function applyMerge(
        string $mergeId,
        string $survivorId,
        int $expectedSurvivorRevision,
        array $duplicateRevisions,
        string $reason,
        string $actorUserId,
        DateTimeImmutable $at,
    ): array {
        $duplicateIds = array_keys($duplicateRevisions);
        $preview = $this->mergePreview($survivorId, $duplicateIds);
        if (($preview['eligible'] ?? false) !== true) {
            throw new DomainException('The merge preview contains unresolved conflicts.');
        }
        $now = $this->date($at);
        if (
            $this->connection->executeStatement(
                'UPDATE products SET revision = revision + 1, updated_at = :updated
                 WHERE id = :id AND status = :status AND revision = :revision',
                [
                    'updated' => $now,
                    'id' => $survivorId,
                    'status' => 'published',
                    'revision' => $expectedSurvivorRevision,
                ],
            ) !== 1
        ) {
            throw new DomainException('The survivor revision changed.');
        }
        $this->connection->insert('catalog_merge_events', [
            'id' => $mergeId,
            'survivor_id' => $survivorId,
            'merged_ids_json' => $this->json($duplicateIds),
            'plan_json' => $this->json(['affectedCounts' => $preview['affectedCounts']]),
            'reason' => $reason,
            'status' => 'applied',
            'revision' => 1,
            'applied_by_user_id' => $actorUserId,
            'applied_at' => $now,
            'reversed_at' => null,
            'reversed_by_user_id' => null,
            'reverse_reason' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        foreach ($duplicateRevisions as $duplicateId => $expectedRevision) {
            if (
                $this->connection->executeStatement(
                    'UPDATE products
                     SET status = :merged, revision = revision + 1, updated_at = :updated
                     WHERE id = :id AND status = :published AND revision = :revision',
                    [
                        'merged' => 'merged',
                        'updated' => $now,
                        'id' => $duplicateId,
                        'published' => 'published',
                        'revision' => $expectedRevision,
                    ],
                ) !== 1
            ) {
                throw new DomainException('A duplicate revision changed.');
            }
            $this->moveReferences($mergeId, $survivorId, $duplicateId, $at);
            $this->connection->insert('catalog_product_redirects', [
                'duplicate_product_id' => $duplicateId,
                'survivor_product_id' => $survivorId,
                'merge_event_id' => $mergeId,
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->recordRevision(
                $this->ids->generate(),
                'product',
                $duplicateId,
                ['status' => 'published'],
                ['status' => 'merged', 'redirectTo' => $survivorId],
                $reason,
                $actorUserId,
                $mergeId,
                $at,
            );
        }
        $this->audit(
            $this->ids->generate(),
            $actorUserId,
            'catalog.products.merged',
            'catalog_merge',
            $mergeId,
            ['survivorId' => $survivorId, 'duplicateCount' => count($duplicateIds)],
            $at,
        );

        return [
            'id' => $mergeId,
            'status' => 'applied',
            'revision' => 1,
            'survivorId' => $survivorId,
            'duplicateIds' => $duplicateIds,
            'affectedCounts' => $preview['affectedCounts'],
        ];
    }

    public function reverseMerge(
        string $mergeId,
        int $expectedRevision,
        string $reason,
        string $actorUserId,
        DateTimeImmutable $at,
    ): array {
        $merge = $this->one(
            'SELECT id, survivor_id AS survivorId, merged_ids_json AS mergedIdsJson,
                    status, revision
             FROM catalog_merge_events WHERE id = :id',
            ['id' => $mergeId],
        );
        if (
            $merge === null
            || (string) $merge['status'] !== 'applied'
            || (int) $merge['revision'] !== $expectedRevision
        ) {
            throw new DomainException('The merge is unavailable or changed.');
        }
        $survivorId = (string) $merge['survivorId'];
        $duplicateIds = $this->decodeList((string) $merge['mergedIdsJson']);
        $relinks = $this->connection->fetchAllAssociative(
            'SELECT duplicate_product_id AS duplicateProductId,
                    reference_type AS referenceType, reference_id AS referenceId
             FROM catalog_merge_relinks
             WHERE merge_event_id = :merge
             ORDER BY duplicate_product_id, reference_type, reference_id',
            ['merge' => $mergeId],
        );
        foreach ($relinks as $relink) {
            if (
                ! $this->referencePointsTo(
                    (string) $relink['referenceType'],
                    (string) $relink['referenceId'],
                    $survivorId,
                )
            ) {
                throw new DomainException('A merged reference changed; reversal requires manual review.');
            }
        }
        $now = $this->date($at);
        foreach ($relinks as $relink) {
            $this->restoreReference(
                (string) $relink['referenceType'],
                (string) $relink['referenceId'],
                (string) $relink['duplicateProductId'],
                $survivorId,
            );
        }
        foreach ($duplicateIds as $duplicateId) {
            if (
                $this->connection->executeStatement(
                    'UPDATE products
                     SET status = :published, revision = revision + 1, updated_at = :updated
                     WHERE id = :id AND status = :merged',
                    [
                        'published' => 'published',
                        'updated' => $now,
                        'id' => $duplicateId,
                        'merged' => 'merged',
                    ],
                ) !== 1
            ) {
                throw new DomainException('A merged product changed; reversal stopped.');
            }
            $this->connection->executeStatement(
                'UPDATE catalog_product_redirects
                 SET status = :reversed, updated_at = :updated
                 WHERE duplicate_product_id = :duplicate
                   AND merge_event_id = :merge AND status = :active',
                [
                    'reversed' => 'reversed',
                    'updated' => $now,
                    'duplicate' => $duplicateId,
                    'merge' => $mergeId,
                    'active' => 'active',
                ],
            );
            $this->recordRevision(
                $this->ids->generate(),
                'product',
                $duplicateId,
                ['status' => 'merged', 'redirectTo' => $survivorId],
                ['status' => 'published'],
                $reason,
                $actorUserId,
                $mergeId,
                $at,
            );
        }
        $this->connection->executeStatement(
            'UPDATE products SET revision = revision + 1, updated_at = :updated WHERE id = :id',
            ['updated' => $now, 'id' => $survivorId],
        );
        if (
            $this->connection->executeStatement(
                'UPDATE catalog_merge_events
                 SET status = :reversed, revision = revision + 1,
                     reversed_by_user_id = :actor, reversed_at = :reversed_at,
                     reverse_reason = :reason, updated_at = :updated
                 WHERE id = :id AND status = :applied AND revision = :revision',
                [
                    'reversed' => 'reversed',
                    'actor' => $actorUserId,
                    'reversed_at' => $now,
                    'reason' => $reason,
                    'updated' => $now,
                    'id' => $mergeId,
                    'applied' => 'applied',
                    'revision' => $expectedRevision,
                ],
            ) !== 1
        ) {
            throw new DomainException('The merge changed during reversal.');
        }
        $this->audit(
            $this->ids->generate(),
            $actorUserId,
            'catalog.products.merge-reversed',
            'catalog_merge',
            $mergeId,
            ['survivorId' => $survivorId, 'restoredProductCount' => count($duplicateIds)],
            $at,
        );

        return [
            'id' => $mergeId,
            'status' => 'reversed',
            'revision' => $expectedRevision + 1,
            'survivorId' => $survivorId,
            'restoredProductIds' => $duplicateIds,
            'restoredReferenceCount' => count($relinks),
        ];
    }

    private function moveReferences(
        string $mergeId,
        string $survivorId,
        string $duplicateId,
        DateTimeImmutable $at,
    ): void {
        foreach ($this->referenceDefinitions() as $type => $definition) {
            if ($type === 'catalog_icon') {
                $ids = $this->connection->fetchFirstColumn(
                    'SELECT id FROM catalog_icons
                     WHERE target_type = :type AND target_id = :duplicate',
                    ['type' => 'product', 'duplicate' => $duplicateId],
                );
            } else {
                $ids = $this->connection->fetchFirstColumn(
                    'SELECT id FROM ' . $definition['table'] . ' WHERE product_id = :duplicate',
                    ['duplicate' => $duplicateId],
                );
            }
            foreach ($ids as $referenceId) {
                $referenceId = (string) $referenceId;
                $this->connection->insert('catalog_merge_relinks', [
                    'merge_event_id' => $mergeId,
                    'duplicate_product_id' => $duplicateId,
                    'reference_type' => $type,
                    'reference_id' => $referenceId,
                    'created_at' => $this->date($at),
                ]);
                if ($type === 'catalog_icon') {
                    $this->connection->executeStatement(
                        'UPDATE catalog_icons SET target_id = :survivor
                         WHERE id = :id AND target_type = :type AND target_id = :duplicate',
                        [
                            'survivor' => $survivorId,
                            'id' => $referenceId,
                            'type' => 'product',
                            'duplicate' => $duplicateId,
                        ],
                    );
                } else {
                    $this->connection->executeStatement(
                        'UPDATE ' . $definition['table'] . '
                         SET product_id = :survivor
                         WHERE id = :id AND product_id = :duplicate',
                        ['survivor' => $survivorId, 'id' => $referenceId, 'duplicate' => $duplicateId],
                    );
                }
            }
        }
        foreach ($this->homeProducts->references($duplicateId) as $referenceId) {
            $this->connection->insert('catalog_merge_relinks', [
                'merge_event_id' => $mergeId,
                'duplicate_product_id' => $duplicateId,
                'reference_type' => 'home_product',
                'reference_id' => $referenceId,
                'created_at' => $this->date($at),
            ]);
            if (! $this->homeProducts->relink($referenceId, $duplicateId, $survivorId)) {
                throw new DomainException('A home product reference changed during the merge.');
            }
        }
    }

    private function referencePointsTo(string $type, string $referenceId, string $survivorId): bool
    {
        if ($type === 'home_product') {
            return $this->homeProducts->pointsTo($referenceId, $survivorId);
        }
        $definitions = $this->referenceDefinitions();
        if (! isset($definitions[$type])) {
            return false;
        }
        if ($type === 'catalog_icon') {
            $count = $this->connection->fetchOne(
                'SELECT COUNT(*) FROM catalog_icons
                 WHERE id = :id AND target_type = :type AND target_id = :survivor',
                ['id' => $referenceId, 'type' => 'product', 'survivor' => $survivorId],
            );
        } else {
            $count = $this->connection->fetchOne(
                'SELECT COUNT(*) FROM ' . $definitions[$type]['table'] . '
                 WHERE id = :id AND product_id = :survivor',
                ['id' => $referenceId, 'survivor' => $survivorId],
            );
        }

        return (int) $count === 1;
    }

    private function restoreReference(
        string $type,
        string $referenceId,
        string $duplicateId,
        string $survivorId,
    ): void {
        if ($type === 'home_product') {
            if (! $this->homeProducts->relink($referenceId, $survivorId, $duplicateId)) {
                throw new DomainException('A merge reference could not be restored.');
            }

            return;
        }
        $definitions = $this->referenceDefinitions();
        if (! isset($definitions[$type])) {
            throw new DomainException('A merge reference type is invalid.');
        }
        if ($type === 'catalog_icon') {
            $updated = $this->connection->executeStatement(
                'UPDATE catalog_icons SET target_id = :duplicate
                 WHERE id = :id AND target_type = :type AND target_id = :survivor',
                [
                    'duplicate' => $duplicateId,
                    'id' => $referenceId,
                    'type' => 'product',
                    'survivor' => $survivorId,
                ],
            );
        } else {
            $updated = $this->connection->executeStatement(
                'UPDATE ' . $definitions[$type]['table'] . '
                 SET product_id = :duplicate
                 WHERE id = :id AND product_id = :survivor',
                ['duplicate' => $duplicateId, 'id' => $referenceId, 'survivor' => $survivorId],
            );
        }
        if ($updated !== 1) {
            throw new DomainException('A merge reference could not be restored.');
        }
    }

    /**
     * @return array<string, array{table: string}> */
    private function referenceDefinitions(): array
    {
        return [
            'variant' => ['table' => 'product_variants'],
            'pack' => ['table' => 'product_packs'],
            'alias' => ['table' => 'product_aliases'],
            'catalog_icon' => ['table' => 'catalog_icons'],
        ];
    }

    private function variantCollision(string $survivorId, string $duplicateId): bool
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*)
             FROM product_variants duplicate_variant
             INNER JOIN product_variants survivor_variant
               ON survivor_variant.product_id = :survivor
              AND survivor_variant.normalized_label = duplicate_variant.normalized_label
              AND survivor_variant.status <> :archived
             WHERE duplicate_variant.product_id = :duplicate
               AND duplicate_variant.status <> :archived',
            ['survivor' => $survivorId, 'duplicate' => $duplicateId, 'archived' => 'archived'],
        ) > 0;
    }

    private function aliasCollision(string $survivorId, string $duplicateId): bool
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*)
             FROM product_aliases duplicate_alias
             INNER JOIN product_aliases survivor_alias
               ON survivor_alias.product_id = :survivor
              AND survivor_alias.normalized_alias = duplicate_alias.normalized_alias
              AND survivor_alias.scope = :scope
              AND survivor_alias.status = :approved
             WHERE duplicate_alias.product_id = :duplicate
               AND duplicate_alias.scope = :scope
               AND duplicate_alias.status = :approved',
            [
                'survivor' => $survivorId,
                'duplicate' => $duplicateId,
                'scope' => 'global',
                'approved' => 'approved',
            ],
        ) > 0;
    }

    private function packCollision(string $survivorId, string $duplicateId): bool
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*)
             FROM product_packs duplicate_pack
             INNER JOIN product_packs survivor_pack
               ON survivor_pack.product_id = :survivor
              AND LOWER(survivor_pack.original_pack_text) = LOWER(duplicate_pack.original_pack_text)
              AND survivor_pack.status <> :archived
             WHERE duplicate_pack.product_id = :duplicate
               AND duplicate_pack.status <> :archived',
            ['survivor' => $survivorId, 'duplicate' => $duplicateId, 'archived' => 'archived'],
        ) > 0;
    }

    private function activeIncomingRedirects(string $productId): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM catalog_product_redirects
             WHERE survivor_product_id = :product AND status = :status',
            ['product' => $productId, 'status' => 'active'],
        );
    }

    /**
     * @return array<string, mixed>|null */
    private function productIdentity(string $id): ?array
    {
        return $this->one(
            'SELECT id, canonical_name AS canonicalName, brand, status, revision
             FROM products WHERE id = :id',
            ['id' => $id],
        );
    }

    private function assertOptionalChild(string $table, mixed $id, string $productId): void
    {
        if ($id === null || $id === '') {
            return;
        }
        $count = $this->connection->fetchOne(
            'SELECT COUNT(*) FROM ' . $table . '
             WHERE id = :id AND product_id = :product AND status <> :archived',
            ['id' => $id, 'product' => $productId, 'archived' => 'archived'],
        );
        if ((int) $count !== 1) {
            throw new DomainException('The proposed catalog child does not belong to its product.');
        }
    }

    private function exists(string $table, string $id, string $status): bool
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM ' . $table . ' WHERE id = :id AND status = :status',
            ['id' => $id, 'status' => $status],
        ) === 1;
    }

    private function count(string $table, string $field, string $id): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM ' . $table . ' WHERE ' . $field . ' = :id',
            ['id' => $id],
        );
    }

    /**
     *
     * @param array<string, mixed>|null $before
     *
     * @param array<string, mixed> $after
     */
    private function recordRevision(
        string $id,
        string $entityType,
        string $entityId,
        ?array $before,
        array $after,
        string $reason,
        string $actorUserId,
        string $operationId,
        DateTimeImmutable $at,
    ): void {
        $this->connection->insert('catalog_revisions', [
            'id' => $id,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'entity_key' => mb_substr($entityType . '|' . $entityId, 0, 191),
            'before_json' => $this->json($before),
            'after_json' => $this->json($after),
            'reason' => $reason,
            'actor_user_id' => $actorUserId,
            'operation_id' => $operationId,
            'created_at' => $this->date($at),
        ]);
        $this->audit(
            $this->ids->generate(),
            $actorUserId,
            'catalog.' . $entityType . '.revised',
            $entityType,
            $entityId,
            ['operationId' => $operationId, 'reason' => $reason],
            $at,
        );
    }

    /**
     * @param array<string, mixed> $details */
    private function audit(
        string $id,
        string $actorUserId,
        string $action,
        string $targetType,
        string $targetId,
        array $details,
        DateTimeImmutable $at,
    ): void {
        $this->connection->insert('audit_events', [
            'id' => $id,
            'home_id' => null,
            'actor_user_id' => $actorUserId,
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'details' => $this->json($details),
            'occurred_at' => $this->date($at),
        ]);
    }

    /**
     *
     * @param array<string, mixed> $parameters
     *
     * @return array<string, mixed>|null
     */
    private function one(string $sql, array $parameters): ?array
    {
        $row = $this->connection->fetchAssociative($sql, $parameters);

        return $row === false ? null : $row;
    }

    /**
     * @return array<string, mixed> */
    private function decode(string $value): array
    {
        $decoded = json_decode($value, true, 64, JSON_THROW_ON_ERROR);

        return is_array($decoded) && ! array_is_list($decoded) ? $decoded : [];
    }

    /**
     * @return list<string> */
    private function decodeList(string $value): array
    {
        $decoded = json_decode($value, true, 32, JSON_THROW_ON_ERROR);
        if (! is_array($decoded) || ! array_is_list($decoded)) {
            return [];
        }
        $result = [];
        foreach ($decoded as $item) {
            if (is_string($item)) {
                $result[] = $item;
            }
        }

        return $result;
    }

    private function json(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    private function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value) ?? $value;

        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }

    private function date(DateTimeImmutable $date): string
    {
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
