<?php

declare(strict_types=1);

namespace Providentia\AiIntegration\Application;

final class ExtractionSchema
{
    public const VERSION = 2;

    /** @return array<string, mixed> */
    public function jsonSchema(): array
    {
        $nullableString = ['anyOf' => [['type' => 'string'], ['type' => 'null']]];
        $nullableNumber = ['anyOf' => [['type' => 'number'], ['type' => 'null']]];
        $nullableDecimal = ['anyOf' => [[
            'type' => 'string',
            'pattern' => '^(?:0|[1-9][0-9]{0,11})(?:\\.[0-9]{1,8})?$',
        ], ['type' => 'null']]];
        $warnings = [
            'type' => 'array',
            'items' => ['type' => 'string'],
        ];
        $fieldConfidence = [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['description', 'quantity', 'packText', 'unitPrice', 'lineTotal'],
            'properties' => [
                'description' => $nullableNumber,
                'quantity' => $nullableNumber,
                'packText' => $nullableNumber,
                'unitPrice' => $nullableNumber,
                'lineTotal' => $nullableNumber,
            ],
        ];
        $boundingRegion = [
            'anyOf' => [[
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['x', 'y', 'width', 'height'],
                'properties' => [
                    'x' => ['type' => 'number'],
                    'y' => ['type' => 'number'],
                    'width' => ['type' => 'number'],
                    'height' => ['type' => 'number'],
                ],
            ], ['type' => 'null']],
        ];

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => [
                'documentType',
                'merchant',
                'receiptNumber',
                'purchaseDate',
                'currency',
                'totalAmount',
                'taxAmount',
                'notes',
                'warnings',
                'candidates',
            ],
            'properties' => [
                'documentType' => [
                    'type' => 'string',
                    'enum' => ['receipt', 'stock', 'unrelated', 'medical'],
                ],
                'merchant' => $nullableString,
                'receiptNumber' => $nullableString,
                'purchaseDate' => $nullableString,
                'currency' => $nullableString,
                'totalAmount' => $nullableString,
                'taxAmount' => $nullableString,
                'notes' => $nullableString,
                'warnings' => $warnings,
                'candidates' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => [
                            'candidateType',
                            'rawText',
                            'description',
                            'brand',
                            'product',
                            'variant',
                            'quantity',
                            'quantityMinimum',
                            'quantityMaximum',
                            'packText',
                            'unitPrice',
                            'lineTotal',
                            'discountAmount',
                            'taxAmount',
                            'boundingRegion',
                            'confidence',
                            'fieldConfidence',
                            'warnings',
                            'unresolvedValues',
                        ],
                        'properties' => [
                            'candidateType' => [
                                'type' => 'string',
                                'enum' => ['receipt_line', 'stock_item'],
                            ],
                            'rawText' => $nullableString,
                            'description' => ['type' => 'string'],
                            'brand' => $nullableString,
                            'product' => $nullableString,
                            'variant' => $nullableString,
                            'quantity' => $nullableDecimal,
                            'quantityMinimum' => $nullableDecimal,
                            'quantityMaximum' => $nullableDecimal,
                            'packText' => $nullableString,
                            'unitPrice' => $nullableString,
                            'lineTotal' => $nullableString,
                            'discountAmount' => $nullableString,
                            'taxAmount' => $nullableString,
                            'boundingRegion' => $boundingRegion,
                            'confidence' => ['type' => 'number'],
                            'fieldConfidence' => $fieldConfidence,
                            'warnings' => $warnings,
                            'unresolvedValues' => $warnings,
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    public function validate(array $result, string $expectedKind): array
    {
        $expectedKeys = [
            'documentType',
            'merchant',
            'receiptNumber',
            'purchaseDate',
            'currency',
            'totalAmount',
            'taxAmount',
            'notes',
            'warnings',
            'candidates',
        ];
        $this->exactKeys($result, $expectedKeys, 'extraction');
        if (in_array($result['documentType'], ['unrelated', 'medical'], true)) {
            throw new AiProviderException(
                'document_rejected',
                'The image was classified as unrelated or sensitive and was not extracted.',
            );
        }
        if ($result['documentType'] !== $expectedKind) {
            throw new AiProviderException('schema_mismatch', 'The provider returned another document type.');
        }
        foreach (['merchant', 'receiptNumber'] as $field) {
            $this->nullableString($result[$field], 191);
        }
        $this->nullableString($result['purchaseDate'], 10);
        if (
            $result['purchaseDate'] !== null
            && (
                ! is_string($result['purchaseDate'])
                || preg_match('/^\d{4}-\d{2}-\d{2}$/', $result['purchaseDate']) !== 1
            )
        ) {
            throw new AiProviderException('schema_mismatch', 'The provider returned an invalid purchase date.');
        }
        $this->nullableString($result['currency'], 3);
        if (
            $result['currency'] !== null
            && (
                ! is_string($result['currency'])
                || preg_match('/^[A-Z]{3}$/', $result['currency']) !== 1
            )
        ) {
            throw new AiProviderException('schema_mismatch', 'The provider returned an invalid currency.');
        }
        $this->nullableDecimal($result['totalAmount'], 2);
        $this->nullableDecimal($result['taxAmount'], 2);
        $this->nullableString($result['notes'], 2000);
        $this->stringList($result['warnings'], 50, 191);
        if (! is_array($result['candidates']) || ! array_is_list($result['candidates'])) {
            throw new AiProviderException('schema_mismatch', 'The provider returned an invalid candidate list.');
        }
        if (count($result['candidates']) > 200) {
            throw new AiProviderException('schema_mismatch', 'The provider returned too many candidates.');
        }
        $candidateType = $expectedKind === 'receipt' ? 'receipt_line' : 'stock_item';
        foreach ($result['candidates'] as $candidate) {
            if (! is_array($candidate) || array_is_list($candidate)) {
                throw new AiProviderException('schema_mismatch', 'A provider candidate is not an object.');
            }
            $this->exactKeys($candidate, [
                'candidateType',
                'rawText',
                'description',
                'brand',
                'product',
                'variant',
                'quantity',
                'quantityMinimum',
                'quantityMaximum',
                'packText',
                'unitPrice',
                'lineTotal',
                'discountAmount',
                'taxAmount',
                'boundingRegion',
                'confidence',
                'fieldConfidence',
                'warnings',
                'unresolvedValues',
            ], 'candidate');
            if (
                $candidate['candidateType'] !== $candidateType
                || ! is_string($candidate['description'])
                || trim($candidate['description']) === ''
                || mb_strlen($candidate['description']) > 500
            ) {
                throw new AiProviderException('schema_mismatch', 'A provider candidate is invalid.');
            }
            $this->nullableString($candidate['rawText'], 500);
            foreach (['brand', 'product', 'variant', 'packText'] as $field) {
                $this->nullableString($candidate[$field], 191);
            }
            if ($candidateType === 'receipt_line') {
                $this->decimal($candidate['quantity'], 8, false);
                if ($candidate['quantityMinimum'] !== null || $candidate['quantityMaximum'] !== null) {
                    throw new AiProviderException(
                        'schema_mismatch',
                        'A receipt candidate must not contain a stock quantity range.',
                    );
                }
            } else {
                if ($candidate['quantity'] !== null) {
                    throw new AiProviderException(
                        'schema_mismatch',
                        'A stock candidate must preserve its count as a quantity range.',
                    );
                }
                $quantityMinimum = $this->decimal($candidate['quantityMinimum'], 8, true);
                $quantityMaximum = $this->decimal($candidate['quantityMaximum'], 8, true);
                if ($this->compareDecimals($quantityMinimum, $quantityMaximum) > 0) {
                    throw new AiProviderException(
                        'schema_mismatch',
                        'A stock candidate quantity range is inverted.',
                    );
                }
            }
            $this->nullableDecimal($candidate['unitPrice'], 2);
            $this->nullableDecimal($candidate['lineTotal'], 2);
            $this->nullableDecimal($candidate['discountAmount'], 2);
            $this->nullableDecimal($candidate['taxAmount'], 2);
            $this->confidence($candidate['confidence']);
            if (! is_array($candidate['fieldConfidence']) || array_is_list($candidate['fieldConfidence'])) {
                throw new AiProviderException('schema_mismatch', 'Field confidence is invalid.');
            }
            $this->exactKeys(
                $candidate['fieldConfidence'],
                ['description', 'quantity', 'packText', 'unitPrice', 'lineTotal'],
                'field-confidence',
            );
            foreach ($candidate['fieldConfidence'] as $confidence) {
                if ($confidence !== null) {
                    $this->confidence($confidence);
                }
            }
            $this->boundingRegion($candidate['boundingRegion']);
            $this->stringList($candidate['warnings'], 20, 191);
            $this->stringList($candidate['unresolvedValues'], 20, 120);
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $value
     * @param list<string> $expected
     */
    private function exactKeys(array $value, array $expected, string $label): void
    {
        $actual = array_keys($value);
        sort($actual);
        sort($expected);
        if ($actual !== $expected) {
            throw new AiProviderException('schema_mismatch', 'The provider returned unexpected ' . $label . ' fields.');
        }
    }

    private function nullableDecimal(mixed $value, int $scale): void
    {
        if ($value !== null) {
            $this->decimal($value, $scale, true);
        }
    }

    private function nullableString(mixed $value, int $maxLength): void
    {
        if ($value !== null && (! is_string($value) || mb_strlen($value) > $maxLength)) {
            throw new AiProviderException('schema_mismatch', 'The provider returned an invalid text field.');
        }
    }

    private function confidence(mixed $value): void
    {
        if (! is_float($value) && ! is_int($value)) {
            throw new AiProviderException('schema_mismatch', 'Provider confidence must be numeric.');
        }
        if ((float) $value < 0.0 || (float) $value > 1.0) {
            throw new AiProviderException('schema_mismatch', 'Provider confidence is outside zero to one.');
        }
    }

    private function boundingRegion(mixed $region): void
    {
        if ($region === null) {
            return;
        }
        if (! is_array($region) || array_is_list($region)) {
            throw new AiProviderException('schema_mismatch', 'The bounding region is invalid.');
        }
        $this->exactKeys($region, ['x', 'y', 'width', 'height'], 'bounding-region');
        foreach ($region as $key => $value) {
            $this->confidence($value);
            if (in_array($key, ['width', 'height'], true) && (float) $value <= 0.0) {
                throw new AiProviderException('schema_mismatch', 'The bounding region is empty.');
            }
        }
        if (
            (float) $region['x'] + (float) $region['width'] > 1.0
            || (float) $region['y'] + (float) $region['height'] > 1.0
        ) {
            throw new AiProviderException('schema_mismatch', 'The bounding region exceeds the image.');
        }
    }

    private function stringList(mixed $value, int $maxItems, int $maxLength): void
    {
        if (! is_array($value) || ! array_is_list($value) || count($value) > $maxItems) {
            throw new AiProviderException('schema_mismatch', 'The provider returned an invalid warning list.');
        }
        foreach ($value as $item) {
            if (! is_string($item) || mb_strlen($item) > $maxLength) {
                throw new AiProviderException('schema_mismatch', 'The provider returned an invalid warning.');
            }
        }
    }

    private function decimal(mixed $value, int $scale, bool $allowZero): string
    {
        if (
            ! is_string($value)
            || preg_match('/^(?:0|[1-9]\d{0,11})(?:\.\d{1,' . $scale . '})?$/', $value) !== 1
            || (! $allowZero && (float) $value <= 0.0)
        ) {
            throw new AiProviderException('schema_mismatch', 'The provider returned an invalid decimal.');
        }

        return $value;
    }

    private function compareDecimals(string $left, string $right): int
    {
        [$leftInteger, $leftFraction] = array_pad(explode('.', $left, 2), 2, '');
        [$rightInteger, $rightFraction] = array_pad(explode('.', $right, 2), 2, '');
        $leftInteger = ltrim($leftInteger, '0') ?: '0';
        $rightInteger = ltrim($rightInteger, '0') ?: '0';
        if (strlen($leftInteger) !== strlen($rightInteger)) {
            return strlen($leftInteger) <=> strlen($rightInteger);
        }
        $integerComparison = strcmp($leftInteger, $rightInteger);
        if ($integerComparison !== 0) {
            return $integerComparison;
        }

        return strcmp(str_pad($leftFraction, 8, '0'), str_pad($rightFraction, 8, '0'));
    }
}
