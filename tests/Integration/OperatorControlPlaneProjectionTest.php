<?php

declare(strict_types=1);

namespace ProvidentiaTest\Integration;

use DateTimeImmutable;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Logging\Middleware;
use PHPUnit\Framework\TestCase;
use Providentia\Administration\Application\OperatorAccountService;
use Providentia\Billing\Infrastructure\Doctrine\DbalBillingStore;
use Providentia\Home\Infrastructure\Doctrine\DbalHomeStore;
use Providentia\Identity\Application\AuthenticatedIdentity;
use Providentia\Identity\Application\PlatformRoleService;
use Providentia\Identity\Infrastructure\Doctrine\DbalIdentityStore;
use Providentia\SharedKernel\Application\Problem;
use Providentia\SharedKernel\Application\TransactionManager;
use ProvidentiaTest\Unit\Identity\IdentityFixedClock;

final class OperatorControlPlaneProjectionTest extends TestCase
{
    private const ACTOR_ID = '01912345-6789-7abc-8def-0123456789ab';
    private const TARGET_ID = '01912345-6789-7abc-9def-0123456789ab';
    private const THIRD_ID = '01912345-6789-7abc-adef-0123456789ab';
    private const HOME_ID = '01912345-6789-7abc-bdef-0123456789ab';

    private Connection $connection;
    private QueryCountLogger $queries;

    protected function setUp(): void
    {
        $this->queries = new QueryCountLogger();
        $configuration = new Configuration();
        $configuration->setMiddlewares([new Middleware($this->queries)]);
        $this->connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ], $configuration);
        foreach ($this->schema() as $statement) {
            $this->connection->executeStatement($statement);
        }
        $this->seed();
        $this->queries->reset();
    }

    public function testListBatchesRolesAndHomeCountsWithoutPerAccountQueries(): void
    {
        $page = $this->service()->list($this->administrator(), '', null, 50, 0);

        self::assertCount(3, $page['data']);
        self::assertSame(1, $page['data'][1]['homeCount']);
        self::assertSame(1, $page['data'][1]['activeSessionCount']);
        self::assertContains(PlatformRoleService::CATALOG_REVIEWER, $page['data'][1]['platformRoles']);
        self::assertLessThanOrEqual(
            4,
            $this->queries->count,
            'Account list queries must stay bounded as the page grows.',
        );
    }

    public function testSuspensionRevokesSessionsAndClosedAccountsStayTerminal(): void
    {
        $service = $this->service();
        $suspended = $service->updateStatus(
            $this->administrator(),
            self::TARGET_ID,
            'suspended',
            'Security review',
            1,
        );

        self::assertSame('suspended', $suspended['status']);
        self::assertSame(2, $suspended['revision']);
        self::assertSame(0, $suspended['activeSessionCount']);
        self::assertSame(0, (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM auth_sessions WHERE user_id = :user AND revoked_at IS NULL',
            ['user' => self::TARGET_ID],
        ));

        $service->updateStatus($this->administrator(), self::TARGET_ID, 'active', 'Review completed', 2);
        $closed = $service->updateStatus(
            $this->administrator(),
            self::TARGET_ID,
            'closed',
            'Owner requested closure',
            3,
        );
        self::assertSame('closed', $closed['status']);

        try {
            $service->updateStatus(
                $this->administrator(),
                self::TARGET_ID,
                'active',
                'Attempted reopening',
                4,
            );
            self::fail('A closed account was reopened.');
        } catch (Problem $problem) {
            self::assertSame(409, $problem->status);
            self::assertSame('Closed account', $problem->title);
        }
    }

    public function testFinalAdministratorCannotBeSuspended(): void
    {
        $this->expectException(Problem::class);
        $this->expectExceptionMessage('Grant another active administrator');
        $this->service()->updateStatus(
            $this->administrator(),
            self::ACTOR_ID,
            'suspended',
            'Unsafe self suspension',
            1,
        );
    }

    public function testSameStatusRequestKeepsRevisionSessionsAndAuditUnchanged(): void
    {
        $detail = $this->service()->updateStatus(
            $this->administrator(),
            self::TARGET_ID,
            'active',
            'Confirm current state',
            1,
        );

        self::assertSame(1, $detail['revision']);
        self::assertSame(3, (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM auth_sessions WHERE user_id = :user AND revoked_at IS NULL',
            ['user' => self::TARGET_ID],
        ));
        self::assertSame(0, (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM audit_events WHERE target_id = :user',
            ['user' => self::TARGET_ID],
        ));
    }

    public function testInactiveAdministratorRoleCanBeRevokedWithoutBlockingTheActiveAdministrator(): void
    {
        $service = $this->service();
        $service->grantRole(
            $this->administrator(),
            self::TARGET_ID,
            PlatformRoleService::ADMINISTRATOR,
            1,
        );
        $service->updateStatus(
            $this->administrator(),
            self::TARGET_ID,
            'suspended',
            'Security review',
            2,
        );

        $detail = $service->revokeRole(
            $this->administrator(),
            self::TARGET_ID,
            PlatformRoleService::ADMINISTRATOR,
            3,
        );

        self::assertSame(4, $detail['revision']);
        self::assertNotContains(PlatformRoleService::ADMINISTRATOR, $detail['platformRoles']);
    }

    public function testDetailComposesOnlyAllowlistedHomeAndSubscriptionMetadata(): void
    {
        $secondHomeId = '01912345-6789-7abc-cdef-0123456789ab';
        $this->connection->insert('homes', ['id' => $secondHomeId, 'name' => 'Second Home']);
        $this->connection->insert('home_memberships', [
            'home_id' => $secondHomeId,
            'user_id' => self::TARGET_ID,
            'role' => 'owner',
            'status' => 'active',
        ]);
        $this->connection->insert('billing_subscriptions', [
            'id' => 'subscription-2',
            'home_id' => $secondHomeId,
            'plan_id' => 'plan-1',
            'price_id' => 'price-1',
            'status' => 'active',
            'current_period_ends_at' => null,
        ]);
        $this->queries->reset();

        $detail = $this->service()->get($this->administrator(), self::TARGET_ID);

        self::assertSame(2, $detail['homeCount']);
        self::assertSame([
            [
                'homeId' => self::HOME_ID,
                'name' => 'Household',
                'membershipRole' => 'member',
                'membershipStatus' => 'active',
                'subscription' => [
                    'status' => 'active',
                    'planCode' => 'free-stabilization',
                    'billingCycle' => 'month',
                    'currentPeriodEnd' => '2026-09-24T12:00:00Z',
                ],
            ],
            [
                'homeId' => $secondHomeId,
                'name' => 'Second Home',
                'membershipRole' => 'owner',
                'membershipStatus' => 'active',
                'subscription' => [
                    'status' => 'active',
                    'planCode' => 'free-stabilization',
                    'billingCycle' => 'month',
                    'currentPeriodEnd' => null,
                ],
            ],
        ], $detail['homes']);
        $encoded = json_encode($detail, JSON_THROW_ON_ERROR);
        foreach (['receipt', 'stock', 'location', 'price', 'providerReference'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $encoded);
        }
        self::assertLessThanOrEqual(
            4,
            $this->queries->count,
            'A single detail view should make one query per owning module projection.',
        );
    }

    public function testStatusCasFailureDoesNotRevokeSessionsOrWriteAudit(): void
    {
        $this->connection->executeStatement(
            "CREATE TRIGGER reject_status_cas BEFORE UPDATE OF revision ON users
             WHEN OLD.id = '" . self::TARGET_ID . "'
             BEGIN SELECT RAISE(IGNORE); END",
        );

        try {
            $this->service()->updateStatus(
                $this->administrator(),
                self::TARGET_ID,
                'suspended',
                'Concurrent update simulation',
                1,
            );
            self::fail('The ignored revision update was reported as successful.');
        } catch (Problem $problem) {
            self::assertSame(409, $problem->status);
        }
        self::assertSame(3, (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM auth_sessions WHERE user_id = :user AND revoked_at IS NULL',
            ['user' => self::TARGET_ID],
        ));
        self::assertSame(0, (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM audit_events WHERE target_id = :user',
            ['user' => self::TARGET_ID],
        ));
    }

    public function testRoleCasFailureDoesNotCreateTheRoleOrAudit(): void
    {
        $this->connection->executeStatement(
            "CREATE TRIGGER reject_role_cas BEFORE UPDATE OF revision ON users
             WHEN OLD.id = '" . self::TARGET_ID . "'
             BEGIN SELECT RAISE(IGNORE); END",
        );

        try {
            $this->service()->grantRole(
                $this->administrator(),
                self::TARGET_ID,
                PlatformRoleService::BILLING_OPERATOR,
                1,
            );
            self::fail('The ignored revision update was reported as successful.');
        } catch (Problem $problem) {
            self::assertSame(409, $problem->status);
        }
        self::assertSame(0, (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM user_platform_roles WHERE user_id = :user AND role = :role',
            ['user' => self::TARGET_ID, 'role' => PlatformRoleService::BILLING_OPERATOR],
        ));
        self::assertSame(0, (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM audit_events WHERE target_id = :user',
            ['user' => self::TARGET_ID],
        ));
    }

    public function testNoOpRoleGrantKeepsTheAccountRevisionAndDoesNotAudit(): void
    {
        $detail = $this->service()->grantRole(
            $this->administrator(),
            self::TARGET_ID,
            PlatformRoleService::CATALOG_REVIEWER,
            1,
        );

        self::assertSame(1, $detail['revision']);
        self::assertSame(0, (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM audit_events WHERE target_id = :user',
            ['user' => self::TARGET_ID],
        ));
    }

    public function testNoOpRoleRevokeKeepsTheAccountRevisionAndDoesNotAudit(): void
    {
        $detail = $this->service()->revokeRole(
            $this->administrator(),
            self::TARGET_ID,
            PlatformRoleService::BILLING_OPERATOR,
            1,
        );

        self::assertSame(1, $detail['revision']);
        self::assertNotContains(PlatformRoleService::BILLING_OPERATOR, $detail['platformRoles']);
        self::assertSame(0, (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM audit_events WHERE target_id = :user',
            ['user' => self::TARGET_ID],
        ));
    }

    public function testEmailInvitationAndAccountRolePathsShareTheAccountRevision(): void
    {
        $identities = new DbalIdentityStore($this->connection);
        $at = new DateTimeImmutable('2026-08-24T12:00:00+00:00');
        $grant = $this->connection->transactional(fn (): array =>
            $identities->grantPlatformAdministrator(
                '01912345-6789-7abc-8def-000000000101',
                '01912345-6789-7abc-8def-000000000102',
                self::ACTOR_ID,
                'person@example.test',
                $at,
            ));

        self::assertTrue($grant['changed']);
        self::assertSame(2, (int) $this->connection->fetchOne(
            'SELECT revision FROM users WHERE id = :user',
            ['user' => self::TARGET_ID],
        ));
        try {
            $this->service()->grantRole(
                $this->administrator(),
                self::TARGET_ID,
                PlatformRoleService::BILLING_OPERATOR,
                1,
            );
            self::fail('An account snapshot survived an email-path role grant.');
        } catch (Problem $problem) {
            self::assertSame(409, $problem->status);
        }

        $detail = $this->service()->grantRole(
            $this->administrator(),
            self::TARGET_ID,
            PlatformRoleService::BILLING_OPERATOR,
            2,
        );
        self::assertSame(3, $detail['revision']);

        $revoked = $this->connection->transactional(fn (): string =>
            $identities->revokePlatformAdministrator(
                '01912345-6789-7abc-8def-000000000103',
                self::ACTOR_ID,
                self::TARGET_ID,
                1,
                $at,
            ));
        self::assertSame('revoked', $revoked);
        self::assertSame(4, (int) $this->connection->fetchOne(
            'SELECT revision FROM users WHERE id = :user',
            ['user' => self::TARGET_ID],
        ));
        try {
            $this->service()->revokeRole(
                $this->administrator(),
                self::TARGET_ID,
                PlatformRoleService::BILLING_OPERATOR,
                3,
            );
            self::fail('An account snapshot survived an email-path role revocation.');
        } catch (Problem $problem) {
            self::assertSame(409, $problem->status);
        }
    }

    private function service(): OperatorAccountService
    {
        $identities = new DbalIdentityStore($this->connection);
        $clock = new IdentityFixedClock(new DateTimeImmutable('2026-08-24T12:00:00+00:00'));
        $transactions = new class ($this->connection) implements TransactionManager {
            public function __construct(private readonly Connection $connection)
            {
            }

            public function transactional(callable $operation): mixed
            {
                return $this->connection->transactional(static fn (): mixed => $operation());
            }
        };

        return new OperatorAccountService(
            $identities,
            $identities,
            new DbalHomeStore($this->connection),
            new DbalBillingStore($this->connection),
            new PlatformRoleService($identities, new SequenceUuidGenerator(), $clock, $transactions),
            new SequenceUuidGenerator(),
            $clock,
            $transactions,
        );
    }

    private function administrator(): AuthenticatedIdentity
    {
        return new AuthenticatedIdentity(
            self::ACTOR_ID,
            'session',
            'device',
            null,
            [PlatformRoleService::ADMINISTRATOR],
        );
    }

    /** @return list<string> */
    private function schema(): array
    {
        return [
            'CREATE TABLE users (
                id TEXT PRIMARY KEY, email TEXT, normalized_email TEXT, email_verified_at TEXT NULL,
                status TEXT, revision INTEGER, created_at TEXT, updated_at TEXT,
                status_changed_at TEXT NULL, suspended_at TEXT NULL, closed_at TEXT NULL
            )',
            'CREATE TABLE user_profiles (user_id TEXT PRIMARY KEY, display_name TEXT)',
            'CREATE TABLE devices (id TEXT PRIMARY KEY, user_id TEXT, revoked_at TEXT NULL)',
            'CREATE TABLE auth_sessions (
                id TEXT PRIMARY KEY, user_id TEXT, device_id TEXT,
                refresh_expires_at TEXT, revoked_at TEXT NULL
            )',
            'CREATE TABLE user_platform_roles (
                user_id TEXT, role TEXT, granted_at TEXT NULL, revoked_at TEXT NULL,
                granted_by_user_id TEXT NULL, source TEXT NULL, revision INTEGER DEFAULT 1,
                updated_at TEXT,
                PRIMARY KEY (user_id, role)
            )',
            'CREATE TABLE platform_administrator_email_grants (
                id TEXT PRIMARY KEY, normalized_email TEXT UNIQUE, status TEXT, source TEXT,
                revision INTEGER, granted_by_user_id TEXT NULL, accepted_by_user_id TEXT NULL,
                accepted_at TEXT NULL, revoked_at TEXT NULL, created_at TEXT, updated_at TEXT
            )',
            'CREATE TABLE audit_events (
                id TEXT PRIMARY KEY, home_id TEXT NULL, actor_user_id TEXT NULL,
                action TEXT, target_type TEXT, target_id TEXT, details TEXT, occurred_at TEXT
            )',
            'CREATE TABLE homes (id TEXT PRIMARY KEY, name TEXT)',
            'CREATE TABLE home_memberships (home_id TEXT, user_id TEXT, role TEXT, status TEXT)',
            'CREATE TABLE billing_plans (id TEXT PRIMARY KEY, code TEXT)',
            'CREATE TABLE billing_prices (id TEXT PRIMARY KEY, interval_unit TEXT)',
            'CREATE TABLE billing_subscriptions (
                id TEXT PRIMARY KEY, home_id TEXT, plan_id TEXT, price_id TEXT,
                status TEXT, current_period_ends_at TEXT NULL
            )',
        ];
    }

    private function seed(): void
    {
        foreach (
            [
                [self::ACTOR_ID, 'admin@example.test', 'Admin', '2026-08-03 12:00:00'],
                [self::TARGET_ID, 'person@example.test', 'Person', '2026-08-02 12:00:00'],
                [self::THIRD_ID, 'third@example.test', 'Third', '2026-08-01 12:00:00'],
            ] as [$id, $email, $displayName, $createdAt]
        ) {
            $this->connection->insert('users', [
                'id' => $id,
                'email' => $email,
                'normalized_email' => $email,
                'email_verified_at' => $createdAt,
                'status' => 'active',
                'revision' => 1,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
                'status_changed_at' => null,
                'suspended_at' => null,
                'closed_at' => null,
            ]);
            $this->connection->insert('user_profiles', ['user_id' => $id, 'display_name' => $displayName]);
        }
        $this->connection->insert('user_platform_roles', [
            'user_id' => self::ACTOR_ID,
            'role' => PlatformRoleService::ADMINISTRATOR,
            'revoked_at' => null,
            'updated_at' => '2026-08-01 12:00:00',
        ]);
        $this->connection->insert('user_platform_roles', [
            'user_id' => self::TARGET_ID,
            'role' => PlatformRoleService::CATALOG_REVIEWER,
            'revoked_at' => null,
            'updated_at' => '2026-08-01 12:00:00',
        ]);
        $this->connection->insert('devices', [
            'id' => 'device-active',
            'user_id' => self::TARGET_ID,
            'revoked_at' => null,
        ]);
        $this->connection->insert('devices', [
            'id' => 'device-revoked',
            'user_id' => self::TARGET_ID,
            'revoked_at' => '2026-08-23 12:00:00',
        ]);
        $this->connection->insert('devices', [
            'id' => 'device-expired',
            'user_id' => self::TARGET_ID,
            'revoked_at' => null,
        ]);
        $this->connection->insert('auth_sessions', [
            'id' => 'session-active',
            'user_id' => self::TARGET_ID,
            'device_id' => 'device-active',
            'refresh_expires_at' => '2026-09-24 12:00:00',
            'revoked_at' => null,
        ]);
        $this->connection->insert('auth_sessions', [
            'id' => 'session-revoked-device',
            'user_id' => self::TARGET_ID,
            'device_id' => 'device-revoked',
            'refresh_expires_at' => '2026-09-24 12:00:00',
            'revoked_at' => null,
        ]);
        $this->connection->insert('auth_sessions', [
            'id' => 'session-expired',
            'user_id' => self::TARGET_ID,
            'device_id' => 'device-expired',
            'refresh_expires_at' => '2026-08-23 12:00:00',
            'revoked_at' => null,
        ]);
        $this->connection->insert('homes', ['id' => self::HOME_ID, 'name' => 'Household']);
        $this->connection->insert('home_memberships', [
            'home_id' => self::HOME_ID,
            'user_id' => self::TARGET_ID,
            'role' => 'member',
            'status' => 'active',
        ]);
        $this->connection->insert('billing_plans', ['id' => 'plan-1', 'code' => 'free-stabilization']);
        $this->connection->insert('billing_prices', ['id' => 'price-1', 'interval_unit' => 'month']);
        $this->connection->insert('billing_subscriptions', [
            'id' => 'subscription-1',
            'home_id' => self::HOME_ID,
            'plan_id' => 'plan-1',
            'price_id' => 'price-1',
            'status' => 'active',
            'current_period_ends_at' => '2026-09-24 12:00:00',
        ]);
    }
}
