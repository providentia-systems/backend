<?php

declare(strict_types=1);

namespace ProvidentiaTest\Integration\Platform;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Laminas\ServiceManager\ServiceManager;
use PHPUnit\Framework\TestCase;
use Providentia\Inventory\Infrastructure\Doctrine\DbalInventoryStore;
use Providentia\Purchasing\Infrastructure\Doctrine\DbalPurchasingStore;
use Providentia\SharedKernel\Infrastructure\Factory\ConnectionFactory;
use Ramsey\Uuid\Uuid;

/** Runs against both real database matrix services; SQLite cannot test row-lock waits. */
final class PurchasingHeaderConcurrencyTest extends TestCase
{
    public function testLineCorrectionWaitsForDateCorrectionAndPublishesCurrentHeaderPrice(): void
    {
        $url = $_SERVER['DATABASE_URL'] ?? '';
        if (!is_string($url) || preg_match('#^(?:mysql|mariadb)://#', $url) !== 1) {
            self::markTestSkipped('The MySQL/MariaDB matrix supplies the original DATABASE_URL.');
        }
        if (!function_exists('proc_open')) {
            self::markTestSkipped('The database concurrency regression requires subprocess support.');
        }
        $db = $this->connection($url);
        $store = new DbalPurchasingStore($db);
        $home = Uuid::uuid7()->toString();
        $user = Uuid::uuid7()->toString();
        $product = Uuid::uuid7()->toString();
        $receipt = Uuid::uuid7()->toString();
        $line = Uuid::uuid7()->toString();
        $at = new DateTimeImmutable('2026-10-20T12:00:00Z');
        $process = null;
        $pipes = [];
        try {
            $db->insert('homes', [
                'id' => $home, 'name' => 'Synthetic receipt concurrency', 'default_locale' => 'en-NA',
                'default_currency' => 'NAD', 'default_timezone' => 'UTC', 'status' => 'active',
                'created_at' => '2026-10-20 12:00:00', 'updated_at' => '2026-10-20 12:00:00',
            ]);
            $db->insert('users', [
                'id' => $user, 'email' => $user . '@example.test', 'normalized_email' => $user . '@example.test',
                'status' => 'active', 'created_at' => '2026-10-20 12:00:00',
                'updated_at' => '2026-10-20 12:00:00',
            ]);
            new DbalInventoryStore($db)->createHomeProduct(
                $product,
                $home,
                null,
                null,
                'Synthetic rice',
                'synthetic rice',
                null,
                null,
                $at,
            );
            $store->createReceipt($receipt, $home, null, '2026-10-15', 'NAD', '40', 'manual', null, '', $user, $at);
            self::assertTrue($store->addReceiptLine(
                $line,
                $home,
                $receipt,
                1,
                1,
                'Rice',
                '4',
                null,
                '10',
                '40',
                $at,
                ['home_product_id' => $product, 'approval_status' => 'approved',
                    'stock_quantity' => '4', 'stock_quantity_per_pack' => '1', 'stock_unit' => 'units'],
            ));
            $store->recordPriceObservation(
                Uuid::uuid7()->toString(),
                $home,
                $line,
                null,
                null,
                'NAD',
                '4',
                '10',
                '40',
                new DateTimeImmutable('2026-10-15T00:00:00Z'),
                $at,
            );
            $db->beginTransaction();
            self::assertTrue($store->correctReceiptDate(
                Uuid::uuid7()->toString(),
                $home,
                $receipt,
                '2026-10-15',
                '2026-10-14',
                'Verified original receipt',
                2,
                $user,
                $at,
            ));
            $store->recordPriceObservation(
                Uuid::uuid7()->toString(),
                $home,
                $line,
                null,
                null,
                'NAD',
                '4',
                '10',
                '40',
                new DateTimeImmutable('2026-10-14T00:00:00Z'),
                $at,
            );
            $process = proc_open(
                [PHP_BINARY, '-r', $this->worker()],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                dirname(__DIR__, 3),
            );
            self::assertIsResource($process);
            fwrite($pipes[0], json_encode(compact('url', 'home', 'receipt', 'line'), JSON_THROW_ON_ERROR));
            fclose($pipes[0]);
            stream_set_timeout($pipes[1], 10);
            self::assertSame("ready\n", fgets($pipes[1]));
            // The worker has established an old line snapshot, then reads the
            // header. It must wait for this transaction instead of reading day15.
            $ready = [$pipes[1]];
            $write = null;
            $except = null;
            self::assertSame(0, stream_select($ready, $write, $except, 0, 200000));
            $db->commit();
            $result = fgets($pipes[1]);
            self::assertIsString($result);
            $result = json_decode($result, true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('2026-10-14', $result['purchaseDate']);
            self::assertSame('', stream_get_contents($pipes[2]));
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process));
            $process = null;
            self::assertSame('2026-10-14 00:00:00', $db->fetchOne(
                'SELECT observed_at FROM price_observations WHERE home_id = ? AND superseded_at IS NULL',
                [$home],
            ));
            self::assertSame(1, (int) $db->fetchOne(
                'SELECT COUNT(*) FROM price_observations WHERE home_id = ? AND superseded_at IS NULL',
                [$home],
            ));
            self::assertSame(3, (int) $db->fetchOne(
                'SELECT COUNT(*) FROM price_observations WHERE home_id = ?',
                [$home],
            ));
        } finally {
            if ($db->isTransactionActive()) {
                $db->rollBack();
            }
            if (is_resource($process)) {
                proc_terminate($process);
                proc_close($process);
            }
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            foreach (
                ['price_observations', 'receipt_date_corrections', 'receipt_line_matches',
                    'receipt_lines', 'receipts', 'home_products'] as $table
            ) {
                $db->delete($table, ['home_id' => $home]);
            }
            $db->delete('homes', ['id' => $home]);
            $db->delete('users', ['id' => $user]);
            $db->close();
        }
    }

    private function connection(string $url): Connection
    {
        return new ConnectionFactory()(new ServiceManager([
            'services' => ['config' => ['database' => ['url' => $url]]],
        ]));
    }

    private function worker(): string
    {
        return <<<'PHP_WORKER'
        require 'vendor/autoload.php';
        try {
            $input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
            $container = new Laminas\ServiceManager\ServiceManager([
                'services' => ['config' => ['database' => ['url' => $input['url']]]],
            ]);
            $db = new Providentia\SharedKernel\Infrastructure\Factory\ConnectionFactory()($container);
            $db->executeStatement('SET SESSION innodb_lock_wait_timeout = 5');
            $db->beginTransaction();
            $store = new Providentia\Purchasing\Infrastructure\Doctrine\DbalPurchasingStore($db);
            $store->receiptLine($input['home'], $input['receipt'], $input['line']);
            echo "ready\n";
            flush();
            $header = $store->receipt($input['home'], $input['receipt']);
            $at = new DateTimeImmutable('2026-10-20T12:00:01Z');
            if (!$store->applyReceiptLine($input['home'], $input['receipt'], $input['line'], [
                'quantity' => '3', 'unit_price' => '10', 'line_total' => '30', 'stock_quantity' => '3',
            ], 1, $at)) {
                throw new RuntimeException('The synthetic line could not be revised.');
            }
            $store->recordPriceObservation(
                Ramsey\Uuid\Uuid::uuid7()->toString(), $input['home'], $input['line'], null,
                $header['storeId'], $header['currency'], '3', '10', '30',
                new DateTimeImmutable($header['purchaseDate'] . 'T00:00:00Z'), $at,
            );
            $db->commit();
            echo json_encode(['purchaseDate' => $header['purchaseDate']], JSON_THROW_ON_ERROR) . "\n";
        } catch (Throwable) {
            fwrite(STDERR, "The synthetic receipt concurrency worker failed.\n");
            exit(1);
        }
        PHP_WORKER;
    }
}
