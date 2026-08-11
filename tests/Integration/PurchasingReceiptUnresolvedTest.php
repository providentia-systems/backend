<?php

declare(strict_types=1);

namespace ProvidentiaTest\Integration;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Providentia\Purchasing\Infrastructure\Doctrine\DbalPurchasingStore;

final class PurchasingReceiptUnresolvedTest extends TestCase
{
    private const HOME_ID = '01912345-6789-7abc-8def-0123456789ab';
    private const OTHER_HOME_ID = '01912345-6789-7abc-9def-0123456789ab';
    private const RECEIPT_ID = '01912345-6789-7abc-adef-0123456789ab';
    private const LINE_ID = '01912345-6789-7abc-bdef-0123456789ab';
    private const PRODUCT_ID = '01912345-6789-7abc-cdef-0123456789ab';
    private const PACK_ID = '01912345-6789-7abc-ddef-0123456789ab';
    private const NEW_MATCH_ID = '01912345-6789-7abc-edef-0123456789ab';

    private Connection $connection;
    private DbalPurchasingStore $store;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);
        $this->connection->executeStatement(
            'CREATE TABLE receipts (
                id VARCHAR(36) NOT NULL,
                home_id VARCHAR(36) NOT NULL,
                status VARCHAR(24) NOT NULL,
                revision INTEGER NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (id)
            )',
        );
        $this->connection->executeStatement(
            'CREATE TABLE receipt_lines (
                id VARCHAR(36) NOT NULL,
                home_id VARCHAR(36) NOT NULL,
                receipt_id VARCHAR(36) NOT NULL,
                raw_description VARCHAR(255) NOT NULL,
                quantity VARCHAR(32) NOT NULL,
                original_pack_text VARCHAR(255) NULL,
                unit_price VARCHAR(32) NULL,
                line_total VARCHAR(32) NULL,
                home_product_id VARCHAR(36) NULL,
                approval_status VARCHAR(24) NOT NULL,
                revision INTEGER NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (id)
            )',
        );
        $this->connection->executeStatement(
            'CREATE TABLE receipt_line_matches (
                id VARCHAR(36) NOT NULL,
                home_id VARCHAR(36) NOT NULL,
                receipt_line_id VARCHAR(36) NOT NULL,
                product_pack_id VARCHAR(36) NOT NULL,
                match_method VARCHAR(40) NOT NULL,
                confidence VARCHAR(16) NOT NULL,
                status VARCHAR(24) NOT NULL,
                decided_by_user_id VARCHAR(36) NULL,
                decided_at DATETIME NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (id)
            )',
        );
        $this->connection->executeStatement(
            'CREATE TABLE home_products (
                id VARCHAR(36) NOT NULL,
                home_id VARCHAR(36) NOT NULL,
                pack_id VARCHAR(36) NULL,
                status VARCHAR(24) NOT NULL,
                PRIMARY KEY (id)
            )',
        );
        $this->connection->insert('receipts', [
            'id' => self::RECEIPT_ID,
            'home_id' => self::HOME_ID,
            'status' => 'draft',
            'revision' => 6,
            'updated_at' => '2026-08-10 10:00:00',
        ]);
        $this->connection->insert('receipt_lines', [
            'id' => self::LINE_ID,
            'home_id' => self::HOME_ID,
            'receipt_id' => self::RECEIPT_ID,
            'raw_description' => 'Unknown pantry item',
            'quantity' => '1',
            'original_pack_text' => '500 g',
            'unit_price' => '12.50',
            'line_total' => '12.50',
            'home_product_id' => self::PRODUCT_ID,
            'approval_status' => 'approved',
            'revision' => 2,
            'updated_at' => '2026-08-10 10:00:00',
        ]);
        $this->connection->insert('receipt_line_matches', [
            'id' => self::LINE_ID,
            'home_id' => self::HOME_ID,
            'receipt_line_id' => self::LINE_ID,
            'product_pack_id' => self::PACK_ID,
            'match_method' => 'human-selection',
            'confidence' => '1',
            'status' => 'approved',
            'decided_by_user_id' => self::PRODUCT_ID,
            'decided_at' => '2026-08-10 10:00:00',
            'created_at' => '2026-08-10 10:00:00',
        ]);
        $this->connection->insert('home_products', [
            'id' => self::PRODUCT_ID,
            'home_id' => self::HOME_ID,
            'pack_id' => self::PACK_ID,
            'status' => 'active',
        ]);
        $this->store = new DbalPurchasingStore($this->connection);
    }

    public function testUnresolvedDecisionIsAtomicRevisionedAndHomeScoped(): void
    {
        self::assertFalse($this->store->markReceiptLineUnresolved(
            self::OTHER_HOME_ID,
            self::RECEIPT_ID,
            self::LINE_ID,
            2,
            new DateTimeImmutable('2026-08-10T11:00:00+00:00'),
        ));
        self::assertSame('approved', $this->line()['approval_status']);

        self::assertTrue($this->store->markReceiptLineUnresolved(
            self::HOME_ID,
            self::RECEIPT_ID,
            self::LINE_ID,
            2,
            new DateTimeImmutable('2026-08-10T12:00:00+00:00'),
        ));

        $line = $this->line();
        self::assertSame('unresolved', $line['approval_status']);
        self::assertNull($line['home_product_id']);
        self::assertSame(3, (int) $line['revision']);
        self::assertSame(7, (int) $this->receipt()['revision']);
        self::assertSame('superseded', $this->match()['status']);

        self::assertFalse($this->store->markReceiptLineUnresolved(
            self::HOME_ID,
            self::RECEIPT_ID,
            self::LINE_ID,
            2,
            new DateTimeImmutable('2026-08-10T13:00:00+00:00'),
        ));
        self::assertSame(3, (int) $this->line()['revision']);
        self::assertSame(7, (int) $this->receipt()['revision']);
    }

    public function testReapprovalAppendsADecisionWithoutErasingSupersededLineage(): void
    {
        self::assertTrue($this->store->markReceiptLineUnresolved(
            self::HOME_ID,
            self::RECEIPT_ID,
            self::LINE_ID,
            2,
            new DateTimeImmutable('2026-08-10T12:00:00+00:00'),
        ));

        self::assertTrue($this->store->approveReceiptLine(
            self::NEW_MATCH_ID,
            self::HOME_ID,
            self::RECEIPT_ID,
            self::LINE_ID,
            self::PRODUCT_ID,
            3,
            self::PRODUCT_ID,
            new DateTimeImmutable('2026-08-10T13:00:00+00:00'),
        ));

        $matches = $this->connection->fetchAllAssociative(
            'SELECT id, status, product_pack_id, match_method, decided_at
             FROM receipt_line_matches
             WHERE home_id = :home AND receipt_line_id = :line
             ORDER BY created_at, id',
            ['home' => self::HOME_ID, 'line' => self::LINE_ID],
        );
        self::assertCount(2, $matches);
        self::assertSame(self::LINE_ID, $matches[0]['id']);
        self::assertSame('superseded', $matches[0]['status']);
        self::assertSame(self::NEW_MATCH_ID, $matches[1]['id']);
        self::assertSame('approved', $matches[1]['status']);
        self::assertSame(self::PACK_ID, $matches[1]['product_pack_id']);
        self::assertSame('human-selection', $matches[1]['match_method']);
    }

    public function testDirectReapprovalSupersedesThePriorAuthoritativeDecision(): void
    {
        self::assertTrue($this->store->approveReceiptLine(
            self::NEW_MATCH_ID,
            self::HOME_ID,
            self::RECEIPT_ID,
            self::LINE_ID,
            self::PRODUCT_ID,
            2,
            self::PRODUCT_ID,
            new DateTimeImmutable('2026-08-10T13:00:00+00:00'),
        ));

        $matches = $this->connection->fetchAllAssociative(
            'SELECT id, status FROM receipt_line_matches
             WHERE home_id = :home AND receipt_line_id = :line
             ORDER BY created_at, id',
            ['home' => self::HOME_ID, 'line' => self::LINE_ID],
        );
        self::assertSame([
            ['id' => self::LINE_ID, 'status' => 'superseded'],
            ['id' => self::NEW_MATCH_ID, 'status' => 'approved'],
        ], $matches);
    }

    /** @return array<string, mixed> */
    private function line(): array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT home_product_id, approval_status, revision FROM receipt_lines WHERE id = :id',
            ['id' => self::LINE_ID],
        );
        self::assertIsArray($row);

        return $row;
    }

    /** @return array<string, mixed> */
    private function receipt(): array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT revision FROM receipts WHERE id = :id',
            ['id' => self::RECEIPT_ID],
        );
        self::assertIsArray($row);

        return $row;
    }

    /** @return array<string, mixed> */
    private function match(): array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT status FROM receipt_line_matches WHERE id = :id',
            ['id' => self::LINE_ID],
        );
        self::assertIsArray($row);

        return $row;
    }
}
