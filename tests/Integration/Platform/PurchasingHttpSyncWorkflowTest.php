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
use Providentia\Identity\Application\LoginApplicationKind;
use Providentia\Identity\Http\SessionResponseFactory;
use Providentia\Identity\Http\WebSessionContext;
use Providentia\SharedKernel\Application\TransactionManager;
use Providentia\SharedKernel\Application\UuidGenerator;
use Providentia\SharedKernel\Infrastructure\Logging\StderrJsonLogger;
use Psr\Log\LoggerInterface;

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
    /** @var list<string> */
    private array $diagnostics = [];

    protected function setUp(): void
    {
        $this->db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $migrations = DependencyFactory::fromConnection(
            new ConfigurationArray(require dirname(__DIR__, 3) . '/config/migrations.php'),
            new ExistingConnection($this->db),
        );
        $migrations->getMetadataStorage()->ensureInitialized();
        $plan = $migrations->getMigrationPlanCalculator()->getPlanUntilVersion(
            new Version('Providentia\Migrations\Version20261026000100'),
        );
        $migrations->getMigrator()->migrate($plan, new MigratorConfiguration());
        $this->container = require dirname(__DIR__, 3) . '/config/container.php';
        $this->container->setAllowOverride(true);
        $this->container->setService(Connection::class, $this->db);
        // Expected HTTP rejections must not reach the process error channel:
        // Infection stops its initial suite on any stderr output. Keep the real
        // logger and verify its serialized diagnostics through an isolated sink.
        $this->diagnostics = [];
        $this->container->setService(LoggerInterface::class, new StderrJsonLogger(function (string $line): void {
            $this->diagnostics[] = $line;
        }));
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
        // Simulate a lost response: inspect the original execution receipt before
        // deciding whether the immutable purchase should be transmitted again.
        $accountId = $this->container->get(AuthenticationService::class)->authenticate($this->ownerToken)->userId;
        $recovery = [
            'accountId' => $accountId, 'deviceId' => $this->deviceId,
            'operations' => [['blockedCode' => 'device_binding_mismatch', 'command' => $envelope['operations'][0]]],
        ];
        $revalidated = $this->request('POST', '/sync/revalidate', $recovery);
        self::assertSame('readback', $revalidated['operations'][0]['action']);
        self::assertSame($second['results'][0], $revalidated['operations'][0]['result']);
        self::assertSame($revalidated, $this->request('POST', '/sync/revalidate', $recovery));
        $this->request('POST', '/sync/revalidate', $recovery, 403, $this->secondDeviceToken);
        $this->assertBalance('10');
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
        $this->assertDiagnostics([403]);
    }

    public function testFinishedReceiptCorrectionRoutesAndTypedSyncApplyOnlyNewPurchasesWithAuditedReadback(): void
    {
        $applied = $this->request(
            'POST',
            $this->purchasesPath(),
            $this->purchaseBody($this->oneKgPackId, '4', '1', 1),
            201
        );
        $pending = $this->request('POST', '/receipts/' . $this->receiptId . '/lines', [
            'expectedReceiptRevision' => 2, 'rawDescription' => 'Pending apples', 'quantity' => '1',
            'originalPackText' => null, 'unitPrice' => null, 'lineTotal' => '10',
        ], 201);
        $this->request('POST', '/receipts/' . $this->receiptId . '/lines/' . $pending['id'] . '/unresolve', [
            'expectedRevision' => $pending['revision'],
        ]);
        $this->request('POST', '/receipts/' . $this->receiptId . '/commit', ['expectedRevision' => 4]);
        $body = [
            'correctionId' => $this->id(), 'lineId' => $pending['id'], 'expectedReceiptRevision' => 5,
            'expectedLineRevision' => 2, 'reason' => 'Confirmed pending purchase',
            'homeProductId' => $this->itemId, 'packId' => $this->oneKgPackId,
            'rawDescription' => 'Apples reviewed', 'quantity' => '1.5', 'originalPackText' => '1 kg bag',
            'unitPrice' => '10', 'lineTotal' => '14', 'unit' => 'kg', 'stockQuantityPerPack' => '1',
        ];
        $path = '/receipts/' . $this->receiptId . '/line-corrections';
        $this->request('POST', $path, $body, 401, '');
        $this->request('POST', $path, $body, 404, $this->outsiderToken);
        $resolved = $this->request('POST', $path, $body, 201);
        self::assertSame('1.5', $resolved['stockQuantity']);
        self::assertSame(6, $resolved['receiptRevision']);
        self::assertSame($resolved, $this->request('POST', $path, $body, 201));
        $this->assertBalance('7.5');
        $missing = $this->id();
        $batchId = $this->id();
        $payload = array_diff_key($body, ['lineId' => true, 'expectedReceiptRevision' => true]);
        $payload = [...$payload, 'receiptId' => $this->receiptId, 'correctionId' => $this->id(),
            'expectedLineRevision' => null, 'quantity' => '0.5'];
        $envelope = [
            'protocolVersion' => 2, 'batchId' => $batchId, 'deviceId' => $this->deviceId,
            'lastPulledCursor' => null, 'operations' => [[
                'operationId' => $this->id(), 'commandType' => 'purchasing.receipt-line.correct',
                'entityId' => $missing, 'baseRevision' => 6, 'clientTimestamp' => '2026-10-13T07:00:00Z',
                'payloadSchemaVersion' => 1, 'payload' => $payload,
            ]],
        ];
        $headers = ['Idempotency-Key' => $batchId];
        $result = $this->request('POST', '/sync/push', $envelope, 200, null, null, $headers);
        self::assertSame('accepted', $result['results'][0]['status']);
        self::assertSame($missing, $result['results'][0]['result']['id']);
        self::assertSame(7, $result['results'][0]['result']['receiptRevision']);
        self::assertSame(
            $result['results'],
            $this->request('POST', '/sync/push', $envelope, 200, null, null, $headers)['results']
        );
        $this->assertBalance('8');
        $receipt = $this->request('GET', '/receipts/' . $this->receiptId);
        self::assertSame('committed', $receipt['status']);
        self::assertSame('available', $receipt['lineCorrectionState']);
        self::assertSame(1, $receipt['lines'][0]['revision']);
        self::assertSame($applied['id'], $receipt['lines'][0]['id']);
        $audits = $this->request('GET', $path)['data'];
        self::assertCount(2, $audits);
        self::assertSame('unresolved', $audits[0]['originalLine']['approvalStatus']);
        self::assertNull($audits[1]['originalLine']);
        $bootstrap = $this->request('GET', '/sync/bootstrap', null, 200, $this->secondDeviceToken);
        $corrections = array_values(array_filter(
            $bootstrap['records'],
            static fn (array $record): bool => $record['entityType'] === 'purchasing-receipt-line-correction'
        ));
        self::assertCount(2, $corrections);
        self::assertArrayNotHasKey('requestHash', $corrections[0]['representation']);
        self::assertSame(3, (int) $this->db->fetchOne("SELECT COUNT(*) FROM stock_movements
            WHERE movement_type = 'purchase-in'"));
        $this->assertDiagnostics([401, 404]);
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
        $this->assertDiagnostics([401, 404, 404]);
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
        $this->assertDiagnostics([409]);
    }

    public function testRealWebSessionsRemainIsolatedAcrossSharedBackendCookieJarAndRefresh(): void
    {
        $authentication = $this->container->get(AuthenticationService::class);
        $users = [
            'homeowner' => $authentication->authenticate($this->ownerToken)->userId,
            'admin' => $authentication->authenticate($this->outsiderToken)->userId,
        ];
        $sessions = [];
        $cookies = [];
        foreach ([LoginApplicationKind::HOMEOWNER, LoginApplicationKind::ADMIN] as $application) {
            $sessions[$application->value] = $authentication->issueVerifiedSession(
                $users[$application->value],
                $this->id(),
                'Web app',
                'web',
                'web',
                2592000,
                null,
            );
            $this->mergeWebCookies($cookies, SessionResponseFactory::web(
                $sessions[$application->value],
                false,
                $application,
            )->getHeader('Set-Cookie'));
        }
        self::assertCount(6, $cookies);
        foreach ([LoginApplicationKind::HOMEOWNER, LoginApplicationKind::ADMIN] as $application) {
            $response = $this->app->handle(new ServerRequest([], [], 'http://localhost/api/v1/auth/sessions', 'GET')
                ->withHeader(WebSessionContext::HEADER, $application->value)->withCookieParams($cookies));
            self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
            $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            $current = array_values(array_filter($data['data'], static fn (array $row): bool => $row['current']));
            self::assertCount(1, $current);
            self::assertSame($sessions[$application->value]['deviceId'], $current[0]['deviceId']);
            self::assertSame($sessions[$application->value]['installationId'], $current[0]['installationId']);

            $refresh = $this->app->handle(new ServerRequest([], [], 'http://localhost/api/v1/auth/refresh', 'POST')
                ->withHeader(WebSessionContext::HEADER, $application->value)
                ->withHeader('X-CSRF-Token', $cookies[WebSessionContext::cookie('csrf', $application)])
                ->withCookieParams($cookies));
            self::assertSame(200, $refresh->getStatusCode(), (string) $refresh->getBody());
            foreach ($refresh->getHeader('Set-Cookie') as $cookie) {
                self::assertStringStartsWith('providentia_' . $application->value . '_', $cookie);
            }
            $this->mergeWebCookies($cookies, $refresh->getHeader('Set-Cookie'));
        }
        $logout = $this->app->handle(new ServerRequest([], [], 'http://localhost/api/v1/auth/logout', 'POST')
            ->withHeader(WebSessionContext::HEADER, 'admin')
            ->withHeader('X-CSRF-Token', $cookies['providentia_admin_csrf'])->withCookieParams($cookies));
        self::assertSame(204, $logout->getStatusCode(), (string) $logout->getBody());
        $this->mergeWebCookies($cookies, $logout->getHeader('Set-Cookie'));
        $stillHome = $this->app->handle(new ServerRequest([], [], 'http://localhost/api/v1/auth/sessions', 'GET')
            ->withHeader(WebSessionContext::HEADER, 'homeowner')->withCookieParams($cookies));
        self::assertSame(200, $stillHome->getStatusCode(), (string) $stillHome->getBody());
        $this->assertDiagnostics([]);
    }

    public function testCorrectedSubmittedReceiptDateSurvivesSyncReplayAndUpdatesAuthoritativeHistory(): void
    {
        $this->request('POST', $this->purchasesPath(), $this->purchaseBody($this->oneKgPackId, '4', '1', 1), 201);
        $this->request('POST', '/receipts/' . $this->receiptId . '/commit', ['expectedRevision' => 2]);
        $receipt = $this->request('GET', '/receipts/' . $this->receiptId);
        $batch = $this->id();
        $command = [
            'operationId' => $this->id(), 'commandType' => 'purchasing.receipt.correct-date',
            'entityId' => $this->receiptId, 'baseRevision' => $receipt['revision'],
            'clientTimestamp' => '2026-10-22T10:00:00Z', 'payloadSchemaVersion' => 1,
            'payload' => ['purchaseDate' => '2026-10-12', 'reason' => 'Checked the original receipt'],
        ];
        $envelope = [
            'protocolVersion' => 2, 'batchId' => $batch, 'deviceId' => $this->deviceId,
            'lastPulledCursor' => null, 'operations' => [$command],
        ];
        $first = $this->request('POST', '/sync/push', $envelope, 200, null, null, ['Idempotency-Key' => $batch]);
        self::assertSame('accepted', $first['results'][0]['status']);
        $replay = $this->request('POST', '/sync/push', $envelope, 200, null, null, ['Idempotency-Key' => $batch]);
        self::assertSame($first['results'], $replay['results']);
        $receipt = $this->request('GET', '/receipts/' . $this->receiptId);
        self::assertSame('2026-10-12', $receipt['purchaseDate']);
        self::assertSame('committed', $receipt['status']);
        $history = $this->request('GET', '/receipts/' . $this->receiptId . '/date-corrections');
        self::assertCount(1, $history['data']);
        self::assertSame('2026-10-13', $history['data'][0]['originalPurchaseDate']);
        self::assertSame('2026-10-12', $history['data'][0]['purchaseDate']);
        $this->assertBalance('6');
        self::assertSame(2, (int) $this->db->fetchOne('SELECT COUNT(*) FROM price_observations'));
        self::assertSame(1, (int) $this->db->fetchOne(
            'SELECT COUNT(*) FROM price_observations WHERE superseded_at IS NULL',
        ));
        self::assertStringStartsWith(
            '2026-10-12',
            (string) $this->db->fetchOne('SELECT observed_at FROM price_observations WHERE superseded_at IS NULL'),
        );
        self::assertSame(1, (int) $this->db->fetchOne(
            "SELECT COUNT(*) FROM stock_movements WHERE movement_type = 'purchase-in'",
        ));
        $this->assertDiagnostics([]);
    }

    public function testAuthenticatedDiagnosticsReportActualContractAndMigrationStateWithoutHouseholdData(): void
    {
        $information = $this->request('GET', '/sync/diagnostics');
        self::assertSame($this->homeId, $information['homeId']);
        self::assertSame($this->deviceId, $information['deviceId']);
        $contract = dirname(__DIR__, 3) . '/contracts/openapi/providentia-v1.json';
        $expectedHash = is_file($contract) ? hash_file('sha256', $contract) : null;
        self::assertSame($expectedHash, $information['deployment']['contractHash']);
        // A clean checkout need not have materialized the canonical contract yet.
        // Missing deployment evidence must be explicit, never a warning or invented hash.
        self::assertSame($expectedHash !== null, $information['deployment']['contractMatchesLock']);
        self::assertSame('current', $information['deployment']['migrationStatus']);
        self::assertSame([], $information['deployment']['pendingMigrations']);
        self::assertStringNotContainsString('accessToken', json_encode($information, JSON_THROW_ON_ERROR));
        $this->request('GET', '/sync/diagnostics', null, 404, $this->outsiderToken);
        $this->assertDiagnostics([404]);
    }

    /**
     * @param array<string, string> $cookies
     * @param array<array-key, string> $headers
     */
    private function mergeWebCookies(array &$cookies, array $headers): void
    {
        foreach ($headers as $header) {
            [$name, $value] = explode('=', explode(';', $header)[0], 2);
            if (str_contains($header, 'Max-Age=0;')) {
                unset($cookies[$name]);
            } else {
                $cookies[$name] = rawurldecode($value);
            }
        }
    }

    /** @param list<int> $expectedStatuses */
    private function assertDiagnostics(array $expectedStatuses): void
    {
        $statuses = [];
        foreach ($this->diagnostics as $line) {
            $diagnostic = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($diagnostic);
            self::assertSame('warning', $diagnostic['level']);
            self::assertSame('HTTP request rejected.', $diagnostic['message']);
            self::assertSame('http.problem', $diagnostic['context']['event']);
            $statuses[] = $diagnostic['context']['status'];
        }
        self::assertSame($expectedStatuses, $statuses);
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
