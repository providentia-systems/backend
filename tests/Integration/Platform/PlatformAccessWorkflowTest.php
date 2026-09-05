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
use Laminas\ServiceManager\ServiceManager;
use PHPUnit\Framework\TestCase;
use Providentia\Access\Application\AccessService;
use Providentia\Access\Domain\FeatureCatalog;
use Providentia\Administration\Application\OperatorWorkspaceService;
use Providentia\Geography\Application\CountryService;
use Providentia\Home\Application\HomeAuthorization;
use Providentia\Home\Application\HomeService;
use Providentia\Identity\Application\AccountProfileService;
use Providentia\Identity\Application\AuthenticatedIdentity;
use Providentia\Identity\Application\AuthenticationService;
use Providentia\Identity\Application\EmailCodeService;
use Providentia\Identity\Application\EmailLoginService;
use Providentia\Identity\Application\NotificationOutbox;
use Providentia\Identity\Infrastructure\Cli\SystemOwnerCommand;
use Providentia\SharedKernel\Application\Problem;
use Providentia\SharedKernel\Application\TransactionManager;
use Symfony\Component\Console\Tester\CommandTester;

final class PlatformAccessWorkflowTest extends TestCase
{
    private Connection $db;
    private ServiceManager $container;
    protected function setUp(): void
    {
        $this->db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $config = require dirname(__DIR__, 3) . '/config/migrations.php';
        $migrations = DependencyFactory::fromConnection(
            new ConfigurationArray($config),
            new ExistingConnection($this->db),
        );
        $migrations->getMetadataStorage()
            ->ensureInitialized();
        $plan = $migrations->getMigrationPlanCalculator()
            ->getPlanUntilVersion(
                new Version('Providentia\Migrations\Version20260905000100'),
            );
        $migrations->getMigrator()
            ->migrate($plan, new MigratorConfiguration());
        $this->container = require dirname(__DIR__, 3) . '/config/container.php';
        $this->container->setAllowOverride(true);
        $this->container->setService(Connection::class, $this->db);
        $connection = $this->db;
        $transactions = $this->createStub(TransactionManager::class);
        $transactions->method('transactional')
            ->willReturnCallback(
                static fn(callable $operation): mixed => $connection->transactional(static fn(): mixed => $operation()),
            );
        $this->container->setService(TransactionManager::class, $transactions);
    }

    public function testCodeIsBoundSingleUseAndNeverStoredAsPlaintext(): void
    {
        [$challenge, $code] = $this->challenge('person@example.test');
        self::assertMatchesRegularExpression('/^[0-9]{8}$/', $code);
        $row = $this->db->fetchAssociative(
            'SELECT * FROM email_code_challenges WHERE id = ?',
            [$challenge['challengeId']],
        );
        self::assertIsArray($row);
        self::assertNotSame($code, $row['code_hash']);
        self::assertNotSame($challenge['bindingToken'], $row['binding_hash']);
        self::assertArrayNotHasKey('code', $challenge);
        $this->problem(
            422,
            fn() => $this->loginService()
                ->verify(
                    [
                    ...$challenge,
                    'bindingToken' => str_repeat('x', 43),
                    'code' => $code,
                    ],
                    '192.0.2.2',
                ),
        );
        $grant = $this->loginService()
            ->verify([...$challenge, 'code' => $code], '192.0.2.2');
        self::assertNotEmpty($grant['accessToken']);
        $this->problem(
            422,
            fn() => $this->loginService()
                ->verify([...$challenge, 'code' => $code], '192.0.2.2'),
        );
        $this->problem(
            429,
            fn() => $this->challenge('person@example.test'),
        );
    }

    public function testFiveWrongAttemptsInvalidateTheChallenge(): void
    {
        [$challenge, $code] = $this->challenge('limited@example.test');
        $wrong = $code === '00000000'
            ? '00000001'
            : '00000000';
        for ($i = 0; $i < 5; $i++) {
            $this->problem(
                422,
                fn() => $this->loginService()
                    ->verify([...$challenge, 'code' => $wrong], '192.0.2.3'),
            );
        }
        $this->problem(
            422,
            fn() => $this->loginService()
                ->verify([...$challenge, 'code' => $code], '192.0.2.3'),
        );
        self::assertSame(
            0,
            (int) $this->db->fetchOne('SELECT COUNT(*) FROM users'),
        );
    }

    public function testInvitationAllowanceDowngradesKeepMembershipAndBlockFurtherAdditions(): void
    {
        $admin = $this->systemOwner();
        $owner = $this->login('homeowner@example.test');
        $this->onboard($owner);
        $home = $this->homes()
            ->create(
                $owner,
                'First home',
                'en-NA',
                'NAD',
                'Africa/Windhoek',
            );
        self::assertContains('inventory.write', $home['effectivePermissions']);
        $id = $home['id'];
        $this->problem(
            409,
            fn() => $this->homes()
                ->create($owner, 'Second', 'en', 'NAD', 'Africa/Windhoek'),
        );
        $this->problem(
            404,
            fn() => $this->homes()
                ->invite($owner, $id, 'member@example.test', 'member'),
        );
        $group = $this->homeGroup($admin, true, 3);
        $this->access()
            ->assign($admin, 'home', $id, $group['id'], 1);
        $invitation = $this->homes()
            ->invite($owner, $id, 'member@example.test', 'member');
        $member = $this->login('member@example.test');
        $profile = $this->onboard($member);
        self::assertSame(
            0,
            $profile['accountAccess']['limits']['homes.owned'],
        );
        $this->homes()
            ->acceptInvitationById($member, $invitation['invitationId'], 1);
        $this->homes()
            ->acceptInvitationById($member, $invitation['invitationId'], 1);
        self::assertCount(
            2,
            $this->db->fetchAllAssociative(
                'SELECT * FROM home_memberships WHERE home_id = ?',
                [$id],
            ),
        );
        $group['features']['members.invite'] = false;
        $group['limits']['members.total'] = 1;
        $group['expectedRevision'] = $group['revision'];
        $this->access()
            ->saveGroup($admin, $group['id'], $group);
        $this->container->get(HomeAuthorization::class)
            ->requirePermission(
                $member,
                $id,
                'inventory.read',
            );
        $this->problem(
            404,
            fn() => $this->homes()
                ->invite($owner, $id, 'other@example.test', 'member'),
        );
        self::assertSame(
            2,
            (int) $this->db->fetchOne(
                'SELECT COUNT(*) FROM home_memberships WHERE home_id = ?',
                [$id],
            ),
        );
    }

    public function testPendingAcceptanceRechecksSenderPermissionsAndIndividualOverrides(): void
    {
        $admin = $this->systemOwner();
        $owner = $this->login('owner@example.test');
        $this->onboard($owner);
        $home = $this->homes()
            ->create(
                $owner,
                'Permission home',
                'en',
                'NAD',
                'Africa/Windhoek',
            );
        $group = $this->homeGroup($admin, true, 4);
        $id = $home['id'];
        $this->access()
            ->assign($admin, 'home', $id, $group['id'], 1);
        $invitation = $this->homes()
            ->invite($owner, $id, 'manager@example.test', 'manager');
        $manager = $this->login('manager@example.test');
        $this->onboard($manager);
        $this->homes()
            ->acceptInvitationById($manager, $invitation['invitationId'], 1);
        $pending = $this->homes()
            ->invite(
                $manager,
                $id,
                'new-member@example.test',
                'member',
            );
        $member = $this->login('new-member@example.test');
        $this->onboard($member);
        $this->homes()
            ->saveMemberPermissions(
                $owner,
                $id,
                $manager->userId,
                [
                'permissions' => ['members.invite' => false],
                'expectedRevision' => 0,
                ],
            );
        $this->problem(
            404,
            fn() => $this->homes()
                ->acceptInvitationById($member, $pending['invitationId'], 1),
        );
        self::assertFalse(
            $this->db->fetchOne(
                'SELECT user_id FROM home_memberships WHERE home_id = ? AND user_id = ?',
                [$id, $member->userId],
            ),
        );
        $this->problem(
            404,
            fn() => $this->homes()
                ->get($member, $id),
        );
        self::assertSame(
            $id,
            $this->container->get(OperatorWorkspaceService::class)
                ->home($admin, $id)['id'],
        );
    }

    public function testVerifiedAliasesStayOnOneAccountAndLastAddressCannotBeRemoved(): void
    {
        $identity = $this->login('first@example.test');
        $profiles = $this->container->get(AccountProfileService::class);
        $challenge = $profiles->requestEmail($identity, 'second@example.test', '192.0.2.10');
        $profiles->verifyEmail(
            $identity,
            [
                ...$challenge,
                'code' => $this->emailCode('second@example.test'),
            ],
            '192.0.2.10',
        );
        self::assertCount(2, $profiles->get($identity)['emails']);
        $second = $this->login('second@example.test');
        self::assertSame($identity->userId, $second->userId);
        $emails = $profiles->get($identity)['emails'];
        $next = array_values(
            array_filter(
                $emails,
                static fn(array $email): bool => $email['email'] === 'second@example.test',
            ),
        )[0];
        $proof = $profiles->requestSecurityCode($identity, 'email.primary', '192.0.2.10');
        $proof = $profiles->verifySecurityCode(
            $identity,
            [
                ...$proof,
                'code' => $this->emailCode('first@example.test'),
            ],
            '192.0.2.10',
        );
        $profiles->changeEmail(
            $identity,
            $next['id'],
            $proof['proofToken'],
            true,
        );
        self::assertSame(
            'second@example.test',
            $this->db->fetchOne(
                'SELECT email FROM users WHERE id = ?',
                [$identity->userId],
            ),
        );
    }

    public function testAdministratorsRequireApprovalAndOnlyReceiveTheirGroupPermissions(): void
    {
        $owner = $this->systemOwner();
        $candidate = $this->login('operator@example.test', 'admin');
        $workspace = $this->container->get(OperatorWorkspaceService::class);
        $this->problem(
            403,
            fn() => $workspace->administrators($candidate),
        );
        $group = $this->access()
            ->saveGroup(
                $owner,
                null,
                [
                'scope' => 'admin',
                'name' => 'Directory reviewers',
                'features' => ['administrators.read' => true],
                'limits' => [],
                'delegablePermissions' => [],
                'rolePermissions' => [],
                'expectedRevision' => 0,
                ],
            );
        $workspace->reviewAdministrator(
            $owner,
            $candidate->userId,
            [
                'status' => 'approved',
                'groupId' => $group['id'],
                'expectedRevision' => 1,
                'assignmentRevision' => 0,
            ],
        );
        self::assertCount(2, $workspace->administrators($candidate));
        self::assertFalse(
            $this->access()
                ->allows(
                    'admin',
                    $candidate->userId,
                    'administrators.approve',
                ),
        );
        $this->problem(
            403,
            fn() => $workspace->reviewAdministrator(
                $candidate,
                $owner->userId,
                ['status' => 'suspended', 'expectedRevision' => 1],
            ),
        );
        $workspace->reviewAdministrator(
            $owner,
            $candidate->userId,
            ['status' => 'suspended', 'expectedRevision' => 2],
        );
        $this->problem(
            403,
            fn() => $workspace->administrators($candidate),
        );
        $this->problem(
            422,
            fn() => $this->access()
                ->assign($owner, 'admin', $owner->userId, $group['id'], 1),
        );
    }

    public function testLastVerifiedEmailCannotBeRemovedEvenWithFreshConfirmation(): void
    {
        $identity = $this->login('only@example.test');
        $profiles = $this->container->get(AccountProfileService::class);
        $email = $profiles->get($identity)['emails'][0];
        $challenge = $profiles->requestSecurityCode($identity, 'email.remove', '192.0.2.10');
        $proof = $profiles->verifySecurityCode(
            $identity,
            [
                ...$challenge,
                'code' => $this->emailCode('only@example.test'),
            ],
            '192.0.2.10',
        );
        $this->problem(
            409,
            fn() => $profiles->changeEmail(
                $identity,
                $email['id'],
                $proof['proofToken'],
                false,
            ),
        );
        self::assertCount(1, $profiles->get($identity)['emails']);
    }

    private function systemOwner(): AuthenticatedIdentity
    {
        $tester = new CommandTester($this->container->get(SystemOwnerCommand::class));
        self::assertSame(
            0,
            $tester->execute(['email' => 'system@example.test']),
        );
        $owner = $this->login('system@example.test', 'admin');
        self::assertTrue(
            $this->access()
                ->allows(
                    'admin',
                    $owner->userId,
                    'administrators.approve',
                ),
        );
        return $owner;
    }

    /** @return array{array<string, mixed>, string} */
    private function challenge(
        string $email,
        string $kind = 'homeowner',
    ): array {
        $challenge = $this->loginService()
            ->request(
                [
                'email' => $email,
                'applicationKind' => $kind,
                'installationId' => '11111111-1111-4111-8111-111111111111',
                'deviceName' => 'Test client',
                'platform' => 'linux',
                'transport' => 'native',
                ],
                '192.0.2.1',
            );
        return [$challenge, $this->emailCode($email)];
    }

    private function emailCode(string $email): string
    {
        $now = new DateTimeImmutable('+1 second');
        $outbox = $this->container->get(NotificationOutbox::class);
        foreach ($outbox->lease(100, $now, $now->modify('+1 minute')) as $message) {
            $outbox->complete($message['id'], $now);
            if ($message['recipient'] === $email && $message['template'] === 'email-code') {
                return (string) $message['context']['code'];
            }
        }
        throw new \RuntimeException(
            'The expected verification email was not queued.',
        );
    }

    private function login(
        string $email,
        string $kind = 'homeowner',
    ): AuthenticatedIdentity {
        [$challenge, $code] = $this->challenge($email, $kind);
        $grant = $this->loginService()
            ->verify([...$challenge, 'code' => $code], '192.0.2.1');
        return $this->container->get(AuthenticationService::class)
            ->authenticate($grant['accessToken']);
    }

    /** @return array<string, mixed> */
    private function onboard(
        AuthenticatedIdentity $identity,
    ): array {
        $policy = $this->container->get(CountryService::class)
            ->registrationPolicy('NA');
        return $this->container->get(AccountProfileService::class)
            ->save(
                $identity,
                [
                'displayName' => 'Test user',
                'countryCode' => 'NA',
                'expectedRevision' => 1,
                'policyAccepted' => true,
                'policyId' => $policy['id'],
                'policyRevision' => $policy['revision'],
                ],
                true,
            );
    }

    /** @return array<string, mixed> */
    private function homeGroup(
        AuthenticatedIdentity $admin,
        bool $inviting,
        int $total,
    ): array {
        $group = FeatureCatalog::defaults()[2];
        $group['name'] = 'Test home group';
        $group['expectedRevision'] = 0;
        $group['features']['members.invite'] = $inviting;
        $group['limits']['members.total'] = $total;
        return $this->access()
            ->saveGroup($admin, null, $group);
    }

    private function loginService(): EmailLoginService
    {
        return $this->container->get(EmailLoginService::class);
    }

    private function homes(): HomeService
    {
        return $this->container->get(HomeService::class);
    }

    private function access(): AccessService
    {
        return $this->container->get(AccessService::class);
    }

    private function problem(
        int $status,
        callable $operation,
    ): void {
        try {
            $operation();
            self::fail('Expected the operation to be refused.');
        } catch (Problem $problem) {
            self::assertSame($status, $problem->status);
        }
    }
}
