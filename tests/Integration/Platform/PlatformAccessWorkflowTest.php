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
use Laminas\ServiceManager\ServiceManager;
use PHPUnit\Framework\TestCase;
use Providentia\Access\Application\AccessService;
use Providentia\Access\Domain\FeatureCatalog;
use Providentia\Administration\Application\OperatorWorkspaceService;
use Providentia\Administration\Application\OperatorInventoryService;
use Providentia\Administration\Application\OperatorShoppingService;
use Providentia\Administration\Application\OperatorStockPreferenceService;
use Providentia\Catalog\Application\CatalogContributionService;
use Providentia\Catalog\Application\CatalogMaintenanceService;
use Providentia\Geography\Application\CountryService;
use Providentia\Home\Application\HomeAuthorization;
use Providentia\Home\Application\HomeService;
use Providentia\Identity\Application\AccountProfileService;
use Providentia\Identity\Application\AuthenticatedIdentity;
use Providentia\Identity\Application\AuthenticationService;
use Providentia\Identity\Application\EmailLoginService;
use Providentia\Identity\Application\NotificationOutbox;
use Providentia\Identity\Application\ProfileMediaService;
use Providentia\Identity\Http\BearerAuthenticationMiddleware;
use Providentia\Inventory\Application\InventoryService;
use Providentia\Identity\Infrastructure\Cli\SystemOwnerCommand;
use Providentia\SharedKernel\Application\Problem;
use Providentia\SharedKernel\Application\TransactionManager;
use Providentia\SharedKernel\Http\ProblemDetailsMiddleware;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\NullLogger;
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
                new Version('Providentia\Migrations\Version20260922000100'),
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

    public function testOnboardingPreservesAPreassignedGroupBeforeAcceptingAManagerInvitation(): void
    {
        $admin = $this->systemOwner();
        [$owner, $home] = $this->ownedHome('preassigned-owner@example.test');
        $homeGroup = $this->homeGroup($admin, true, 3);
        $this->access()
            ->assign($admin, FeatureCatalog::HOME, $home['id'], $homeGroup['id'], 1);
        $invitation = $this->homes()
            ->invite(
                $owner,
                $home['id'],
                'preassigned-manager@example.test',
                HomeAuthorization::MANAGER,
            );

        $manager = $this->login('preassigned-manager@example.test');
        $accountGroupInput = FeatureCatalog::defaults()[0];
        $accountGroupInput['name'] = 'Preassigned account group';
        $accountGroupInput['expectedRevision'] = 0;
        $accountGroup = $this->access()
            ->saveGroup($admin, null, $accountGroupInput);
        $this->access()
            ->assign(
                $admin,
                FeatureCatalog::ACCOUNT,
                $manager->userId,
                $accountGroup['id'],
                0,
            );
        $this->access()
            ->assign(
                $admin,
                FeatureCatalog::ACCOUNT,
                $manager->userId,
                $accountGroup['id'],
                1,
            );
        $assigned = $this->access()
            ->assignment($admin, FeatureCatalog::ACCOUNT, $manager->userId);
        self::assertSame($accountGroup['id'], $assigned['groupId']);
        self::assertSame(2, $assigned['revision']);

        $policy = $this->container->get(CountryService::class)
            ->registrationPolicy('NA');
        $profileInput = [
            'displayName' => 'Preassigned manager',
            'countryCode' => 'NA',
            'expectedRevision' => 0,
            'policyAccepted' => true,
            'policyId' => $policy['id'],
            'policyRevision' => $policy['revision'],
        ];
        $profiles = $this->container->get(AccountProfileService::class);
        $this->problem(
            409,
            fn() => $profiles->save($manager, $profileInput, true),
        );
        self::assertFalse($profiles->get($manager)['onboardingComplete']);

        $profile = $profiles->save(
            $manager,
            [...$profileInput, 'expectedRevision' => 1],
            true,
        );
        self::assertTrue($profile['onboardingComplete']);
        self::assertSame($accountGroup['id'], $profile['accountAccess']['groupId']);
        self::assertSame(2, $profile['accountAccess']['revision']);
        self::assertSame($accountGroup['revision'], $profile['accountAccess']['groupRevision']);
        self::assertSame(
            1,
            (int) $this->db->fetchOne(
                "SELECT COUNT(*) FROM platform_audit_events WHERE action = 'account.registered'"
                    . ' AND actor_user_id = ?',
                [$manager->userId],
            ),
        );

        $this->homes()
            ->acceptInvitationById($manager, $invitation['invitationId'], $invitation['revision']);
        $opened = $this->homes()
            ->get($manager, $home['id']);
        $permissions = $opened['effectivePermissions'] ?? null;
        self::assertSame(HomeAuthorization::MANAGER, $opened['role']);
        self::assertIsArray($permissions);
        self::assertSame(
            array_values(array_unique($permissions)),
            $permissions,
        );
        self::assertSame(
            1,
            count(array_keys($permissions, 'ai.credentials.use', true)),
        );
    }

    public function testProfileLocationNamesSurviveReadBackAndOptionalFieldsCanBeCleared(): void
    {
        $this->db->insert(
            'reference_cities',
            [
                'source_id' => 990000001,
                'country_code' => 'NA',
                'state_id' => 44,
                'name' => 'Windhoek',
                'source_version' => 'acceptance-fixture',
                'active' => 1,
            ],
        );
        $identity = $this->login('location-profile@example.test');
        $profiles = $this->container->get(AccountProfileService::class);
        $policy = $this->container->get(CountryService::class)
            ->registrationPolicy('NA');
        $profile = $profiles->save(
            $identity,
            [
                'displayName' => 'Location profile',
                'countryCode' => 'NA',
                'stateId' => 44,
                'cityId' => 990000001,
                'expectedRevision' => 1,
                'policyAccepted' => true,
                'policyId' => $policy['id'],
                'policyRevision' => $policy['revision'],
            ],
            true,
        );
        self::assertSame(44, $profile['stateId']);
        self::assertSame('Khomas', $profile['stateName']);
        self::assertSame(990000001, $profile['cityId']);
        self::assertSame('Windhoek', $profile['cityName']);
        self::assertSame($profile, $profiles->get($identity));

        $cleared = $profiles->save(
            $identity,
            [
                'displayName' => 'Location profile',
                'countryCode' => 'NA',
                'stateId' => null,
                'cityId' => null,
                'expectedRevision' => 2,
            ],
            false,
        );
        self::assertNull($cleared['stateId']);
        self::assertNull($cleared['stateName']);
        self::assertNull($cleared['cityId']);
        self::assertNull($cleared['cityName']);

        $this->db->update('country_settings', ['published' => 1], ['country_code' => 'ZA']);
        $countryChange = [
            'displayName' => 'Location profile',
            'countryCode' => 'ZA',
            'stateId' => null,
            'cityId' => null,
            'expectedRevision' => 3,
        ];
        $this->problem(
            422,
            fn() => $profiles->save($identity, $countryChange, false),
        );
        $changed = $profiles->save(
            $identity,
            [
                ...$countryChange,
                'policyAccepted' => true,
                'policyId' => $policy['id'],
                'policyRevision' => $policy['revision'],
            ],
            false,
        );
        self::assertSame('ZA', $changed['countryCode']);
        self::assertNull($changed['stateId']);
        self::assertNull($changed['stateName']);
        self::assertNull($changed['cityId']);
        self::assertNull($changed['cityName']);
        self::assertSame($changed, $profiles->get($identity));
        self::assertSame(
            ['country_code' => 'ZA', 'state_id' => null, 'city_id' => null],
            $this->db->fetchAssociative(
                'SELECT country_code, state_id, city_id FROM user_profiles WHERE user_id = ?',
                [$identity->userId],
            ),
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

    public function testCategoryReactivationRespectsReducedAllowanceWithoutDeletingExistingRecords(): void
    {
        $admin = $this->systemOwner();
        [$owner, $home] = $this->ownedHome('categories@example.test');
        $homeId = $home['id'];
        $inventory = $this->container->get(InventoryService::class);
        $first = $inventory->createHomeCategory($owner, $homeId, 'First');
        $archived = $inventory->createHomeCategory($owner, $homeId, 'Archived');
        $inventory->updateHomeCategory($owner, $homeId, $archived['id'], null, 'archived', 1);
        $second = $inventory->createHomeCategory($owner, $homeId, 'Second');
        $group = $this->homeGroup($admin, false, 3);
        $group['limits']['categories.total'] = 1;
        $group['expectedRevision'] = $group['revision'];
        $this->access()->saveGroup($admin, $group['id'], $group);
        $this->access()->assign($admin, 'home', $homeId, $group['id'], 1);

        $edited = $inventory->updateHomeCategory($owner, $homeId, $first['id'], 'Renamed', 'active', 1);
        self::assertSame('Renamed', $edited['name']);
        $this->problem(409, fn() => $inventory->createHomeCategory($owner, $homeId, 'Over limit'));
        $this->problem(
            409,
            fn() => $inventory->updateHomeCategory($owner, $homeId, $archived['id'], null, 'active', 2),
        );
        self::assertCount(3, $inventory->categories($owner, $homeId, true));
        self::assertCount(2, $inventory->categories($owner, $homeId));
        $inventory->updateHomeCategory($owner, $homeId, $first['id'], null, 'archived', 2);
        $inventory->updateHomeCategory($owner, $homeId, $second['id'], null, 'archived', 1);
        $restored = $inventory->updateHomeCategory($owner, $homeId, $archived['id'], null, 'active', 2);
        self::assertSame('active', $restored['status']);
        self::assertCount(1, $inventory->categories($owner, $homeId));
    }

    public function testProductReactivationCountsCapacityButEditingGrandfatheredProductsDoesNot(): void
    {
        $admin = $this->systemOwner();
        [$owner, $home] = $this->ownedHome('products@example.test');
        $homeId = $home['id'];
        $inventory = $this->container->get(InventoryService::class);
        $first = $inventory->addHomeProduct($owner, $homeId, null, null, 'First product', null);
        $archived = $inventory->addHomeProduct($owner, $homeId, null, null, 'Archived product', null);
        $this->productStatus($owner, $homeId, $archived['id'], 'archived', 1);
        $second = $inventory->addHomeProduct($owner, $homeId, null, null, 'Second product', null);
        $group = $this->homeGroup($admin, false, 3);
        $group['limits']['products.total'] = 1;
        $group['expectedRevision'] = $group['revision'];
        $this->access()->saveGroup($admin, $group['id'], $group);
        $this->access()->assign($admin, 'home', $homeId, $group['id'], 1);

        $inventory->updateHomeProduct(
            $owner,
            $homeId,
            $first['id'],
            true,
            'Renamed product',
            false,
            null,
            false,
            null,
            'active',
            1,
        );
        $this->problem(
            409,
            fn() => $inventory->addHomeProduct($owner, $homeId, null, null, 'Over limit', null),
        );
        $this->problem(409, fn() => $this->productStatus($owner, $homeId, $archived['id'], 'active', 2));
        self::assertSame('Renamed product', $this->db->fetchOne(
            'SELECT private_name FROM home_products WHERE id = ?',
            [$first['id']],
        ));
        self::assertSame(3, (int) $this->db->fetchOne(
            'SELECT COUNT(*) FROM home_products WHERE home_id = ?',
            [$homeId],
        ));
        $this->productStatus($owner, $homeId, $first['id'], 'archived', 2);
        $this->productStatus($owner, $homeId, $second['id'], 'archived', 1);
        $restored = $this->productStatus($owner, $homeId, $archived['id'], 'active', 2);
        self::assertSame('active', $restored['status']);
        self::assertSame(1, (int) $this->db->fetchOne(
            "SELECT COUNT(*) FROM home_products WHERE home_id = ? AND status = 'active'",
            [$homeId],
        ));
    }

    public function testAssignmentReadsRequireScopedAdministratorAuthorityAndExposeCurrentRevision(): void
    {
        $admin = $this->systemOwner();
        [$owner, $home] = $this->ownedHome('assignment@example.test');
        $homeId = $home['id'];
        $this->problem(403, fn() => $this->access()->assignment($owner, 'home', $homeId));
        $this->problem(403, fn() => $this->access()->assignment($owner, 'account', $owner->userId));
        $before = $this->access()->assignment($admin, 'home', $homeId);
        self::assertSame(FeatureCatalog::STARTER_HOME, $before['groupId']);
        self::assertSame(1, $before['revision']);
        $group = $this->homeGroup($admin, true, 3);
        $this->access()->assign($admin, 'home', $homeId, $group['id'], $before['revision']);
        $scoped = $this->approvedAdministrator($admin, 'assigner@example.test', ['homes.assign']);
        $after = $this->access()->assignment($scoped, 'home', $homeId);
        self::assertSame($group['id'], $after['groupId']);
        self::assertSame(2, $after['revision']);
        self::assertSame($group['revision'], $after['groupRevision']);
        $this->problem(403, fn() => $this->access()->assignment($scoped, 'account', $owner->userId));
        $this->problem(403, fn() => $this->access()->assignment($scoped, 'admin', $scoped->userId));
        $this->problem(422, fn() => $this->access()->assignment($admin, 'unknown', $homeId));
        $this->problem(409, fn() => $this->access()->assign($scoped, 'home', $homeId, $group['id'], 1));
        self::assertSame(2, $this->access()->assignment($admin, 'home', $homeId)['revision']);
    }

    public function testProfileImagesRequireSharedHomePeoplePermissionAndAuditOperatorReads(): void
    {
        $admin = $this->systemOwner();
        [$owner, $home] = $this->ownedHome('photo-owner@example.test');
        $homeId = $home['id'];
        $group = $this->homeGroup($admin, true, 3);
        $this->access()->assign($admin, 'home', $homeId, $group['id'], 1);
        $invitation = $this->homes()->invite($owner, $homeId, 'photo-manager@example.test', 'manager');
        $manager = $this->login('photo-manager@example.test');
        $this->onboard($manager);
        $this->homes()->acceptInvitationById($manager, $invitation['invitationId'], 1);
        $media = $this->container->get(ProfileMediaService::class);
        $image = imagecreatetruecolor(16, 16);
        self::assertInstanceOf(\GdImage::class, $image);
        ob_start();
        imagepng($image);
        $png = ob_get_clean();
        self::assertIsString($png);
        $media->saveImage($owner, 'account', $owner->userId, $png, 0);
        $media->saveImage($owner, 'home', $homeId, $png, 0);
        $avatar = $media->image($manager, 'account', $owner->userId);
        self::assertNotNull($avatar);
        self::assertSame(hash('sha256', $avatar['bytes']), $avatar['digest']);
        self::assertStringStartsWith('RIFF', $avatar['bytes']);
        $this->homes()->saveMemberPermissions($owner, $homeId, $manager->userId, [
            'permissions' => ['members.read' => false],
            'expectedRevision' => 0,
        ]);
        $this->problem(404, fn() => $media->image($manager, 'account', $owner->userId));
        self::assertNotNull($media->image($manager, 'home', $homeId));
        $stranger = $this->login('photo-stranger@example.test');
        $this->problem(404, fn() => $media->image($stranger, 'account', $owner->userId));
        $this->problem(404, fn() => $media->image($stranger, 'home', $homeId));

        $homeReader = $this->approvedAdministrator($admin, 'home-reader@example.test', ['homes.read']);
        self::assertNotNull($media->image($homeReader, 'home', $homeId, true));
        $this->problem(403, fn() => $media->image($homeReader, 'account', $owner->userId, true));
        $peopleReader = $this->approvedAdministrator($admin, 'people-reader@example.test', ['people.read']);
        self::assertSame($avatar, $media->image($peopleReader, 'account', $owner->userId, true));
        $this->problem(403, fn() => $media->image($peopleReader, 'home', $homeId, true));
        $events = $this->db->fetchAllAssociative(
            "SELECT actor_user_id, scope, subject_id, details_json FROM platform_audit_events "
            . "WHERE action = 'operator.profile-image.viewed' ORDER BY scope",
        );
        self::assertCount(2, $events);
        self::assertSame($peopleReader->userId, $events[0]['actor_user_id']);
        self::assertSame($owner->userId, $events[0]['subject_id']);
        self::assertSame($homeReader->userId, $events[1]['actor_user_id']);
        self::assertSame($homeId, $events[1]['subject_id']);
        self::assertSame('[]', $events[0]['details_json']);
        self::assertSame('[]', $events[1]['details_json']);
    }

    public function testOperatorSharingRecordsRemainVisibleIndependentlyOfCurrentPublicConsent(): void
    {
        $admin = $this->systemOwner();
        [$owner, $home] = $this->ownedHome('sharing-owner@example.test');
        $homeId = $home['id'];
        $catalog = $this->container->get(CatalogContributionService::class);
        $catalog->configureConsent($owner, $homeId, true, false, false, CatalogContributionService::NOTICE_VERSION, 0);
        $product = $this->container->get(InventoryService::class)
            ->addHomeProduct($owner, $homeId, null, null, 'Private beans', null);
        $submissionId = '55555555-5555-4555-8555-555555555555';
        $catalog->submit($owner, $homeId, $submissionId, 'product_identity', $product['id'], 1, [
            'canonicalName' => 'Beans',
            'categoryLabel' => 'Groceries',
        ]);
        $catalog->configureConsent($owner, $homeId, false, false, false, CatalogContributionService::NOTICE_VERSION, 1);
        $workspace = $this->container->get(OperatorWorkspaceService::class);
        $view = $workspace->home($admin, $homeId);
        self::assertSame(0, (int) $view['sharingConsent']['share_product_identity']);
        $rows = $workspace->records($admin, $homeId, 'sharing', 0);
        self::assertCount(1, $rows);
        self::assertSame($submissionId, $rows[0]['id']);
        self::assertSame('product_identity', $rows[0]['contribution_type']);
        self::assertArrayNotHasKey('payload_json', $rows[0]);
        self::assertArrayNotHasKey('submitted_by_user_id', $rows[0]);
        $this->problem(403, fn() => $workspace->records($owner, $homeId, 'sharing', 0));
        self::assertSame(1, (int) $this->db->fetchOne(
            "SELECT COUNT(*) FROM platform_audit_events WHERE action = 'operator.home.records.viewed' "
            . 'AND subject_id = ?',
            [$homeId],
        ));
    }

    public function testCoOwnerMayLeaveWhileTheFinalOwnerMustRemain(): void
    {
        $admin = $this->systemOwner();
        [$owner, $home] = $this->ownedHome('first-owner@example.test');
        $homeId = $home['id'];
        $group = $this->homeGroup($admin, true, 3);
        $group['limits']['members.owners'] = 2;
        $group['expectedRevision'] = $group['revision'];
        $this->access()->saveGroup($admin, $group['id'], $group);
        $this->access()->assign($admin, 'home', $homeId, $group['id'], 1);
        [$coOwner] = $this->ownedHome('second-owner@example.test', false);
        $invitation = $this->homes()->invite($owner, $homeId, 'second-owner@example.test', 'owner');
        $this->homes()->acceptInvitationById($coOwner, $invitation['invitationId'], 1);
        self::assertSame('owner', $this->homes()->get($coOwner, $homeId)['role']);
        $this->homes()->leave($coOwner, $homeId);
        $this->problem(404, fn() => $this->homes()->get($coOwner, $homeId));
        $this->problem(409, fn() => $this->homes()->leave($owner, $homeId));
        self::assertSame(1, (int) $this->db->fetchOne(
            "SELECT COUNT(*) FROM home_memberships WHERE home_id = ? AND role = 'owner' AND status = 'active'",
            [$homeId],
        ));
    }

    public function testOperatorShoppingLifecycleIsAuditedAndPreservesHouseholdBoundaries(): void
    {
        $admin = $this->systemOwner();
        [$owner, $home] = $this->ownedHome('operator-shopping@example.test');
        [, $otherHome] = $this->ownedHome('operator-shopping-other@example.test');
        $reader = $this->approvedAdministrator($admin, 'shopping-reader@example.test', ['homes.read']);
        $service = $this->container->get(OperatorShoppingService::class);
        $homeId = $home['id'];
        $listId = '55000000-0000-4000-8000-000000000001';
        $lineId = '55000000-0000-4000-8000-000000000002';
        $input = ['id' => $listId, 'name' => 'Weekly shop', 'expectedRevision' => 0, 'reason' => 'Household support'];
        $this->problem(403, fn() => $service->saveList($owner, $homeId, $listId, $input, true));
        $this->problem(403, fn() => $service->saveList($reader, $homeId, $listId, $input, true));
        $created = $service->saveList($admin, $homeId, $listId, $input, true);
        self::assertSame(1, $created['revision']);
        self::assertSame($homeId, $created['homeId']);
        $this->problem(409, fn() => $service->saveList($admin, $homeId, $listId, $input, true));
        $line = $service->saveLine(
            $admin,
            $homeId,
            $listId,
            $lineId,
            [
                'id' => $lineId,
                'description' => 'Oats',
                'quantityToBuy' => '2.5',
                'expectedRevision' => 0,
                'expectedListRevision' => 1,
                'reason' => 'Plan groceries',
            ],
            true,
        );
        self::assertSame(1, $line['revision']);
        self::assertFalse($line['checked']);
        $edit = [
            'description' => 'Rolled oats',
            'quantityToBuy' => '3.25',
            'expectedRevision' => 1,
            'reason' => 'Correct quantity',
        ];
        $this->problem(404, fn() => $service->saveLine($admin, $otherHome['id'], $listId, $lineId, $edit, false));
        $edited = $service->saveLine($admin, $homeId, $listId, $lineId, $edit, false);
        self::assertSame('Rolled oats', $edited['description']);
        self::assertSame('3.25', rtrim(rtrim((string) $edited['quantityToBuy'], '0'), '.'));
        self::assertSame('manual', $edited['source']);
        $this->problem(409, fn() => $service->saveLine($admin, $homeId, $listId, $lineId, $edit, false));
        $checked = $service->checkLine($admin, $homeId, $listId, $lineId, [
            'checked' => true,
            'expectedRevision' => 2,
            'reason' => 'Purchased',
        ]);
        self::assertTrue($checked['checked']);
        foreach ([true, false] as $index => $archived) {
            $saved = $service->saveLine(
                $admin,
                $homeId,
                $listId,
                $lineId,
                [
                    'archived' => $archived,
                    'expectedRevision' => $index + 3,
                    'reason' => 'Correct list',
                ],
                false,
            );
            self::assertSame($archived, $saved['archived']);
            self::assertTrue($saved['checked']);
        }
        $revision = (int) $this->db->fetchOne('SELECT revision FROM shopping_lists WHERE id = ?', [$listId]);
        $service->saveList(
            $admin,
            $homeId,
            $listId,
            [
                'status' => 'archived',
                'expectedRevision' => $revision,
                'reason' => 'Complete list',
            ],
            false,
        );
        $this->problem(
            409,
            fn() => $service->saveLine(
                $admin,
                $homeId,
                $listId,
                $lineId,
                [
                    'quantityToBuy' => '5',
                    'expectedRevision' => 5,
                    'reason' => 'Closed list attempt',
                ],
                false,
            ),
        );
        $service->saveList(
            $admin,
            $homeId,
            $listId,
            [
                'status' => 'open',
                'expectedRevision' => $revision + 1,
                'reason' => 'Reopen list',
            ],
            false,
        );
        $audit = $this->db->fetchOne(
            "SELECT details_json FROM platform_audit_events WHERE action = 'operator.home.shopping-line.saved' " .
                "AND details_json LIKE '%Correct quantity%'",
        );
        self::assertIsString($audit);
        $details = json_decode($audit, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Oats', $details['before']['description']);
        self::assertSame('Rolled oats', $details['after']['description']);
        self::assertSame(
            0,
            (int) $this->db->fetchOne('SELECT COUNT(*) FROM home_memberships WHERE home_id = ? AND user_id = ?', [
                $homeId,
                $admin->userId,
            ]),
        );
        self::assertSame(
            5,
            (int) $this->db->fetchOne('SELECT COUNT(*) FROM change_log WHERE home_id = ? AND entity_id = ?', [
                $homeId,
                $lineId,
            ]),
        );
        $this->db->executeStatement(
            'CREATE TRIGGER reject_shopping_audit BEFORE INSERT ON platform_audit_events ' .
                "WHEN NEW.action = 'operator.home.shopping-line.saved' " .
                "BEGIN SELECT RAISE(ABORT, 'Audit unavailable'); END",
        );
        try {
            $service->saveLine(
                $admin,
                $homeId,
                $listId,
                $lineId,
                [
                    'description' => 'Uncommitted edit',
                    'expectedRevision' => 5,
                    'reason' => 'Atomicity check',
                ],
                false,
            );
            self::fail('Audit failure must roll back the shopping edit.');
        } catch (\Doctrine\DBAL\Exception) {
            self::assertSame(
                'Rolled oats',
                $this->db->fetchOne('SELECT description FROM shopping_list_lines WHERE id = ?', [$lineId]),
            );
            self::assertSame(
                $revision + 2,
                (int) $this->db->fetchOne('SELECT revision FROM shopping_lists WHERE id = ?', [$listId]),
            );
        }
    }

    public function testOperatorPlacesAreRevisionBoundAuditedAndHomeScoped(): void
    {
        $admin = $this->systemOwner();
        [$owner, $home] = $this->ownedHome('operator-places@example.test');
        [, $other] = $this->ownedHome('operator-places-other@example.test');
        $reader = $this->approvedAdministrator($admin, 'places-reader@example.test', ['homes.read']);
        $service = $this->container->get(OperatorInventoryService::class);
        $homeId = $home['id'];
        foreach (['Location', 'Store'] as $index => $kind) {
            $id = '53000000-0000-4000-8000-00000000000' . ($index + 1);
            $create = 'create' . $kind;
            $update = 'update' . $kind;
            $input = [
                'id' => $id,
                'name' => 'Original ' . $kind,
                'reason' => 'Household support',
                'expectedRevision' => 0,
                ...($kind === 'Location' ? ['kind' => 'pantry'] : ['location' => 'Windhoek']),
            ];
            $this->problem(403, fn() => $service->$create($owner, $homeId, $input));
            $this->problem(403, fn() => $service->$create($reader, $homeId, $input));
            $created = $service->$create($admin, $homeId, $input);
            self::assertSame($id, $created['id']);
            self::assertSame('active', $created['status']);
            self::assertSame(1, (int) $created['revision']);
            $this->problem(409, fn() => $service->$create($admin, $homeId, $input));
            $edit = ['name' => 'Corrected ' . $kind, 'reason' => 'Correct label', 'expectedRevision' => 1];
            $this->problem(404, fn() => $service->$update($admin, $other['id'], $id, $edit));
            $updated = $service->$update($admin, $homeId, $id, $edit);
            self::assertSame('Corrected ' . $kind, $updated['name']);
            self::assertSame(2, (int) $updated['revision']);
            $this->problem(409, fn() => $service->$update($admin, $homeId, $id, $edit));
            foreach (['archived', 'active'] as $revision => $status) {
                $saved = $service->$update($admin, $homeId, $id, [
                    'status' => $status, 'reason' => 'Lifecycle correction', 'expectedRevision' => $revision + 2,
                ]);
                self::assertSame($status, $saved['status']);
            }
            $audit = $this->db->fetchOne(
                'SELECT details_json FROM platform_audit_events WHERE action = ? AND details_json LIKE ?',
                ['operator.home.' . strtolower($kind) . '.saved', '%Correct label%'],
            );
            self::assertIsString($audit);
            $details = json_decode($audit, true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('Original ' . $kind, $details['before']['name']);
            self::assertSame('Corrected ' . $kind, $details['after']['name']);
            self::assertSame(4, (int) $this->db->fetchOne(
                'SELECT COUNT(*) FROM change_log WHERE home_id = ? AND entity_id = ?',
                [$homeId, $id],
            ));
        }
        self::assertSame(0, (int) $this->db->fetchOne(
            'SELECT COUNT(*) FROM home_memberships WHERE home_id = ? AND user_id = ?',
            [$homeId, $admin->userId],
        ));
        $stores = $this->container->get(OperatorWorkspaceService::class)->records($admin, $homeId, 'stores', 0);
        self::assertCount(1, $stores);
    }

    public function testOperatorInventoryUsesDomainGuardsAndAuditsWithoutCreatingMembership(): void
    {
        $admin = $this->systemOwner();
        [$owner, $home] = $this->ownedHome('operator-inventory-home@example.test');
        $homeId = $home['id'];
        $service = $this->container->get(OperatorInventoryService::class);
        $categoryId = '50000000-0000-4000-8000-000000000001';
        $productId = '50000000-0000-4000-8000-000000000002';
        $category = $service->createCategory($admin, $homeId, [
            'id' => $categoryId, 'name' => 'Pantry', 'reason' => 'Household support', 'expectedRevision' => 0,
        ]);
        self::assertSame(1, $category['revision']);
        $created = $service->createProduct($admin, $homeId, [
            'id' => $productId, 'privateName' => 'Beans', 'originalPackText' => '400 g',
            'homeCategoryId' => $categoryId, 'reason' => 'Household support', 'expectedRevision' => 0,
        ]);
        self::assertSame($productId, $created['id']);
        $this->problem(409, fn() => $service->createProduct($admin, $homeId, [
            'id' => $productId, 'privateName' => 'Beans', 'reason' => 'Retry', 'expectedRevision' => 0,
        ]));
        $updated = $service->updateProduct($admin, $homeId, $productId, [
            'privateName' => 'Red beans', 'originalPackText' => '500 g',
            'reason' => 'Correct label', 'expectedRevision' => 1,
        ]);
        self::assertSame('Red beans', $updated['privateName']);
        self::assertSame(2, $updated['revision']);
        $this->problem(409, fn() => $service->updateProduct($admin, $homeId, $productId, [
            'privateName' => 'Stale label', 'reason' => 'Stale edit', 'expectedRevision' => 1,
        ]));
        $this->problem(409, fn() => $service->updateCategory($admin, $homeId, $categoryId, [
            'status' => 'archived', 'reason' => 'Retire category', 'expectedRevision' => 1,
        ]));
        $inventory = $this->container->get(InventoryService::class);
        $inventory->manualAdjustment($owner, $homeId, $productId, '1', 'Counted stock', 'operator-test-stock');
        $this->problem(409, fn() => $service->updateProduct($admin, $homeId, $productId, [
            'status' => 'archived', 'reason' => 'Retire product', 'expectedRevision' => 2,
        ]));
        $inventory->manualAdjustment($owner, $homeId, $productId, '-1', 'Consumed stock', 'operator-test-consumed');
        foreach (['archived', 'active'] as $index => $status) {
            $saved = $service->updateProduct($admin, $homeId, $productId, [
                'status' => $status, 'reason' => 'Lifecycle correction', 'expectedRevision' => $index + 2,
            ]);
            self::assertSame($status, $saved['status']);
        }
        self::assertSame(0, (int) $this->db->fetchOne(
            'SELECT COUNT(*) FROM home_memberships WHERE home_id = ? AND user_id = ?',
            [$homeId, $admin->userId],
        ));
        $this->problem(404, fn() => $inventory->categories($admin, $homeId));
        self::assertSame(4, (int) $this->db->fetchOne(
            "SELECT COUNT(*) FROM platform_audit_events WHERE action = 'operator.home.product.saved'",
        ));
        $changes = $this->db->fetchAllAssociative(
            'SELECT revision, payload_json, changed_by_user_id FROM change_log '
            . "WHERE home_id = ? AND entity_id = ? AND entity_type = 'inventory-home-product' ORDER BY revision",
            [$homeId, $productId],
        );
        self::assertCount(4, $changes);
        self::assertSame($admin->userId, $changes[3]['changed_by_user_id']);
        self::assertSame('active', json_decode($changes[3]['payload_json'], true, 512, JSON_THROW_ON_ERROR)['status']);
        $audit = $this->db->fetchOne(
            "SELECT details_json FROM platform_audit_events WHERE action = 'operator.home.product.saved' "
            . "AND details_json LIKE '%Correct label%'",
        );
        self::assertIsString($audit);
        $details = json_decode($audit, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Beans', $details['before']['privateName']);
        self::assertSame('Red beans', $details['after']['privateName']);
    }

    public function testOperatorInventoryRequiresManageAndHomeScopedIdentifiers(): void
    {
        $admin = $this->systemOwner();
        [$owner, $home] = $this->ownedHome('operator-isolation@example.test');
        [, $otherHome] = $this->ownedHome('operator-other-home@example.test');
        $reader = $this->approvedAdministrator($admin, 'inventory-reader@example.test', ['homes.read']);
        $service = $this->container->get(OperatorInventoryService::class);
        $input = [
            'id' => '51000000-0000-4000-8000-000000000001', 'name' => 'Home category',
            'reason' => 'Support edit', 'expectedRevision' => 0,
        ];
        $this->problem(403, fn() => $service->createCategory($owner, $home['id'], $input));
        $this->problem(403, fn() => $service->createCategory($reader, $home['id'], $input));
        $service->createCategory($admin, $home['id'], $input);
        $this->problem(404, fn() => $service->updateCategory($admin, $otherHome['id'], $input['id'], [
            'name' => 'Cross-home edit', 'reason' => 'Support edit', 'expectedRevision' => 1,
        ]));
        $this->problem(422, fn() => $service->updateCategory($admin, $home['id'], $input['id'], [
            'name' => 'No audit reason', 'reason' => ' ', 'expectedRevision' => 1,
        ]));
        $this->problem(422, fn() => $service->updateCategory($admin, $home['id'], $input['id'], [
            'name' => 'Unsupported', 'home_id' => $otherHome['id'], 'reason' => 'Support', 'expectedRevision' => 1,
        ]));
        self::assertSame('Home category', $this->db->fetchOne(
            'SELECT name FROM home_categories WHERE id = ?',
            [$input['id']],
        ));
    }

    public function testOperatorInventoryAuditFailureRollsBackRecordAndChangeFeed(): void
    {
        $admin = $this->systemOwner();
        [, $home] = $this->ownedHome('operator-atomicity@example.test');
        $this->db->executeStatement(
            "CREATE TRIGGER reject_operator_audit BEFORE INSERT ON platform_audit_events "
            . "WHEN NEW.action = 'operator.home.category.saved' "
            . "BEGIN SELECT RAISE(ABORT, 'Audit unavailable'); END",
        );
        $categoryId = '52000000-0000-4000-8000-000000000001';
        try {
            $this->container->get(OperatorInventoryService::class)->createCategory($admin, $home['id'], [
                'id' => $categoryId, 'name' => 'Uncommitted', 'reason' => 'Atomicity check', 'expectedRevision' => 0,
            ]);
            self::fail('The audit failure should abort the mutation.');
        } catch (\Doctrine\DBAL\Exception) {
            self::assertFalse($this->db->fetchOne('SELECT id FROM home_categories WHERE id = ?', [$categoryId]));
            self::assertSame(0, (int) $this->db->fetchOne(
                'SELECT COUNT(*) FROM change_log WHERE entity_id = ?',
                [$categoryId],
            ));
        }
    }

    public function testOperatorStockPreferencesKeepPolicyRevisionAuditAndHouseholdFeedTogether(): void
    {
        $admin = $this->systemOwner();
        [$owner, $home] = $this->ownedHome('operator-preferences@example.test');
        [, $other] = $this->ownedHome('operator-preferences-other@example.test');
        $reader = $this->approvedAdministrator($admin, 'preference-reader@example.test', ['homes.read']);
        $product = $this->container->get(InventoryService::class)
            ->addHomeProduct($owner, $home['id'], null, null, 'Beans', '400 g');
        $service = $this->container->get(OperatorStockPreferenceService::class);
        $initial = $service->get($reader, $home['id'], $product['id']);
        self::assertSame(0, $initial['revision']);
        self::assertSame([], $initial['packOptions']);
        $input = [
            'minimumQuantity' => '3.5', 'alwaysKeep' => true, 'neverSuggest' => false,
            'preferredPackId' => null, 'leadTimeDays' => 2, 'targetCoverageDays' => 14,
            'snoozeUntil' => null, 'expectedRevision' => 0, 'reason' => 'Set household stock minimum',
        ];
        $this->problem(403, fn() => $service->put($reader, $home['id'], $product['id'], $input));
        $this->problem(403, fn() => $service->put($owner, $home['id'], $product['id'], $input));
        $this->problem(404, fn() => $service->put($admin, $other['id'], $product['id'], $input));
        $result = $service->put($admin, $home['id'], $product['id'], $input);
        self::assertSame(1, $result['revision']);
        $this->problem(409, fn() => $service->put($admin, $home['id'], $product['id'], $input));
        $this->problem(422, fn() => $service->put($admin, $home['id'], $product['id'], [
            ...$input, 'expectedRevision' => 1, 'alwaysKeep' => 'false',
        ]));
        $saved = $service->get($admin, $home['id'], $product['id']);
        self::assertSame('3.5', $saved['minimumQuantity']);
        self::assertTrue($saved['alwaysKeep']);
        self::assertSame(1, $saved['revision']);
        $change = $this->db->fetchAssociative(
            "SELECT revision, changed_by_user_id, payload_json FROM change_log "
            . "WHERE home_id = ? AND entity_id = ? AND entity_type = 'shopping-stock-preference'",
            [$home['id'], $product['id']],
        );
        self::assertIsArray($change);
        self::assertSame(1, (int) $change['revision']);
        self::assertSame($admin->userId, $change['changed_by_user_id']);
        $payload = json_decode($change['payload_json'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('3.5', $payload['minimumQuantity']);
        self::assertSame(1, (int) $this->db->fetchOne(
            "SELECT COUNT(*) FROM platform_audit_events WHERE action = 'operator.home.stock-preference.saved'",
        ));
        $service->put($admin, $home['id'], $product['id'], [
            ...$input, 'minimumQuantity' => null, 'alwaysKeep' => false,
            'leadTimeDays' => 0, 'targetCoverageDays' => null, 'expectedRevision' => 1, 'reason' => 'Reset to defaults',
        ]);
        self::assertNull($service->get($admin, $home['id'], $product['id'])['minimumQuantity']);
    }

    public function testCatalogBackedCreationAcceptsOnlyCategoriesFromTheSameHome(): void
    {
        $admin = $this->systemOwner();
        [$owner, $home] = $this->ownedHome('catalog-category-owner@example.test');
        [$otherOwner, $otherHome] = $this->ownedHome('catalog-category-other@example.test');
        $catalog = $this->container->get(CatalogMaintenanceService::class);
        $globalCategoryId = '53000000-0000-4000-8000-000000000001';
        $globalProductId = '53000000-0000-4000-8000-000000000002';
        $base = ['status' => 'published', 'expectedRevision' => 0, 'reason' => 'Catalog fixture'];
        $catalog->save($admin, 'category', $globalCategoryId, [...$base, 'fields' => ['canonicalName' => 'Groceries']]);
        $catalog->save($admin, 'product', $globalProductId, [
            ...$base,
            'fields' => ['canonicalName' => 'Beans', 'brand' => '', 'categoryId' => $globalCategoryId],
        ]);
        $inventory = $this->container->get(InventoryService::class);
        $category = $inventory->createHomeCategory($owner, $home['id'], 'Pantry');
        $otherCategory = $inventory->createHomeCategory($otherOwner, $otherHome['id'], 'Other pantry');
        $created = $inventory->addHomeProduct($owner, $home['id'], $globalProductId, null, null, null, $category['id']);
        self::assertSame(
            $category['id'],
            $this->db->fetchOne('SELECT home_category_id FROM home_products WHERE id = ?', [$created['id']]),
        );
        $this->problem(
            422,
            fn() => $inventory->addHomeProduct(
                $owner,
                $home['id'],
                $globalProductId,
                null,
                null,
                null,
                $otherCategory['id'],
            ),
        );
        $operator = $this->container->get(OperatorInventoryService::class);
        $operator->createProduct($admin, $home['id'], [
            'id' => '53000000-0000-4000-8000-000000000003',
            'productId' => $globalProductId,
            'homeCategoryId' => $category['id'],
            'expectedRevision' => 0,
            'reason' => 'Set household classification',
        ]);
        $this->problem(
            422,
            fn() => $operator->updateProduct($admin, $home['id'], $created['id'], [
                'privateName' => 'Overwrite catalog name',
                'reason' => 'Invalid global identity edit',
                'expectedRevision' => 1,
            ]),
        );
        self::assertSame(
            'Beans',
            $this->db->fetchOne('SELECT canonical_name FROM products WHERE id = ?', [$globalProductId]),
        );
    }

    public function testReaderScopedHttpBootstrapRecoversBeforeReceivingOperatorEdits(): void
    {
        $admin = $this->systemOwner();
        [$owner, $home] = $this->ownedHome('cursor-owner@example.test');
        $homeId = $home['id'];
        $group = $this->homeGroup($admin, true, 3);
        $this->access()->assign($admin, FeatureCatalog::HOME, $homeId, $group['id'], 1);
        [$member] = $this->ownedHome('cursor-member@example.test', false);
        $invitation = $this->homes()->invite($owner, $homeId, 'cursor-member@example.test', 'member');
        $this->homes()->acceptInvitationById($member, $invitation['invitationId'], 1);
        $inventory = $this->container->get(InventoryService::class);
        $category = $inventory->createHomeCategory($owner, $homeId, 'Draft pantry');
        $product = $inventory->addHomeProduct(
            $owner,
            $homeId,
            null,
            null,
            'Draft beans',
            'Draft pack',
            $category['id'],
        );

        $ownerBootstrap = $this->syncHttp($owner, $homeId, 'bootstrap');
        self::assertSame(200, $ownerBootstrap->getStatusCode());
        $ownerData = $this->syncJson($ownerBootstrap);
        self::assertFalse($ownerData['hasMore']);
        self::assertIsString($ownerData['snapshotCursor']);
        $crossReader = $this->syncHttp($member, $homeId, 'pull', ['cursor' => $ownerData['snapshotCursor']]);
        self::assertSame(410, $crossReader->getStatusCode());
        self::assertSame('application/problem+json', $crossReader->getHeaderLine('Content-Type'));
        $problem = $this->syncJson($crossReader);
        self::assertSame('https://providentia.invalid/problems/sync_resync_required', $problem['type']);
        self::assertArrayNotHasKey('changes', $problem);
        self::assertArrayNotHasKey('records', $problem);
        self::assertSame(0, (int) $this->db->fetchOne(
            'SELECT COUNT(*) FROM sync_cursors WHERE home_id = ? AND user_id = ? AND device_id = ?',
            [$homeId, $member->userId, $member->deviceId],
        ));

        $memberCursor = null;
        $pageCursor = null;
        $seen = [];
        $records = [];
        for ($page = 0; $page < 20; ++$page) {
            $query = ['limit' => '1'];
            if ($pageCursor !== null) {
                $query['cursor'] = $pageCursor;
            }
            $response = $this->syncHttp($member, $homeId, 'bootstrap', $query);
            self::assertSame(200, $response->getStatusCode());
            $data = $this->syncJson($response);
            self::assertIsArray($data['records']);
            $records = [...$records, ...$data['records']];
            self::assertIsBool($data['hasMore']);
            if (! $data['hasMore']) {
                self::assertNull($data['pageCursor']);
                self::assertIsString($data['snapshotCursor']);
                $memberCursor = $data['snapshotCursor'];
                break;
            }
            self::assertNull($data['snapshotCursor']);
            self::assertIsString($data['pageCursor']);
            self::assertNotContains($data['pageCursor'], $seen);
            $pageCursor = $data['pageCursor'];
            $seen[] = $pageCursor;
        }
        self::assertIsString($memberCursor, 'A bounded bootstrap must finish with its own incremental cursor.');
        self::assertNotEmpty($seen, 'The database-backed snapshot must exercise multiple pages.');
        self::assertNotSame($ownerData['snapshotCursor'], $memberCursor);
        self::assertContains($category['id'], array_column($records, 'entityId'));
        self::assertContains($product['id'], array_column($records, 'entityId'));
        $acknowledged = $this->db->fetchOne(
            'SELECT last_acknowledged_cursor FROM sync_cursors WHERE home_id = ? AND user_id = ? AND device_id = ?',
            [$homeId, $member->userId, $member->deviceId],
        );
        self::assertNotFalse($acknowledged);

        $operator = $this->container->get(OperatorInventoryService::class);
        $operator->updateCategory($admin, $homeId, $category['id'], [
            'name' => 'Acceptance pantry', 'expectedRevision' => 1, 'reason' => 'Acceptance category correction',
        ]);
        $operator->updateProduct($admin, $homeId, $product['id'], [
            'privateName' => 'Acceptance baked beans', 'originalPackText' => '400 g tin',
            'expectedRevision' => 1, 'reason' => 'Acceptance product correction',
        ]);
        $pull = $this->syncHttp($member, $homeId, 'pull', ['cursor' => $memberCursor]);
        self::assertSame(200, $pull->getStatusCode());
        $changes = $this->syncJson($pull);
        self::assertFalse($changes['hasMore']);
        self::assertIsArray($changes['changes']);
        $byId = array_column($changes['changes'], null, 'entityId');
        self::assertSame(2, $byId[$category['id']]['revision']);
        self::assertSame('Acceptance pantry', $byId[$category['id']]['representation']['name']);
        self::assertSame(2, $byId[$product['id']]['revision']);
        self::assertSame('Acceptance baked beans', $byId[$product['id']]['representation']['privateName']);
        self::assertSame('400 g tin', $byId[$product['id']]['representation']['originalPackText']);
        self::assertGreaterThan((int) $acknowledged, (int) $this->db->fetchOne(
            'SELECT last_acknowledged_cursor FROM sync_cursors WHERE home_id = ? AND user_id = ? AND device_id = ?',
            [$homeId, $member->userId, $member->deviceId],
        ));
    }

    /** @param array<string, string> $query */
    private function syncHttp(
        AuthenticatedIdentity $identity,
        string $homeId,
        string $action,
        array $query = [],
    ): ResponseInterface {
        // Real handlers, middleware, authorization, migrations and SQLite storage.
        // Identity is supplied from the real login flow; this is not a socket/Compose test.
        $handler = $this->container->get('synchronization.' . $action);
        self::assertInstanceOf(RequestHandlerInterface::class, $handler);
        $request = (new ServerRequest([], [], '/api/v1/homes/' . $homeId . '/sync/' . $action, 'GET'))
            ->withAttribute(BearerAuthenticationMiddleware::ATTRIBUTE, $identity)
            ->withAttribute('homeId', $homeId)
            ->withHeader('X-Request-Id', 'scope-http-regression')
            ->withQueryParams($query);
        return (new ProblemDetailsMiddleware(false, new NullLogger()))->process($request, $handler);
    }

    /** @return array<string, mixed> */
    private function syncJson(ResponseInterface $response): array
    {
        $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        return $data;
    }

    /** @return array{AuthenticatedIdentity, array<string, mixed>} */
    private function ownedHome(string $email, bool $create = true): array
    {
        $owner = $this->login($email);
        $this->onboard($owner);
        $home = $create ? $this->homes()->create($owner, 'Test home', 'en', 'NAD', 'Africa/Windhoek') : [];
        return [$owner, $home];
    }

    /** @return array<string, mixed> */
    private function productStatus(
        AuthenticatedIdentity $owner,
        string $homeId,
        string $productId,
        string $status,
        int $revision,
    ): array {
        return $this->container->get(InventoryService::class)->updateHomeProduct(
            $owner,
            $homeId,
            $productId,
            false,
            null,
            false,
            null,
            false,
            null,
            $status,
            $revision,
        );
    }

    /** @param list<string> $permissions */
    private function approvedAdministrator(
        AuthenticatedIdentity $owner,
        string $email,
        array $permissions,
    ): AuthenticatedIdentity {
        $candidate = $this->login($email, 'admin');
        $group = $this->access()->saveGroup($owner, null, [
            'scope' => 'admin',
            'name' => $email,
            'features' => array_fill_keys($permissions, true),
            'limits' => [],
            'delegablePermissions' => [],
            'rolePermissions' => [],
            'expectedRevision' => 0,
        ]);
        $this->container->get(OperatorWorkspaceService::class)->reviewAdministrator($owner, $candidate->userId, [
            'status' => 'approved',
            'groupId' => $group['id'],
            'expectedRevision' => 1,
            'assignmentRevision' => 0,
        ]);
        return $candidate;
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
