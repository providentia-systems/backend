<?php

declare(strict_types=1);

namespace Providentia\Access\Infrastructure\Factory;

use Doctrine\DBAL\Connection;
use Providentia\Administration\Application\OperatorWorkspaceService;
use Providentia\Administration\Application\OperatorWorkspaceStore;
use Providentia\Administration\Infrastructure\Doctrine\DbalOperatorWorkspaceStore;
use Providentia\Administration\Http\OperatorWorkspaceHandler;
use Providentia\Identity\Application\ProfileMediaService;
use Providentia\Identity\Application\ProfileMediaStore;
use Providentia\Identity\Infrastructure\Doctrine\DbalProfileMediaStore;
use Providentia\Identity\Http\ProfileMediaHandler;
use Providentia\Identity\Infrastructure\Cli\SystemOwnerCommand;
use Providentia\Geography\Infrastructure\Cli\ReferenceUpdateCommand;
use Providentia\Catalog\Application\CatalogImageSanitizer;
use Providentia\Home\Application\HomeAuthorization;

use Providentia\Access\Application\AccessService;
use Providentia\Access\Application\AccessStore;
use Providentia\Access\Infrastructure\Doctrine\DbalAccessStore;
use Providentia\Access\Http\AccessHandler;
use Providentia\Geography\Application\CountryService;
use Providentia\Geography\Application\CountryStore;
use Providentia\Geography\Infrastructure\Doctrine\DbalCountryStore;
use Providentia\Geography\Http\CountryHandler;
use Providentia\Home\Application\HomeStore;
use Providentia\Identity\Application\AccountProfileService;
use Providentia\Identity\Application\AccountProfileStore;
use Providentia\Identity\Application\AuthenticationRateLimiter;
use Providentia\Identity\Application\AuthenticationService;
use Providentia\Identity\Application\CredentialHasher;
use Providentia\Identity\Application\EmailCodeService;
use Providentia\Identity\Application\EmailCodeStore;
use Providentia\Identity\Application\EmailLoginService;
use Providentia\Identity\Application\IdentityStore;
use Providentia\Identity\Application\NotificationOutbox;
use Providentia\Identity\Infrastructure\Doctrine\DbalAccountProfileStore;
use Providentia\Identity\Infrastructure\Doctrine\DbalEmailCodeStore;
use Providentia\Identity\Http\AccountProfileHandler;
use Providentia\Identity\Http\EmailLoginHandler;
use Providentia\SharedKernel\Application\Clock;
use Providentia\SharedKernel\Application\SecureTokenGenerator;
use Providentia\SharedKernel\Application\TransactionManager;
use Providentia\SharedKernel\Application\UuidGenerator;
use Psr\Container\ContainerInterface;

final class AccessFactory
{
    public function __invoke(ContainerInterface $container, string $name): object
    {
        /** @var array{identity: array{web_idle_ttl_seconds: int, native_idle_ttl_seconds: int, cookie_secure: bool}} $config */
        $config = $container->get('config');
        return match (true) {
            $name === SystemOwnerCommand::class => new SystemOwnerCommand($container->get(Connection::class), $container->get(Clock::class)),
            $name === ReferenceUpdateCommand::class => new ReferenceUpdateCommand($container->get(Connection::class)),
            $name === DbalOperatorWorkspaceStore::class => new DbalOperatorWorkspaceStore($container->get(Connection::class)),
            $name === DbalProfileMediaStore::class => new DbalProfileMediaStore($container->get(Connection::class)),
            $name === OperatorWorkspaceService::class => new OperatorWorkspaceService($container->get(OperatorWorkspaceStore::class), $container->get(AccessService::class), $container->get(AccessStore::class), $container->get(AccountProfileStore::class), $container->get(TransactionManager::class), $container->get(Clock::class)),
            $name === ProfileMediaService::class => new ProfileMediaService($container->get(ProfileMediaStore::class), $container->get(AccountProfileStore::class), $container->get(HomeAuthorization::class), $container->get(AccessService::class), $container->get(CountryService::class), $container->get(CatalogImageSanitizer::class), $container->get(TransactionManager::class), $container->get(Clock::class)),
            str_starts_with($name, 'operator-workspace.') => new OperatorWorkspaceHandler($container->get(OperatorWorkspaceService::class), substr($name, 19)),
            str_starts_with($name, 'profile-media.') => new ProfileMediaHandler($container->get(ProfileMediaService::class), substr($name, 14)),
            $name === DbalAccessStore::class => new DbalAccessStore($container->get(Connection::class), $container->get(Clock::class), $container->get(UuidGenerator::class)),
            $name === DbalAccountProfileStore::class => new DbalAccountProfileStore($container->get(Connection::class)),
            $name === DbalEmailCodeStore::class => new DbalEmailCodeStore($container->get(Connection::class)),
            $name === DbalCountryStore::class => new DbalCountryStore($container->get(Connection::class)),
            $name === AccessService::class => new AccessService($container->get(AccessStore::class), $container->get(TransactionManager::class), $container->get(UuidGenerator::class)),
            $name === CountryService::class => new CountryService($container->get(CountryStore::class), $container->get(AccessService::class), $container->get(AccessStore::class), $container->get(Clock::class), $container->get(TransactionManager::class), $container->get(UuidGenerator::class)),
            $name === EmailCodeService::class => new EmailCodeService($container->get(EmailCodeStore::class), $container->get(CredentialHasher::class), $container->get(NotificationOutbox::class), $container->get(AuthenticationRateLimiter::class), $container->get(UuidGenerator::class), $container->get(SecureTokenGenerator::class), $container->get(Clock::class), $container->get(TransactionManager::class)),
            $name === EmailLoginService::class => new EmailLoginService($container->get(EmailCodeService::class), $container->get(IdentityStore::class), $container->get(AccountProfileStore::class), $container->get(AuthenticationService::class), $container->get(AccessService::class), $container->get(UuidGenerator::class), $container->get(Clock::class), $container->get(TransactionManager::class), $config['identity']['web_idle_ttl_seconds'], $config['identity']['native_idle_ttl_seconds']),
            $name === AccountProfileService::class => new AccountProfileService($container->get(AccountProfileStore::class), $container->get(IdentityStore::class), $container->get(HomeStore::class), $container->get(CountryService::class), $container->get(AccessService::class), $container->get(AccessStore::class), $container->get(EmailCodeService::class), $container->get(CredentialHasher::class), $container->get(SecureTokenGenerator::class), $container->get(Clock::class), $container->get(TransactionManager::class), $container->get(UuidGenerator::class)),
            str_starts_with($name, 'email-login.') => new EmailLoginHandler($container->get(EmailLoginService::class), $name === 'email-login.verify', $config['identity']['cookie_secure']),
            str_starts_with($name, 'profile.') => new AccountProfileHandler($container->get(AccountProfileService::class), substr($name, 8)),
            str_starts_with($name, 'access.') => new AccessHandler($container->get(AccessService::class), substr($name, 7)),
            str_starts_with($name, 'country.') => new CountryHandler($container->get(CountryService::class), substr($name, 8)),
            default => throw new \LogicException('Unknown access service: ' . $name),
        };
    }
}
