<?php

declare(strict_types=1);

namespace Providentia\Administration\Application;

use JsonException;
use Providentia\SharedKernel\Application\Clock;
use Providentia\SharedKernel\Application\Problem;
use Providentia\SharedKernel\Application\TransactionManager;

final class BaselineImportService
{
    private const DATA_DIGEST = 'ac2a74f267d7a48a460c8fae24515887f97632cddfb4a17f5f45dd07c9e90116';
    private const RULES_DIGEST = '8131bd3bf41c9b70f0e4cfe86c9e7de699ca0df827c6287fc9f2927e35827899';
    private const SOURCE_COMMIT = 'b01b5ef14783b4ad1c1bfc0be7ba0dba32629af8';

    public function __construct(
        private readonly BaselineImportStore $imports,
        private readonly Clock $clock,
        private readonly TransactionManager $transactions,
    ) {
    }

    /** @return array<string, int|string|bool> */
    public function validateAndImport(
        string $dataPath,
        string $rulesPath,
        string $homeId,
        string $actorUserId,
        bool $dryRun,
    ): array {
        $dataDigest = $this->verify($dataPath, self::DATA_DIGEST);
        $rulesDigest = $this->verify($rulesPath, self::RULES_DIGEST);
        $data = $this->decode($dataPath);
        $rules = $this->decode($rulesPath);
        $itemMaster = $this->list($data, 'itemMaster');
        $stock = $this->list($data, 'currentStock');
        $purchases = $this->list($data, 'purchases');
        $history = $this->list($data, 'history');
        $monthly = $this->list($data, 'monthlyPurchases');
        $aliases = $rules['aliases'] ?? null;
        $identityRules = $this->list($rules, 'identityRules');
        $unresolved = $this->list($rules, 'unresolvedCurrentStock');
        if (! is_array($aliases)) {
            throw new Problem(422, 'Invalid baseline', 'Product aliases must be an object.');
        }
        $aliasCount = 0;
        foreach ($aliases as $values) {
            if (! is_array($values) || ! array_is_list($values)) {
                throw new Problem(422, 'Invalid baseline', 'Every alias group must be an ordered list.');
            }
            $aliasCount += count($values);
        }
        $stockQuantity = 0;
        foreach ($stock as $row) {
            if (! is_array($row)) {
                throw new Problem(422, 'Invalid baseline', 'Every current-stock row must be an object.');
            }
            $quantity = $row['quantity'] ?? null;
            if (! is_int($quantity) && ! is_float($quantity)) {
                throw new Problem(422, 'Invalid baseline', 'Every current-stock quantity must be numeric.');
            }
            $stockQuantity += (int) $quantity;
        }
        $recentSpend = 0.0;
        foreach ($purchases as $row) {
            if (! is_array($row) || ! is_numeric($row['totalCost'] ?? null)) {
                throw new Problem(422, 'Invalid baseline', 'Every recent purchase requires evidenced total cost.');
            }
            $recentSpend += (float) $row['totalCost'];
        }
        $reconciliation = [
            'itemMasterRows' => count($itemMaster),
            'openingStockLines' => count($stock),
            'openingStockQuantity' => $stockQuantity,
            'recentPurchaseLines' => count($purchases),
            'recentPurchaseSpend' => number_format($recentSpend, 2, '.', ''),
            'historicalPurchaseLines' => count($history),
            'monthlyValidationRows' => count($monthly),
            'aliasGroups' => count($aliases),
            'aliases' => $aliasCount,
            'identityRules' => count($identityRules),
            'unresolvedDescriptions' => count($unresolved),
            'sourceCommit' => self::SOURCE_COMMIT,
        ];
        $expected = [
            'itemMasterRows' => 292,
            'openingStockLines' => 60,
            'openingStockQuantity' => 159,
            'recentPurchaseLines' => 16,
            'recentPurchaseSpend' => '1078.38',
            'historicalPurchaseLines' => 452,
            'monthlyValidationRows' => 261,
            'aliasGroups' => 13,
            'aliases' => 19,
            'identityRules' => 19,
            'unresolvedDescriptions' => 8,
            'sourceCommit' => self::SOURCE_COMMIT,
        ];
        if ($reconciliation !== $expected) {
            throw new Problem(
                422,
                'Baseline reconciliation failed',
                json_encode(['expected' => $expected, 'actual' => $reconciliation], JSON_THROW_ON_ERROR),
            );
        }
        if ($dryRun) {
            return array_merge($reconciliation, ['dryRun' => true]);
        }
        if ($homeId === '' || $actorUserId === '') {
            throw new Problem(422, 'Invalid import target', 'Home and actor user IDs are required.');
        }

        return $this->transactions->transactional(fn (): array => $this->imports->import(
            $homeId,
            $actorUserId,
            $dataDigest,
            $rulesDigest,
            $data,
            $rules,
            $reconciliation,
            $this->clock->now(),
        ));
    }

    private function verify(string $path, string $expected): string
    {
        $digest = is_file($path) && is_readable($path) ? hash_file('sha256', $path) : false;
        if (! is_string($digest) || ! hash_equals($expected, $digest)) {
            throw new Problem(422, 'Baseline integrity failed', 'An authoritative export failed SHA-256 verification.');
        }

        return $digest;
    }

    /** @return array<string, mixed> */
    private function decode(string $path): array
    {
        try {
            $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new Problem(422, 'Invalid baseline', 'Baseline JSON is invalid: ' . $error->getMessage());
        }
        if (! is_array($decoded)) {
            throw new Problem(422, 'Invalid baseline', 'Baseline root must be an object.');
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $document
     * @return list<mixed>
     */
    private function list(array $document, string $key): array
    {
        $value = $document[$key] ?? null;
        if (! is_array($value) || ! array_is_list($value)) {
            throw new Problem(422, 'Invalid baseline', $key . ' must be an ordered list.');
        }

        return $value;
    }
}
