<?php

declare(strict_types=1);

namespace Providentia\AiIntegration\Application\Orchestration;

final class ExtractionReconciler
{
    /**
     * @param array<string, mixed> $primary
     * @param array<string, mixed> $validation
     * @return list<array<string, mixed>>
     */
    public function discrepancies(array $primary, array $validation): array
    {
        $differences = [];
        foreach (['merchant', 'receiptNumber', 'purchaseDate', 'currency', 'totalAmount', 'taxAmount'] as $field) {
            if (($primary[$field] ?? null) !== ($validation[$field] ?? null)) {
                $differences[] = [
                    'type' => 'field',
                    'field' => $field,
                    'primary' => $primary[$field] ?? null,
                    'validation' => $validation[$field] ?? null,
                ];
            }
        }

        $primaryCandidates = $this->candidates($primary);
        $validationCandidates = $this->candidates($validation);
        foreach (array_unique(array_merge(array_keys($primaryCandidates), array_keys($validationCandidates))) as $key) {
            $left = $primaryCandidates[$key] ?? null;
            $right = $validationCandidates[$key] ?? null;
            if ($left === null || $right === null) {
                $differences[] = [
                    'type' => $left === null ? 'missing-primary' : 'missing-validation',
                    'candidateKey' => $key,
                ];
                continue;
            }
            foreach (
                ['quantity', 'quantityMinimum', 'quantityMaximum', 'packText', 'unitPrice', 'lineTotal'] as $field
            ) {
                if (($left[$field] ?? null) !== ($right[$field] ?? null)) {
                    $differences[] = [
                        'type' => 'candidate-field',
                        'candidateKey' => $key,
                        'field' => $field,
                        'primary' => $left[$field] ?? null,
                        'validation' => $right[$field] ?? null,
                    ];
                }
            }
        }

        return $differences;
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, array<string, mixed>>
     */
    private function candidates(array $result): array
    {
        $indexed = [];
        $candidates = is_array($result['candidates'] ?? null) ? $result['candidates'] : [];
        foreach ($candidates as $position => $candidate) {
            if (! is_array($candidate)) {
                continue;
            }
            $description = mb_strtolower(trim((string) ($candidate['description'] ?? '')));
            $raw = mb_strtolower(trim((string) ($candidate['rawText'] ?? '')));
            $base = $description !== '' ? $description : $raw;
            $key = hash('sha256', $base === '' ? 'position:' . $position : $base);
            if (isset($indexed[$key])) {
                $key .= ':' . $position;
            }
            $indexed[$key] = $candidate;
        }

        return $indexed;
    }
}
