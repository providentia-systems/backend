<?php

declare(strict_types=1);

namespace ProvidentiaTest\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Providentia\SharedKernel\Infrastructure\Doctrine\DecimalSql;
use Providentia\SharedKernel\Infrastructure\Factory\ConnectionFactory;
use Psr\Container\ContainerInterface;

final class DecimalSqlTest extends TestCase
{
    /** @return iterable<string, array{string|null, string|null, string|null}> */
    public static function additions(): iterable
    {
        yield 'smallest fractions' => ['0.00000001', '0.00000001', '0.00000002'];
        yield 'maximum and smallest fraction' => ['999999999.99999999', '0.00000001', '1000000000.00000000'];
        yield 'maximum values' => ['999999999.99999999', '999999999.99999999', '1999999999.99999998'];
        yield 'exact cancellation' => ['999999999.99999999', '-999999999.99999999', '0.00000000'];
        yield 'negative smallest fraction' => ['-0.00000001', '-0.00000001', '-0.00000002'];
        yield 'exact strings are never rounded' => ['0.000000001', '0.000000001', '0.000000002'];
        yield 'left null' => [null, '1', null];
        yield 'right null' => ['1', null, null];
        yield 'both null' => [null, null, null];
    }

    #[DataProvider('additions')]
    public function testAdditionPreservesExactStringsAndSqlNulls(
        ?string $left,
        ?string $right,
        ?string $expected,
    ): void {
        $connection = $this->connection();
        self::assertSame($expected, $connection->fetchOne(
            'SELECT ' . DecimalSql::add($connection, '?', '?'),
            [$left, $right],
        ));
    }

    /** @return iterable<string, array{string|null, string|null, int|null, int|null}> */
    public static function comparisons(): iterable
    {
        yield 'numeric not lexical' => ['2', '10', 1, 0];
        yield 'numeric not lexical reversed' => ['10', '2', 0, 1];
        yield 'smallest positive fraction' => ['0.00000001', '0', 0, 1];
        yield 'negative fraction' => ['-0.00000001', '0', 1, 0];
        yield 'maximum fractional difference' => ['999999999.99999998', '999999999.99999999', 1, 0];
        yield 'equivalent scales' => ['2.00000000', '2', 0, 0];
        yield 'negative values' => ['-10', '-2', 1, 0];
        yield 'left null' => [null, '1', null, null];
        yield 'right null' => ['1', null, null, null];
        yield 'both null' => [null, null, null, null];
    }

    #[DataProvider('comparisons')]
    public function testComparisonUsesExactNumericOrderAndSqlNulls(
        ?string $left,
        ?string $right,
        ?int $lessThan,
        ?int $greaterThan,
    ): void {
        $connection = $this->connection();
        self::assertSame($lessThan, $connection->fetchOne(
            'SELECT ' . DecimalSql::lessThan($connection, '?', '?'),
            [$left, $right],
        ));
        self::assertSame($greaterThan, $connection->fetchOne(
            'SELECT ' . DecimalSql::greaterThan($connection, '?', '?'),
            [$left, $right],
        ));
    }

    public function testGroupedSumKeepsEveryFractionAndNullGroupIndependent(): void
    {
        $connection = $this->connection();
        $connection->executeStatement('CREATE TABLE amounts (group_id TEXT NOT NULL, amount TEXT)');
        $amounts = [
            ['cancel', '999999999.99999999'],
            ['cancel', '0.00000001'],
            ['cancel', '-999999999.99999999'],
            ['nulls', null],
            ['nulls', null],
            ['tiny', '0.00000001'],
            ['tiny', null],
            ['tiny', '0.00000001'],
            ['zeros', '1.25000000'],
            ['zeros', '-1.25000000'],
        ];
        foreach ($amounts as [$group, $amount]) {
            $connection->insert('amounts', ['group_id' => $group, 'amount' => $amount]);
        }
        for ($row = 0; $row < 100; ++$row) {
            $connection->insert('amounts', ['group_id' => 'large', 'amount' => '999999999.99999999']);
        }

        $sum = DecimalSql::sum($connection, 'amount');
        self::assertSame([
            ['group_id' => 'cancel', 'total' => '0.00000001'],
            ['group_id' => 'large', 'total' => '99999999999.99999900'],
            ['group_id' => 'nulls', 'total' => null],
            ['group_id' => 'tiny', 'total' => '0.00000002'],
            ['group_id' => 'zeros', 'total' => '0.00000000'],
        ], $connection->fetchAllAssociative(
            'SELECT group_id, ' . $sum . ' AS total FROM amounts GROUP BY group_id ORDER BY group_id',
        ));
        self::assertNull($connection->fetchOne('SELECT ' . $sum . ' FROM amounts WHERE 1 = 0'));
        self::assertSame(0, $connection->fetchOne('SELECT COALESCE(' . $sum . ', 0) FROM amounts WHERE 1 = 0'));
    }

    public function testAggregateIntermediatesAreNotLimitedByPhpIntegerRange(): void
    {
        $connection = $this->connection();
        $sum = DecimalSql::sum($connection, 'amount');
        self::assertSame('18446744073709551616.00000002', $connection->fetchOne(
            'SELECT ' . $sum . ' FROM (SELECT ? AS amount UNION ALL SELECT ?)',
            ['9223372036854775808.00000001', '9223372036854775808.00000001'],
        ));
    }

    public function testDatabaseNumericInputsAndNestedAggregateAdditionRemainUsable(): void
    {
        $connection = $this->connection();
        $sum = DecimalSql::sum($connection, 'amount');
        $add = DecimalSql::add($connection, $sum, "'999999999.99999999'");
        self::assertSame('1000000002.00000000', $connection->fetchOne(
            'SELECT ' . $add . ' FROM (SELECT 2 AS amount UNION ALL SELECT 0.00000001)',
        ));
        self::assertSame(3, $connection->fetchOne('SELECT SUM(value) FROM (SELECT 1 AS value UNION ALL SELECT 2)'));
    }

    public function testRepeatedExpressionsDoNotReregisterFunctionsWhileAResultIsActive(): void
    {
        $connection = $this->connection();
        $sum = DecimalSql::sum($connection, 'amount');
        $result = $connection->executeQuery(
            'SELECT ' . $sum . ' FROM (SELECT 1 AS id, ? AS amount UNION ALL SELECT 2, ?) GROUP BY id',
            ['999999999.99999999', '0.00000001'],
        );
        self::assertSame('999999999.99999999', $result->fetchOne());
        self::assertSame('0.00000002', $connection->fetchOne(
            'SELECT ' . DecimalSql::add($connection, '?', '?'),
            ['0.00000001', '0.00000001'],
        ));
        self::assertSame('0.00000001', $result->fetchOne());
        $result->free();
    }

    #[DataProvider('connectionKinds')]
    public function testOrderingUsesExactNumericValuesAndPreservesNullOrdering(bool $factory): void
    {
        $connection = $this->connection($factory);
        $connection->executeStatement('CREATE TABLE amounts (amount TEXT)');
        $ascending = [
            null,
            '-999999999.99999999',
            '-100',
            '-10',
            '-2',
            '-0.00000001',
            '0',
            '0.00000001',
            '2',
            '10',
            '100',
            '999999999.99999998',
            '999999999.99999999',
        ];
        foreach (array_reverse($ascending) as $amount) {
            $connection->insert('amounts', ['amount' => $amount]);
        }
        $order = DecimalSql::order($connection, 'amount');
        self::assertSame($ascending, $connection->fetchFirstColumn('SELECT amount FROM amounts ORDER BY ' . $order));
        self::assertSame(array_reverse($ascending), $connection->fetchFirstColumn(
            'SELECT amount FROM amounts ORDER BY ' . $order . ' DESC',
        ));
    }

    public function testDecimalOrderingWorksAfterAnotherSortKeyAndTreatsScalesAsEqual(): void
    {
        $connection = $this->connection();
        $connection->executeStatement('CREATE TABLE amounts (id INTEGER, currency TEXT, amount TEXT)');
        $amounts = [
            [1, 'USD', '100'],
            [2, 'USD', '10'],
            [3, 'USD', '2'],
            [4, 'EUR', '10'],
            [5, 'EUR', '2.00000000'],
            [6, 'EUR', '2'],
        ];
        foreach ($amounts as [$id, $currency, $amount]) {
            $connection->insert('amounts', ['id' => $id, 'currency' => $currency, 'amount' => $amount]);
        }
        self::assertSame([5, 6, 4, 3, 2, 1], $connection->fetchFirstColumn(
            'SELECT id FROM amounts ORDER BY currency, ' . DecimalSql::order($connection, 'amount') . ', id',
        ));
    }

    /** @return iterable<string, array{bool}> */
    public static function connectionKinds(): iterable
    {
        yield 'plain DriverManager connection' => [false];
        yield 'production connection wrapper' => [true];
    }

    #[DataProvider('connectionKinds')]
    public function testFunctionsAreRegisteredOnEachNativeConnectionIncludingReconnects(bool $factory): void
    {
        $connection = $this->connection($factory);
        $other = $this->connection($factory);
        foreach ([$connection, $other] as $current) {
            self::assertSame('1000000000.00000000', $current->fetchOne(
                'SELECT ' . DecimalSql::add($current, '?', '?'),
                ['999999999.99999999', '0.00000001'],
            ));
        }
        $original = $connection->getNativeConnection();
        $connection->close();
        self::assertFalse($connection->isConnected());
        $sum = DecimalSql::sum($connection, 'amount');
        self::assertNotSame($original, $connection->getNativeConnection());
        self::assertSame('0.00000001', $connection->fetchOne(
            'SELECT ' . $sum . ' FROM (SELECT ? AS amount)',
            ['0.00000001'],
        ));
        self::assertSame(1, $connection->fetchOne(
            'SELECT ' . DecimalSql::lessThan($connection, "'2'", "'10'"),
        ));
        self::assertSame(1, $connection->fetchOne(
            'SELECT ' . DecimalSql::greaterThan($connection, "'10'", "'2'"),
        ));
        self::assertSame(['2', '10'], $connection->fetchFirstColumn(
            "SELECT amount FROM (SELECT '10' AS amount UNION ALL SELECT '2') ORDER BY "
                . DecimalSql::order($connection, 'amount'),
        ));
    }

    public function testMysqlAndMariadbKeepNativeDecimalSqlWithoutOpeningPdoConnections(): void
    {
        foreach ([new MySQLPlatform(), new MariaDBPlatform()] as $platform) {
            $connection = $this->createMock(Connection::class);
            $connection->method('getDatabasePlatform')->willReturn($platform);
            $connection->expects(self::never())->method('getNativeConnection');
            self::assertSame('SUM(amount)', DecimalSql::sum($connection, 'amount'));
            self::assertSame('(amount + :amount)', DecimalSql::add($connection, 'amount', ':amount'));
            self::assertSame('(amount < :amount)', DecimalSql::lessThan($connection, 'amount', ':amount'));
            self::assertSame('(amount > :amount)', DecimalSql::greaterThan($connection, 'amount', ':amount'));
            self::assertSame('amount', DecimalSql::order($connection, 'amount'));
        }
    }

    private function connection(bool $factory = false): Connection
    {
        if (! $factory) {
            return DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        }
        $container = $this->createStub(ContainerInterface::class);
        $container->method('get')->willReturn(['database' => ['url' => 'sqlite:///:memory:']]);
        return (new ConnectionFactory())($container);
    }
}
