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
use Providentia\Catalog\Application\CatalogContributionService;
use Providentia\Geography\Application\CountryService;
use Providentia\Home\Application\HomeAuthorization;
use Providentia\Home\Application\HomeService;
use Providentia\Identity\Application\AccountProfileService;
use Providentia\Identity\Application\AuthenticatedIdentity;
use Providentia\Identity\Application\AuthenticationService;
use Providentia\Identity\Application\EmailLoginService;
use Providentia\Identity\Application\NotificationOutbox;
use Providentia\Identity\Application\ProfileMediaService;
use Providentia\Inventory\Application\InventoryService;
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
