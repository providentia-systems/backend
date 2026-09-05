<?php

declare(strict_types=1);

namespace ProvidentiaTest\Support;

use PHPUnit\Framework\TestCase;
use Providentia\Access\Application\AccessService;
use Providentia\Access\Application\AccessStore;
use Providentia\Access\Domain\FeatureCatalog;
use Providentia\Geography\Application\CountryService;
use Providentia\Identity\Application\AccountProfileService;
use Providentia\Identity\Application\AccountProfileStore;
use Providentia\Identity\Application\AuthenticationService;
use Providentia\SharedKernel\Application\TransactionManager;
use Providentia\SharedKernel\Infrastructure\Identifier\RamseyUuidGenerator;

/** Explicit generous group fixture for existing module tests; production defaults are tested with a real database. */
final class AccessFixture extends TestCase
{
    public static function create(): AccessService
    {
        $fixture = new self('fixture');
        $store = $fixture->createStub(AccessStore::class);
        $store->method('assignment')
            ->willReturnCallback(
                static function (string $scope, string $subject): array {
                    $group = FeatureCatalog::defaults()[$scope === 'home'
                    ? 2
                    : ($scope === 'admin'
                    ? 3
                    : 0)];
                    $group['features'] = array_fill_keys(FeatureCatalog::features($scope), true);
                    $group['rolePermissions']['manager'][] = 'billing.read';
                    $group['limits'] = array_fill_keys(FeatureCatalog::limits($scope), -1);
                    return [
                    ...$group,
                    'groupId' => $group['id'],
                    'groupRevision' => 1,
                    ];
                },
            );
        $transactions = $fixture->createStub(TransactionManager::class);
        $transactions->method('transactional')
            ->willReturnCallback(
                static fn(callable $operation): mixed => $operation(),
            );
        return new AccessService($store, $transactions, new RamseyUuidGenerator());
    }

    /**
     * @param list<string> $roles
     * @return list<string> */
    public static function administratorPermissions(array $roles): array
    {
        if (in_array('platform_administrator', $roles, true)) {
            return FeatureCatalog::features(FeatureCatalog::ADMIN);
        }
        $permissions = [];
        foreach ($roles as $role) {
            $permissions = [
                ...$permissions,
                ...match ($role) {
                    'catalog_curator' => ['catalog.read', 'catalog.curate'],
                    'catalog_reviewer' => ['catalog.read', 'catalog.review'],
                    'billing_operator' => ['billing.read', 'billing.manage'],
                    default => [],
                },
            ];
        }
        return array_values(array_unique($permissions));
    }

    public static function authentication(): AuthenticationService
    {
        return self::container()->get(AuthenticationService::class);
    }

    public static function countries(): CountryService
    {
        return self::container()->get(CountryService::class);
    }

    public static function profile(): AccountProfileService
    {
        $container = self::container();
        $container->setService(
            AccountProfileStore::class,
            self::profileEmails(),
        );
        $container->setService(AccessService::class, self::create());
        return $container->get(AccountProfileService::class);
    }

    public static function profileEmails(): AccountProfileStore
    {
        $fixture = new self('fixture');
        $store = $fixture->createStub(AccountProfileStore::class);
        $store->method('profile')
            ->willReturn([]);
        $store->method('emails')
            ->willReturn([['email' => 'person@example.com']]);
        return $store;
    }

    private static function container(): \Laminas\ServiceManager\ServiceManager
    {
        return require dirname(__DIR__, 2) . '/config/container.php';
    }

    public function fixture(): void
    {
    }
}
