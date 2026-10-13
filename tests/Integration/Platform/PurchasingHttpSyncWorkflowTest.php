<?php

declare(strict_types=1);

namespace ProvidentiaTest\Integration\Platform;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\Migrations\Configuration\Connection\ExistingConnection;
use Doctrine\Migrations\Configuration\Migration\ConfigurationArray;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\MigratorConfiguration;
use Doctrine\Migrations\Version\Version;
use Laminas\Diactoros\ServerRequest;
use Laminas\Diactoros\StreamFactory;
use Laminas\ServiceManager\ServiceManager;
use Mezzio\Application;
use PHPUnit\Framework\TestCase;
use Providentia\Access\Application\AccessStore;
use Providentia\Access\Domain\FeatureCatalog;
use Providentia\Home\Application\HomeStore;
use Providentia\Identity\Application\AuthenticationService;
use Providentia\Identity\Application\IdentityStore;
use Providentia\SharedKernel\Application\TransactionManager;
use Providentia\SharedKernel\Application\UuidGenerator;

/** Real route, JSON parser, bearer authentication and typed synchronization integration. */
final class PurchasingHttpSyncWorkflowTest extends TestCase
{
    private Connection $db;
    private ServiceManager $container;
    private Application $app;
    private string $homeId;
    private string $otherHomeId;
    private string $ownerToken;
    private string $secondDeviceToken;
    private string $outsiderToken;
    private string $deviceId;
    private string $productId;
    private string $oneKgPackId;
    private string $fourKgPackId;
    private string $itemId;
    private string $receiptId;

    protected function setUp(): void
    {
        $this->db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $migrations = DependencyFactory::fromConnection(
            new ConfigurationArray(require dirname(__DIR__, 3) . '/config/migrations.php'),
            new ExistingConnection($this->db),
        );
        $migrations->getMetadataStorage()->ensureInitialized();
        $plan = $migrations->getMigrationPlanCalculator()->getPlanUntilVersion(
            new Version('Providentia\Migrations\Version20261013000100'),
        );
        $migrations->getMigrator()->migrate($plan, new MigratorConfiguration());
        $this->container = require dirname(__DIR__, 3) . '/config/container.php';
        $this->container->setAllowOverride(true);
        $this->container->setService(Connection::class, $this->db);
        $connection = $this->db;
        $transactions = $this->createStub(TransactionManager::class);
        $transactions->method('transactional')->willReturnCallback(
            static fn (callable $work): mixed => $connection->transactional(static fn (): mixed => $work()),
        );
        $this->container->setService(TransactionManager::class, $transactions);
        $this->seedAccountsAndCatalog();
        $this->app = $this->container->get(Application::class);
        (require dirname(__DIR__, 3) . '/config/pipeline.php')($this->app);
        (require dirname(__DIR__, 3) . '/config/routes.php')($this->app);

        $item = $this->request('POST', '/products', [
            'productId' => $this->productId, 'packId' => $this->oneKgPackId, 'unit' => 'kg',
        ], 201);
        $this->itemId = $item['id'];
        $this->request('POST', '/stock-adjustments', [
            'homeProductId' => $this->itemId, 'quantityDelta' => '2', 'reason' => 'Existing counted stock',
            'operationId' => $this->id(),
        ], 201);
        $receipt = $this->request('POST', '/receipts', [
            'storeId' => null, 'purchaseDate' => '2026-10-13', 'currency' => 'NAD',
            'totalAmount' => null, 'notes' => 'Synthetic purchase regression', 'sourceReference' => null,
        ], 201);
        $this->receiptId = $receipt['id'];
    }

    public function testHttpPurchaseAndTypedSyncPreservePackagingAndNeverDoubleStockOnRetry(): void
    {
        $bootstrap = $this->request('GET', '/sync/bootstrap', null, 200, $this->secondDeviceToken);
        self::assertFalse($bootstrap['hasMore']);
        $cursor = $bootstrap['snapshotCursor'];
        self::assertIsString($cursor);

        $firstBody = $this->purchaseBody($this->oneKgPackId, '4', '1', 1);
        $first = $this->request('POST', $this->purchasesPath(), $firstBody, 201);
        self::assertSame('approved', $first['approvalStatus']);
        self::assertSame('4', $first['quantity']);
        self::assertSame('4', $first['stockQuantity']);
        self::assertSame('1', $first['stockQuantityPerPack']);
        self::assertSame($first, $this->request('POST', $this->purchasesPath(), $firstBody, 201));
        $this->assertBalance('6');

        $secondBody = $this->purchaseBody($this->fourKgPackId, '1', '4', 2);
        $secondId = $secondBody['id'];
        unset($secondBody['id'], $secondBody['expectedReceiptRevision']);
        $batchId = $this->id();
        $envelope = [
            'protocolVersion' => 2, 'batchId' => $batchId, 'deviceId' => $this->deviceId,
            'lastPulledCursor' => null,
            'operations' => [[
                'operationId' => $this->id(), 'commandType' => 'purchasing.receipt-line.purchase',
                'entityId' => $secondId, 'baseRevision' => 2,
                'clientTimestamp' => '2026-10-13T07:00:00Z', 'payloadSchemaVersion' => 1,
                'payload' => ['receiptId' => $this->receiptId, ...$secondBody],
            ]],
        ];
        $headers = ['Idempotency-Key' => $batchId];
        $second = $this->request('POST', '/sync/push', $envelope, 200, null, null, $headers);
        self::assertSame('accepted', $second['results'][0]['status']);
        self::assertSame('1', $second['results'][0]['result']['quantity']);
        self::assertSame('4', $second['results'][0]['result']['stockQuantity']);
        self::assertSame('4', $second['results'][0]['result']['stockQuantityPerPack']);
        $retry = $this->request('POST', '/sync/push', $envelope, 200, null, null, $headers);
        self::assertSame($second['results'], $retry['results']);
        $this->assertBalance('10');

        $draft = $this->request('GET', '/receipts/' . $this->receiptId);
        self::assertSame('draft', $draft['status']);
        self::assertCount(2, $draft['lines']);
        self::assertSame([$this->oneKgPackId, $this->fourKgPackId], array_column($draft['lines'], 'packId'));
        self::assertSame(['4', '1'], array_column($draft['lines'], 'quantity'));
        $feed = $this->request('GET', '/sync/pull', null, 200, $this->secondDeviceToken, null, [], [
            'cursor' => $cursor,
        ]);
        self::assertFalse($feed['hasMore']);
        $balances = array_values(array_filter(
            $feed['changes'],
            static fn (array $change): bool => $change['entityType'] === 'inventory-balance',
        ));
        self::assertNotEmpty($balances);
        $lastBalance = array_pop($balances);
        self::assertIsArray($lastBalance);
        self::assertSame('10', $lastBalance['representation']['quantity']);
        $lines = array_values(array_filter(
            $feed['changes'],
            static fn (array $change): bool => $change['entityType'] === 'purchasing-receipt-line',
        ));
        self::assertCount(2, $lines);
        self::assertSame(['4', '4'], array_column(array_column($lines, 'representation'), 'stockQuantity'));

        $complete = $this->request('POST', '/receipts/' . $this->receiptId . '/commit', [
            'expectedRevision' => $draft['revision'],
        ]);
        self::assertSame('committed', $complete['status']);
        self::assertSame(0, $complete['movements']);
        $this->assertBalance('10');
        self::assertSame(2, (int) $this->db->fetchOne(
            "SELECT COUNT(*) FROM stock_movements WHERE movement_type = 'purchase-in'",
        ));
    }

    public function testPurchaseRouteRejectsUnauthenticatedAndOtherHomeRequestsWithoutWrites(): void
    {
        $body = $this->purchaseBody($this->oneKgPackId, '4', '1', 1);
        $this->request('POST', $this->purchasesPath(), $body, 401, '');
        $this->request('POST', $this->purchasesPath(), $body, 404, $this->outsiderToken);
        $this->request('POST', $this->purchasesPath(), $body, 404, $this->outsiderToken, $this->otherHomeId);
        $this->assertBalance('2');
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM receipt_lines'));
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM price_observations'));
    }

    public function testChangedHttpRetryAndStaleTypedPurchaseCannotPartiallyChangeStock(): void
    {
        $body = $this->purchaseBody($this->oneKgPackId, '4', '1', 1);
        $this->request('POST', $this->purchasesPath(), $body, 201);
        $this->request('POST', $this->purchasesPath(), [...$body, 'quantity' => '5'], 409);
        $batch = $this->id();
        $payload = $this->purchaseBody($this->fourKgPackId, '1', '4', 1);
        $lineId = $payload['id'];
        unset($payload['id'], $payload['expectedReceiptRevision']);
        $response = $this->request('POST', '/sync/push', [
            'protocolVersion' => 2, 'batchId' => $batch, 'deviceId' => $this->deviceId,
            'lastPulledCursor' => null,
            'operations' => [[
                'operationId' => $this->id(), 'commandType' => 'purchasing.receipt-line.purchase',
                'entityId' => $lineId, 'baseRevision' => 1, 'clientTimestamp' => '2026-10-13T07:00:00Z',
                'payloadSchemaVersion' => 1, 'payload' => ['receiptId' => $this->receiptId, ...$payload],
            ]],
        ], 200, null, null, ['Idempotency-Key' => $batch]);
        self::assertSame('conflict', $response['results'][0]['status']);
        $this->assertBalance('6');
        self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM receipt_lines'));
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM client_operations'));
    }

    /** @return array<string, mixed> */
    private function purchaseBody(string $pack, string $quantity, string $perPack, int $revision): array
    {
        return [
            'id' => $this->id(), 'expectedReceiptRevision' => $revision, 'homeProductId' => $this->itemId,
            'packId' => $pack, 'rawDescription' => 'Apples', 'quantity' => $quantity,
            'originalPackText' => $perPack . ' kg bag', 'unitPrice' => '10', 'lineTotal' => null,
            'unit' => 'kg', 'stockQuantityPerPack' => $perPack,
        ];
    }

    private function purchasesPath(): string
    {
        return '/receipts/' . $this->receiptId . '/purchases';
    }

    /**
     * @param array<string, mixed>|null $body
     * @param array<string, string> $headers
     * @param array<string, string> $query
     * @return array<string, mixed>
     */
    private function request(
        string $method,
        string $path,
        ?array $body = null,
        int $status = 200,
        ?string $token = null,
        ?string $homeId = null,
        array $headers = [],
        array $query = [],
    ): array {
        $uri = 'http://localhost/api/v1/homes/' . ($homeId ?? $this->homeId) . $path;
        $request = new ServerRequest([], [], $uri, $method);
        $request = $request->withQueryParams($query)->withHeader('Accept', 'application/json');
        $token ??= $this->ownerToken;
        if ($token !== '') {
            $request = $request->withHeader('Authorization', 'Bearer ' . $token);
        }
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        if ($body !== null) {
            $request = $request->withHeader('Content-Type', 'application/json')->withBody(
                new StreamFactory()->createStream(json_encode($body, JSON_THROW_ON_ERROR)),
            );
        }
        $response = $this->app->handle($request);
        $text = (string) $response->getBody();
        self::assertSame($status, $response->getStatusCode(), $method . ' ' . $path . ': ' . $text);
        $data = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        return $data;
    }

    private function assertBalance(string $quantity): void
    {
        $stock = $this->request('GET', '/stock');
        self::assertCount(1, $stock['data']);
        self::assertSame($quantity, $stock['data'][0]['quantity']);
    }

    private function id(): string
    {
        return $this->container->get(UuidGenerator::class)->generate();
    }

    private function seedAccountsAndCatalog(): void
    {
        $now = new DateTimeImmutable();
        $access = $this->container->get(AccessStore::class);
        foreach (FeatureCatalog::defaults() as $group) {
            if ($access->group($group['id']) === null) {
                self::assertTrue($access->saveGroup($group, 0));
            }
        }
        $identity = $this->container->get(IdentityStore::class);
        $homes = $this->container->get(HomeStore::class);
        $auth = $this->container->get(AuthenticationService::class);
        $owner = $this->id();
        $outsider = $this->id();
        $this->homeId = $this->id();
        $this->otherHomeId = $this->id();
        foreach ([$owner => 'owner', $outsider => 'outsider'] as $id => $name) {
            $identity->createUser($id, $name . '@purchase.example.test', $name, 'en', 'UTC', $now);
            $identity->markEmailVerified($id, $now);
            self::assertTrue($access->assign('account', $id, FeatureCatalog::STARTER_ACCOUNT, 0));
        }
        foreach ([$this->homeId => $owner, $this->otherHomeId => $outsider] as $home => $user) {
            $homes->createHome($home, $user, 'Synthetic purchase home', 'en', 'NAD', 'UTC', $now);
            self::assertTrue($access->assign('home', $home, FeatureCatalog::STARTER_HOME, 0));
        }
        $this->deviceId = $this->id();
        $this->ownerToken = $auth->issueVerifiedSession(
            $owner,
            $this->deviceId,
            'Purchase device',
            'linux',
            'native',
            2592000,
            $this->homeId,
        )['accessToken'];
        $this->deviceId = $auth->authenticate($this->ownerToken)->deviceId;
        $this->secondDeviceToken = $auth->issueVerifiedSession(
            $owner,
            $this->id(),
            'Second purchase device',
            'linux',
            'native',
            2592000,
            $this->homeId,
        )['accessToken'];
        $this->outsiderToken = $auth->issueVerifiedSession(
            $outsider,
            $this->id(),
            'Isolated purchase device',
            'linux',
            'native',
            2592000,
            $this->otherHomeId,
        )['accessToken'];

        $base = ['status' => 'published', 'revision' => 1,
            'created_at' => $now->format('Y-m-d H:i:s'), 'updated_at' => $now->format('Y-m-d H:i:s')];
        $category = $this->id();
        $unit = $this->id();
        $this->productId = $this->id();
        $this->oneKgPackId = $this->id();
        $this->fourKgPackId = $this->id();
        $this->db->insert('categories', [
            ...$base, 'id' => $category, 'parent_id' => null,
            'canonical_name' => 'Fruit', 'normalized_name' => 'fruit',
        ]);
        $this->db->insert('units', [
            ...$base, 'id' => $unit, 'symbol' => 'kg', 'name' => 'Kilogram',
            'dimension' => 'mass', 'base_factor' => '1000',
        ]);
        $this->db->insert('products', [
            ...$base, 'id' => $this->productId, 'category_id' => $category,
            'canonical_name' => 'Apples', 'normalized_name' => 'apples', 'brand' => '', 'normalized_brand' => '',
        ]);
        foreach ([$this->oneKgPackId => 1, $this->fourKgPackId => 4] as $pack => $size) {
            $this->db->insert('product_packs', [
                ...$base, 'id' => $pack, 'product_id' => $this->productId, 'variant_id' => null,
                'unit_id' => $unit, 'source_key' => 'purchase-regression-' . $size,
                'original_pack_text' => $size . ' kg bag', 'amount' => (string) $size,
                'normalized_base_amount' => (string) ($size * 1000), 'multiplicity' => 1,
            ]);
        }
    }
}
