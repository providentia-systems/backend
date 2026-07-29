<?php

declare(strict_types=1);

namespace Providentia\Catalog\Application;

use JsonException;
use Providentia\SharedKernel\Application\Clock;
use Providentia\SharedKernel\Application\TransactionManager;
use Providentia\SharedKernel\Http\HttpProblem;

final class CatalogSeedService
{
    public function __construct(
        private readonly CatalogStore $catalog,
        private readonly Clock $clock,
        private readonly TransactionManager $transactions,
    ) {
    }

    /** @return array<string, int> */
    public function validateAndImport(string $dataPath, string $rulesPath, bool $dryRun): array
    {
        $expectedDigests = [
            $dataPath => 'ac2a74f267d7a48a460c8fae24515887f97632cddfb4a17f5f45dd07c9e90116',
            $rulesPath => '8131bd3bf41c9b70f0e4cfe86c9e7de699ca0df827c6287fc9f2927e35827899',
        ];
        foreach ($expectedDigests as $path => $expectedDigest) {
            $actualDigest = is_file($path) ? hash_file('sha256', $path) : false;
            if (! is_string($actualDigest) || ! hash_equals($expectedDigest, $actualDigest)) {
                throw new HttpProblem(
                    422,
                    'Seed integrity failed',
                    'An authoritative catalog source failed SHA-256 verification.',
                );
            }
        }
        $data = $this->decode($dataPath);
        $rules = $this->decode($rulesPath);
        $items = $data['itemMaster'] ?? null;
        $aliases = $rules['aliases'] ?? null;
        $identityRules = $rules['identityRules'] ?? null;
        $unresolved = $rules['unresolvedCurrentStock'] ?? null;
        if (! is_array($items) || ! is_array($aliases) || ! is_array($identityRules) || ! is_array($unresolved)) {
            throw new HttpProblem(422, 'Invalid seed', 'The authoritative catalog exports have an invalid shape.');
        }
        if (
            ! array_is_list($items)
            || ! array_is_list($identityRules)
            || ! array_is_list($unresolved)
        ) {
            throw new HttpProblem(422, 'Invalid seed', 'The authoritative seed collections must be ordered lists.');
        }
        $productNames = [];
        $tuples = [];
        $categories = [];
        $pendingPacks = 0;
        foreach ($items as $row) {
            if (! is_array($row)) {
                throw new HttpProblem(422, 'Invalid seed', 'Every item-master row must be an object.');
            }
            $product = trim((string) ($row['product'] ?? ''));
            $category = trim((string) ($row['category'] ?? ''));
            $brand = trim((string) ($row['brand'] ?? ''));
            $pack = trim((string) ($row['packSize'] ?? ''));
            if ($product === '' || $category === '') {
                throw new HttpProblem(422, 'Invalid seed', 'Each seed row requires product and category.');
            }
            $productNames[$product] = true;
            $categories[$category] = true;
            $tuples[implode("\0", [$product, $brand, $pack, trim((string) ($row['unit'] ?? ''))])] = true;
            if (mb_strtolower($pack) === 'pack size pending') {
                $pendingPacks++;
            }
        }
        foreach ($aliases as $canonical => $values) {
            if (
                ! is_string($canonical)
                || trim($canonical) === ''
                || ! is_array($values)
                || ! array_is_list($values)
                || array_filter($values, 'is_string') !== $values
            ) {
                throw new HttpProblem(422, 'Invalid seed', 'Every alias group must contain ordered strings.');
            }
        }
        foreach ($identityRules as $rule) {
            if (! is_array($rule)) {
                throw new HttpProblem(422, 'Invalid seed', 'Every identity rule must be an object.');
            }
        }
        foreach ($unresolved as $description) {
            if (! is_string($description) || trim($description) === '') {
                throw new HttpProblem(422, 'Invalid seed', 'Every unresolved description must be a string.');
            }
        }
        /** @var list<array<string, mixed>> $items */
        /** @var array<string, list<string>> $aliases */
        /** @var list<array<string, mixed>> $identityRules */
        /** @var list<string> $unresolved */
        $aliasCount = array_sum(array_map(
            static fn (mixed $value): int => is_array($value) ? count($value) : 0,
            $aliases,
        ));
        $report = [
            'itemRows' => count($items),
            'distinctProductNames' => count($productNames),
            'distinctItemTuples' => count($tuples),
            'categoryLabels' => count($categories),
            'aliasGroups' => count($aliases),
            'aliases' => $aliasCount,
            'identityRules' => count($identityRules),
            'unresolved' => count($unresolved),
            'packSizePending' => $pendingPacks,
        ];
        $expected = [
            'itemRows' => 292,
            'distinctProductNames' => 263,
            'distinctItemTuples' => 292,
            'categoryLabels' => 22,
            'aliasGroups' => 13,
            'aliases' => 19,
            'identityRules' => 19,
            'unresolved' => 8,
            'packSizePending' => 9,
        ];
        if ($report !== $expected) {
            throw new HttpProblem(
                422,
                'Seed reconciliation failed',
                'Catalog seed counts differ from the authoritative Phase 0 gates: '
                    . json_encode(['expected' => $expected, 'actual' => $report], JSON_THROW_ON_ERROR),
            );
        }
        if ($dryRun) {
            return $report;
        }

        $safeItems = array_map(static fn (array $row): array => [
            'sourceId' => (string) ($row['id'] ?? ''),
            'category' => trim((string) $row['category']),
            'product' => trim((string) $row['product']),
            'packSize' => trim((string) ($row['packSize'] ?? '')),
            'unit' => trim((string) ($row['unit'] ?? '')),
            'brand' => trim((string) ($row['brand'] ?? '')),
        ], $items);
        $seed = [
            'items' => $safeItems,
            'aliases' => $aliases,
            'identityRules' => $identityRules,
            'unresolved' => $unresolved,
            'reconciliation' => $report,
            'sourceDigests' => [
                'pantryData' => $expectedDigests[$dataPath],
                'productRules' => $expectedDigests[$rulesPath],
            ],
        ];
        $imported = $this->transactions->transactional(
            fn (): array => $this->catalog->importSeed($seed, $this->clock->now()),
        );

        return array_merge($report, $imported);
    }

    /** @return array<string, mixed> */
    private function decode(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new HttpProblem(422, 'Invalid seed', 'Seed source is not readable: ' . $path);
        }
        try {
            $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new HttpProblem(422, 'Invalid seed', 'Seed JSON is invalid: ' . $error->getMessage());
        }
        if (! is_array($decoded)) {
            throw new HttpProblem(422, 'Invalid seed', 'Seed root must be an object.');
        }

        return $decoded;
    }
}
