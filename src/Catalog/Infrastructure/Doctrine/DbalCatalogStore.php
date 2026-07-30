<?php

declare(strict_types=1);

namespace Providentia\Catalog\Infrastructure\Doctrine;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Providentia\Catalog\Application\CatalogStore;
use Providentia\SharedKernel\Application\UuidGenerator;

final class DbalCatalogStore implements CatalogStore
{
    public function __construct(
        private readonly Connection $connection,
        private readonly UuidGenerator $ids,
    ) {
    }

    public function search(string $query, int $limit, int $offset): array
    {
        $pattern = '%' . mb_strtolower($query) . '%';

        return $this->connection->fetchAllAssociative(
            'SELECT p.id, p.canonical_name AS canonicalName, p.brand, p.revision,
                    c.canonical_name AS category,
                    pk.id AS packId, pk.original_pack_text AS packText,
                    pk.status AS packStatus
             FROM products p
             INNER JOIN categories c ON c.id = p.category_id
             LEFT JOIN product_packs pk ON pk.product_id = p.id AND pk.status <> :archived
             WHERE p.status = :published
               AND (:empty_query = :empty OR p.normalized_name LIKE :pattern
                    OR p.normalized_brand LIKE :pattern
                    OR EXISTS (
                        SELECT 1 FROM product_aliases a
                        WHERE a.product_id = p.id AND a.scope = :global_scope
                          AND a.status = :approved AND a.normalized_alias LIKE :pattern
                    ))
             ORDER BY p.canonical_name, p.brand, pk.original_pack_text
             LIMIT ' . $limit . ' OFFSET ' . $offset,
            [
                'archived' => 'archived',
                'published' => 'published',
                'empty_query' => $query,
                'empty' => '',
                'pattern' => $pattern,
                'global_scope' => 'global',
                'approved' => 'approved',
            ],
        );
    }

    public function product(string $requestedId): ?array
    {
        $redirect = $this->connection->fetchAssociative(
            'SELECT survivor_product_id AS survivorId
             FROM catalog_product_redirects
             WHERE duplicate_product_id = :id AND status = :status',
            ['id' => $requestedId, 'status' => 'active'],
        );
        $resolvedId = $redirect === false ? $requestedId : (string) $redirect['survivorId'];
        $product = $this->connection->fetchAssociative(
            'SELECT p.id, p.canonical_name AS canonicalName, p.brand, p.revision,
                    c.id AS categoryId, c.canonical_name AS category
             FROM products p
             INNER JOIN categories c ON c.id = p.category_id
             WHERE p.id = :id AND p.status = :status',
            ['id' => $resolvedId, 'status' => 'published'],
        );
        if ($product === false) {
            return null;
        }
        $product['requestedId'] = $requestedId;
        $product['redirected'] = $resolvedId !== $requestedId;
        $product['packs'] = $this->connection->fetchAllAssociative(
            'SELECT id, original_pack_text AS packText, amount,
                    normalized_base_amount AS normalizedBaseAmount,
                    multiplicity, revision
             FROM product_packs
             WHERE product_id = :product AND status = :status
             ORDER BY original_pack_text, id',
            ['product' => $resolvedId, 'status' => 'published'],
        );
        $product['icons'] = $this->connection->fetchAllAssociative(
            'SELECT id, asset_digest AS assetDigest, media_type AS mediaType,
                    alt_text AS altText, width, height, byte_size AS byteSize, revision
             FROM catalog_icons
             WHERE target_type = :type AND target_id = :target AND status = :status
             ORDER BY created_at DESC',
            ['type' => 'product', 'target' => $resolvedId, 'status' => 'active'],
        );

        return $product;
    }

    /**
     * @param array<string, mixed> $seed
     * @return array<string, int>
     */
    public function importSeed(array $seed, DateTimeImmutable $at): array
    {
        $now = $this->date($at);
        /** @var list<array<string, string>> $items */
        $items = $seed['items'];
        $categoryIds = [];
        $productIdsByName = [];
        $unitIds = $this->seedUnits($now);
        $products = 0;
        $packs = 0;

        foreach ($items as $row) {
            $categoryKey = $this->normalize($row['category']);
            if (! isset($categoryIds[$categoryKey])) {
                $category = $this->connection->fetchAssociative(
                    'SELECT id FROM categories WHERE normalized_name = :name',
                    ['name' => $categoryKey],
                );
                $categoryId = $category === false ? $this->ids->generate() : (string) $category['id'];
                if ($category === false) {
                    $this->connection->insert('categories', [
                        'id' => $categoryId,
                        'parent_id' => null,
                        'canonical_name' => $row['category'],
                        'normalized_name' => $categoryKey,
                        'status' => 'published',
                        'revision' => 1,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
                $categoryIds[$categoryKey] = $categoryId;
            }

            $productKey = implode('|', [
                $this->normalize($row['product']),
                $this->normalize($row['brand']),
                $categoryIds[$categoryKey],
            ]);
            if (! isset($productIdsByName[$productKey])) {
                $product = $this->connection->fetchAssociative(
                    'SELECT id FROM products
                     WHERE normalized_name = :name AND normalized_brand = :brand
                       AND category_id = :category',
                    [
                        'name' => $this->normalize($row['product']),
                        'brand' => $this->normalize($row['brand']),
                        'category' => $categoryIds[$categoryKey],
                    ],
                );
                $productId = $product === false ? $this->ids->generate() : (string) $product['id'];
                if ($product === false) {
                    $this->connection->insert('products', [
                        'id' => $productId,
                        'category_id' => $categoryIds[$categoryKey],
                        'canonical_name' => $row['product'],
                        'normalized_name' => $this->normalize($row['product']),
                        'brand' => $row['brand'],
                        'normalized_brand' => $this->normalize($row['brand']),
                        'status' => 'published',
                        'revision' => 1,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                    $products++;
                }
                $productIdsByName[$productKey] = $productId;
                $productIdsByName[$this->normalize($row['product'])] ??= $productId;
            }
            $productId = $productIdsByName[$productKey];
            $existingPack = $this->connection->fetchOne(
                'SELECT id FROM product_packs WHERE source_key = :source',
                ['source' => $row['sourceId']],
            );
            if ($existingPack === false) {
                [$unitId, $amount, $baseAmount, $multiplicity] = $this->parsePack(
                    $row['packSize'],
                    $unitIds,
                );
                $this->connection->insert('product_packs', [
                    'id' => $this->ids->generate(),
                    'product_id' => $productId,
                    'variant_id' => null,
                    'unit_id' => $unitId,
                    'source_key' => $row['sourceId'],
                    'original_pack_text' => $row['packSize'],
                    'amount' => $amount,
                    'normalized_base_amount' => $baseAmount,
                    'multiplicity' => $multiplicity,
                    'status' => mb_strtolower($row['packSize']) === 'pack size pending'
                        ? 'pending-normalization'
                        : 'published',
                    'revision' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $packs++;
            }
        }

        $aliases = 0;
        /** @var array<string, list<string>> $aliasGroups */
        $aliasGroups = $seed['aliases'];
        foreach ($aliasGroups as $canonical => $values) {
            $productId = $productIdsByName[$this->normalize($canonical)] ?? null;
            if ($productId === null) {
                throw new \RuntimeException('Alias group cannot be mapped safely: ' . $canonical);
            }
            foreach ($values as $alias) {
                $normalized = $this->normalize($alias);
                $exists = (int) $this->connection->fetchOne(
                    'SELECT COUNT(*) FROM product_aliases
                     WHERE scope = :scope AND normalized_alias = :alias',
                    ['scope' => 'global', 'alias' => $normalized],
                );
                if ($exists > 0) {
                    continue;
                }
                $this->connection->insert('product_aliases', [
                    'id' => $this->ids->generate(),
                    'scope' => 'global',
                    'home_id' => null,
                    'product_id' => $productId,
                    'variant_id' => null,
                    'pack_id' => null,
                    'raw_alias' => $alias,
                    'normalized_alias' => $normalized,
                    'approval_source' => 'providentia-v1-authoritative-seed',
                    'status' => 'approved',
                    'revision' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $aliases++;
            }
        }

        $rules = 0;
        /** @var list<array<string, mixed>> $identityRules */
        $identityRules = $seed['identityRules'];
        foreach ($identityRules as $index => $rule) {
            $ruleKey = 'providentia-v1-' . str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT);
            $exists = (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM product_identity_rules WHERE rule_key = :key',
                ['key' => $ruleKey],
            );
            if ($exists > 0) {
                continue;
            }
            $this->connection->insert('product_identity_rules', [
                'id' => $this->ids->generate(),
                'rule_key' => $ruleKey,
                'family' => (string) ($rule['family'] ?? ''),
                'rule_definition' => json_encode($rule, JSON_THROW_ON_ERROR),
                'provenance' => 'product-rules.json',
                'status' => 'approved',
                'revision' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $rules++;
        }

        $quarantined = 0;
        /** @var list<string> $unresolved */
        $unresolved = $seed['unresolved'];
        foreach ($unresolved as $description) {
            $exists = (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM catalog_seed_quarantine
                 WHERE raw_description = :description AND reason = :reason',
                ['description' => $description, 'reason' => 'unresolved-source-identity'],
            );
            if ($exists > 0) {
                continue;
            }
            $this->connection->insert('catalog_seed_quarantine', [
                'id' => $this->ids->generate(),
                'raw_description' => $description,
                'reason' => 'unresolved-source-identity',
                'resolution_status' => 'unresolved',
                'created_at' => $now,
            ]);
            $quarantined++;
        }

        /** @var array{pantryData: string, productRules: string} $sourceDigests */
        $sourceDigests = $seed['sourceDigests'];
        $existingRun = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM catalog_seed_runs
             WHERE seed_version = :version AND pantry_data_sha256 = :data_digest
               AND product_rules_sha256 = :rules_digest',
            [
                'version' => 'providentia-v1',
                'data_digest' => $sourceDigests['pantryData'],
                'rules_digest' => $sourceDigests['productRules'],
            ],
        );
        $seedRuns = 0;
        if ($existingRun === 0) {
            $this->connection->insert('catalog_seed_runs', [
                'id' => $this->ids->generate(),
                'seed_version' => 'providentia-v1',
                'pantry_data_sha256' => $sourceDigests['pantryData'],
                'product_rules_sha256' => $sourceDigests['productRules'],
                'reconciliation' => json_encode($seed['reconciliation'], JSON_THROW_ON_ERROR),
                'completed_at' => $now,
            ]);
            $seedRuns++;
        }

        $mappedRows = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM product_packs WHERE source_key <> ''",
        );
        $approvedAliases = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM product_aliases
             WHERE scope = 'global' AND approval_source = 'providentia-v1-authoritative-seed'",
        );
        $approvedRules = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM product_identity_rules WHERE provenance = 'product-rules.json'",
        );
        $unresolvedRows = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM catalog_seed_quarantine
             WHERE reason = 'unresolved-source-identity'",
        );
        if (
            $mappedRows !== 292
            || $approvedAliases !== 19
            || $approvedRules !== 19
            || $unresolvedRows !== 8
        ) {
            throw new \RuntimeException('Post-import catalog reconciliation failed.');
        }

        return [
            'productsInserted' => $products,
            'packsInserted' => $packs,
            'aliasesInserted' => $aliases,
            'rulesInserted' => $rules,
            'quarantineInserted' => $quarantined,
            'seedRunsInserted' => $seedRuns,
            'mappedSourceRows' => $mappedRows,
            'approvedAliases' => $approvedAliases,
            'approvedRules' => $approvedRules,
            'unresolvedRows' => $unresolvedRows,
        ];
    }

    /** @return array<string, array{id: string, factor: float}> */
    private function seedUnits(string $now): array
    {
        $definitions = [
            'g' => ['name' => 'gram', 'dimension' => 'mass', 'factor' => 1.0],
            'kg' => ['name' => 'kilogram', 'dimension' => 'mass', 'factor' => 1000.0],
            'ml' => ['name' => 'millilitre', 'dimension' => 'volume', 'factor' => 1.0],
            'l' => ['name' => 'litre', 'dimension' => 'volume', 'factor' => 1000.0],
            'each' => ['name' => 'each', 'dimension' => 'count', 'factor' => 1.0],
            'pack' => ['name' => 'pack', 'dimension' => 'count', 'factor' => 1.0],
        ];
        $result = [];
        foreach ($definitions as $symbol => $definition) {
            $row = $this->connection->fetchAssociative(
                'SELECT id, base_factor FROM units WHERE symbol = :symbol AND dimension = :dimension',
                ['symbol' => $symbol, 'dimension' => $definition['dimension']],
            );
            $id = $row === false ? $this->ids->generate() : (string) $row['id'];
            if ($row === false) {
                $this->connection->insert('units', [
                    'id' => $id,
                    'symbol' => $symbol,
                    'name' => $definition['name'],
                    'dimension' => $definition['dimension'],
                    'base_factor' => (string) $definition['factor'],
                    'status' => 'published',
                    'revision' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
            $result[$symbol] = ['id' => $id, 'factor' => $definition['factor']];
        }

        return $result;
    }

    /**
     * @param array<string, array{id: string, factor: float}> $unitIds
     * @return array{0: string|null, 1: string|null, 2: string|null, 3: int}
     */
    private function parsePack(string $packText, array $unitIds): array
    {
        if (mb_strtolower(trim($packText)) === 'pack size pending') {
            return [null, null, null, 1];
        }
        $normalized = mb_strtolower(trim($packText));
        $symbol = match (true) {
            preg_match('/\bkg\b/u', $normalized) === 1 => 'kg',
            preg_match('/\b(?:g|gram|grams)\b/u', $normalized) === 1 => 'g',
            preg_match('/\bml\b/u', $normalized) === 1 => 'ml',
            preg_match('/\b(?:l|litre|litres)\b/u', $normalized) === 1 => 'l',
            preg_match('/\b(?:each|ea)\b/u', $normalized) === 1 => 'each',
            default => 'pack',
        };
        preg_match_all('/\d+(?:[.,]\d+)?/', $normalized, $matches);
        $numbers = array_map(
            static fn (string $number): float => (float) str_replace(',', '.', $number),
            $matches[0],
        );
        $multiplicity = str_contains($normalized, 'x') && count($numbers) >= 2
            ? max(1, (int) $numbers[0])
            : 1;
        $amount = $numbers === [] ? 1.0 : (float) $numbers[array_key_last($numbers)];
        $base = $amount * $multiplicity * $unitIds[$symbol]['factor'];

        return [
            $unitIds[$symbol]['id'],
            (string) $amount,
            (string) $base,
            $multiplicity,
        ];
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
