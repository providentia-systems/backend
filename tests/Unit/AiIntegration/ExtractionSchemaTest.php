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
}
