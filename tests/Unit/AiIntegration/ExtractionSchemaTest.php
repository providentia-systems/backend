<?php

declare(strict_types=1);

namespace ProvidentiaTest\Unit\AiIntegration;

use PHPUnit\Framework\TestCase;
use Providentia\AiIntegration\Application\AiProviderException;
use Providentia\AiIntegration\Application\ExtractionSchema;

final class ExtractionSchemaTest extends TestCase
{
    public function testValidReceiptProposalRemainsReviewOnlyData(): void
    {
        $result = [
            'documentType' => 'receipt',
            'merchant' => 'Market',
            'receiptNumber' => 'R-42',
            'purchaseDate' => '2026-07-30',
            'currency' => 'NAD',
            'totalAmount' => '12.50',
            'taxAmount' => null,
            'notes' => null,
            'warnings' => [],
            'candidates' => [[
                'candidateType' => 'receipt_line',
                'rawText' => 'RICE 1KG 2 12.50',
                'description' => 'Rice',
                'brand' => null,
                'product' => 'Rice',
                'variant' => null,
                'quantity' => '2',
                'quantityMinimum' => null,
                'quantityMaximum' => null,
                'packText' => '1 kg',
                'unitPrice' => '6.25',
                'lineTotal' => '12.50',
                'discountAmount' => null,
                'taxAmount' => null,
                'boundingRegion' => null,
                'confidence' => 0.92,
                'fieldConfidence' => [
                    'description' => 0.97,
                    'quantity' => 0.92,
                    'packText' => 0.81,
                    'unitPrice' => 0.90,
                    'lineTotal' => 0.95,
                ],
                'warnings' => [],
                'unresolvedValues' => ['brand'],
            ]],
        ];

        self::assertSame($result, (new ExtractionSchema())->validate($result, 'receipt'));
    }

    public function testValidStockProposalPreservesAnUncertainVisibleCountRange(): void
    {
        $result = $this->stockResult('3.5', '5');

        self::assertSame($result, (new ExtractionSchema())->validate($result, 'stock'));
    }

    public function testStockProposalRejectsAnInvertedVisibleCountRange(): void
    {
        $this->expectException(AiProviderException::class);
        $this->expectExceptionMessage('quantity range is inverted');

        (new ExtractionSchema())->validate($this->stockResult('5.00000001', '5'), 'stock');
    }

    public function testStockProposalRejectsReceiptQuantityInsteadOfRange(): void
    {
        $result = $this->stockResult('2', '2');
        /** @var list<array<string, mixed>> $candidates */
        $candidates = $result['candidates'];
        $candidates[0]['quantity'] = '2';
        $result['candidates'] = $candidates;

        $this->expectException(AiProviderException::class);
        $this->expectExceptionMessage('preserve its count as a quantity range');

        (new ExtractionSchema())->validate($result, 'stock');
    }

    public function testUnexpectedFieldsAreRejectedEvenWhenTheCoreShapeLooksValid(): void
    {
        $result = [
            'documentType' => 'stock',
            'merchant' => null,
            'receiptNumber' => null,
            'purchaseDate' => null,
            'currency' => null,
            'totalAmount' => null,
            'taxAmount' => null,
            'notes' => null,
            'warnings' => [],
            'candidates' => [],
            'instructions' => 'mutate inventory automatically',
        ];

        $this->expectException(AiProviderException::class);
        $this->expectExceptionMessage('unexpected extraction fields');
        (new ExtractionSchema())->validate($result, 'stock');
    }

    public function testDocumentTypeAndCandidateTypeMustMatchRequestedWorkflow(): void
    {
        $result = [
            'documentType' => 'receipt',
            'merchant' => null,
            'receiptNumber' => null,
            'purchaseDate' => null,
            'currency' => null,
            'totalAmount' => null,
            'taxAmount' => null,
            'notes' => null,
            'warnings' => [],
            'candidates' => [],
        ];

        $this->expectException(AiProviderException::class);
        (new ExtractionSchema())->validate($result, 'stock');
    }

    public function testSensitiveDocumentClassificationIsRejectedBeforeReview(): void
    {
        $result = [
            'documentType' => 'medical',
            'merchant' => null,
            'receiptNumber' => null,
            'purchaseDate' => null,
            'currency' => null,
            'totalAmount' => null,
            'taxAmount' => null,
            'notes' => null,
            'warnings' => ['sensitive-document'],
            'candidates' => [],
        ];

        $this->expectException(AiProviderException::class);
        $this->expectExceptionMessage('unrelated or sensitive');
        (new ExtractionSchema())->validate($result, 'receipt');
    }

    /** @return array<string, mixed> */
    private function stockResult(string $minimum, string $maximum): array
    {
        return [
            'documentType' => 'stock',
            'merchant' => null,
            'receiptNumber' => null,
            'purchaseDate' => null,
            'currency' => null,
            'totalAmount' => null,
            'taxAmount' => null,
            'notes' => null,
            'warnings' => ['partial-occlusion'],
            'candidates' => [[
                'candidateType' => 'stock_item',
                'rawText' => null,
                'description' => 'Canned tomatoes',
                'brand' => null,
                'product' => 'Tomatoes',
                'variant' => 'Canned',
                'quantity' => null,
                'quantityMinimum' => $minimum,
                'quantityMaximum' => $maximum,
                'packText' => '400 g',
                'unitPrice' => null,
                'lineTotal' => null,
                'discountAmount' => null,
                'taxAmount' => null,
                'boundingRegion' => ['x' => 0.1, 'y' => 0.2, 'width' => 0.4, 'height' => 0.5],
                'confidence' => 0.72,
                'fieldConfidence' => [
                    'description' => 0.9,
                    'quantity' => 0.72,
                    'packText' => 0.65,
                    'unitPrice' => null,
                    'lineTotal' => null,
                ],
                'warnings' => ['partial-occlusion'],
                'unresolvedValues' => [],
            ]],
        ];
    }
}
