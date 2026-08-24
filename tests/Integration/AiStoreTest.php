<?php

declare(strict_types=1);

namespace ProvidentiaTest\Integration;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Providentia\AiIntegration\Infrastructure\Doctrine\DbalAiStore;

final class AiStoreTest extends TestCase
{
    private Connection $connection;
    private DbalAiStore $store;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);
        $this->connection->executeStatement(
            'CREATE TABLE ai_extractions (
                id VARCHAR(36) PRIMARY KEY,
                home_id VARCHAR(36) NOT NULL,
                kind VARCHAR(24) NOT NULL,
                target_id VARCHAR(36) NULL,
                provider VARCHAR(40) NOT NULL,
                model VARCHAR(120) NOT NULL,
                status VARCHAR(24) NOT NULL,
                input_mime_type VARCHAR(40) NOT NULL,
                input_sha256 VARCHAR(64) NOT NULL,
                input_byte_count INTEGER NOT NULL,
                schema_version INTEGER NOT NULL,
                prompt_template_version INTEGER NOT NULL,
                processing_ms INTEGER NULL,
                usage_json TEXT NULL,
                result_json TEXT NULL,
                error_code VARCHAR(64) NULL,
                error_detail VARCHAR(500) NULL,
                created_by_user_id VARCHAR(36) NOT NULL,
                completed_at DATETIME NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL
            )',
        );
        $this->connection->executeStatement(
            'CREATE TABLE ai_extraction_candidates (
                home_id VARCHAR(36) NOT NULL,
                extraction_id VARCHAR(36) NOT NULL,
                position INTEGER NOT NULL,
                candidate_type VARCHAR(32) NOT NULL,
                payload_json TEXT NOT NULL,
                confidence DECIMAL(5,4) NULL,
                review_status VARCHAR(24) NOT NULL,
                revision INTEGER NOT NULL,
                reviewed_by_user_id VARCHAR(36) NULL,
                reviewed_at DATETIME NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (extraction_id, position)
            )',
        );
        $this->connection->executeStatement(
            'CREATE TABLE receipts (
                id VARCHAR(36) PRIMARY KEY,
                home_id VARCHAR(36) NOT NULL,
                status VARCHAR(24) NOT NULL
            )',
        );
        $this->connection->executeStatement(
            'CREATE TABLE stock_count_sessions (
                id VARCHAR(36) PRIMARY KEY,
                home_id VARCHAR(36) NOT NULL,
                status VARCHAR(24) NOT NULL
            )',
        );
        $this->store = new DbalAiStore($this->connection);
    }

    public function testExtractionTargetsMustBeOpenWorkflowsInTheSameHome(): void
    {
        $this->connection->insert('receipts', ['id' => 'receipt-1', 'home_id' => 'home-1', 'status' => 'draft']);
        $this->connection->insert('receipts', ['id' => 'receipt-2', 'home_id' => 'home-1', 'status' => 'committed']);
        $this->connection->insert(
            'stock_count_sessions',
            ['id' => 'count-1', 'home_id' => 'home-1', 'status' => 'open'],
        );
        $this->connection->insert(
            'stock_count_sessions',
            ['id' => 'count-2', 'home_id' => 'home-1', 'status' => 'closed'],
        );

        self::assertTrue($this->store->targetExists('home-1', 'receipt', 'receipt-1'));
        self::assertTrue($this->store->targetExists('home-1', 'receipt', null));
        self::assertTrue($this->store->targetExists('home-1', 'receipt', ''));
        self::assertFalse($this->store->targetExists('home-1', 'receipt', 'receipt-2'));
        self::assertFalse($this->store->targetExists('home-2', 'receipt', 'receipt-1'));
        self::assertTrue($this->store->targetExists('home-1', 'stock', 'count-1'));
        self::assertFalse($this->store->targetExists('home-1', 'stock', 'count-2'));
        self::assertFalse($this->store->targetExists('home-1', 'stock', null));
    }

    public function testCandidateReviewIsRevisionCheckedAndDoesNotCreateDomainData(): void
    {
        $at = new DateTimeImmutable('2026-07-30T12:00:00+00:00');
        $this->store->startExtraction(
            'extraction-1',
            'home-1',
            'receipt',
            'receipt-1',
            'openai',
            'vision-model',
            'image/png',
            str_repeat('a', 64),
            128,
            1,
            'user-1',
            $at,
        );
        $this->store->completeExtraction(
            'extraction-1',
            'home-1',
            [
                'documentType' => 'receipt',
                'candidates' => [[
                    'candidateType' => 'receipt_line',
                    'description' => 'Rice',
                    'quantity' => '1',
                    'confidence' => 0.9,
                ]],
            ],
            ['inputTokens' => 10, 'outputTokens' => 5, 'totalTokens' => 15],
            42,
            $at,
        );

        self::assertTrue($this->store->reviewCandidate(
            'home-1',
            'extraction-1',
            0,
            'accepted',
            1,
            'user-1',
            $at->modify('+1 minute'),
        ));
        self::assertFalse($this->store->reviewCandidate(
            'home-1',
            'extraction-1',
            0,
            'rejected',
            1,
            'user-2',
            $at->modify('+2 minutes'),
        ));

        $extraction = $this->store->extraction('home-1', 'extraction-1');
        self::assertNotNull($extraction);
        /** @var list<array<string, mixed>> $candidates */
        $candidates = $extraction['candidates'];
        /** @var array<string, mixed> $usage */
        $usage = $extraction['usage'];
        self::assertCount(1, $candidates);
        self::assertSame('accepted', $candidates[0]['reviewStatus']);
        self::assertSame(2, (int) $candidates[0]['revision']);
        self::assertSame(42, (int) $extraction['processingMs']);
        self::assertSame(15, $usage['totalTokens']);
        self::assertSame(1, $this->tableRows('ai_extractions'));
        self::assertSame(1, $this->tableRows('ai_extraction_candidates'));
    }

    private function tableRows(string $table): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM ' . $table);
    }
}
