<?php

declare(strict_types=1);

namespace Providentia\Identity\Infrastructure\Factory;

use Doctrine\DBAL\Connection;
use Providentia\Home\Application\HomeStore;
use Providentia\Identity\Application\AccountNotificationSender;
use Providentia\Identity\Application\AuthenticationRateLimiter;
use Providentia\Identity\Application\AuthenticationRateLimitStore;
use Providentia\Identity\Application\AuthenticationService;
use Providentia\Identity\Application\CredentialHasher;
use Providentia\Identity\Application\CurrentUserService;
use Providentia\Identity\Application\IdentityStore;
use Providentia\Identity\Application\NotificationDeliveryService;
use Providentia\Identity\Application\NotificationOutbox;
use Providentia\Identity\Application\NotificationPayloadCipher;
use Providentia\Identity\Application\NotificationTransport;
use Providentia\Identity\Application\PlatformAdministratorService;
use Providentia\Identity\Application\PlatformRoleService;
use Providentia\Identity\Application\PlatformRoleStore;
use Providentia\Identity\Application\QueuedAccountNotificationSender;
use Providentia\Identity\Http\AuthenticationRateLimitMiddleware;
use Providentia\Identity\Http\BearerAuthenticationMiddleware;
use Providentia\Identity\Http\CurrentUserHandler;
use Providentia\Identity\Http\IdentityHandler;
use Providentia\Identity\Http\PlatformAdministratorHandler;
use Providentia\Identity\Infrastructure\Doctrine\DbalIdentityStore;
use Providentia\Identity\Infrastructure\Doctrine\DbalNotificationOutbox;
use Providentia\Identity\Infrastructure\Cli\NotificationDeliverCommand;
use Providentia\Identity\Infrastructure\Cli\PlatformRoleCommand;
use Providentia\Identity\Infrastructure\Doctrine\DbalAuthenticationRateLimitStore;
use Providentia\Identity\Infrastructure\Notification\SmtpAccountNotificationSender;
use Providentia\Identity\Infrastructure\Security\NativeCredentialHasher;
use Providentia\Identity\Infrastructure\Security\NativeNotificationPayloadCipher;
use Providentia\SharedKernel\Application\Clock;
use Providentia\SharedKernel\Application\SecureTokenGenerator;
use Providentia\SharedKernel\Application\TransactionManager;
use Providentia\SharedKernel\Application\UuidGenerator;
use Psr\Container\ContainerInterface;

final class IdentityFactory
{
    public function __invoke(ContainerInterface $container, string $requestedName): object
    {
        /**
         * @var array{
         *   identity: array{
         *     access_ttl_seconds: int,
         *     refresh_ttl_seconds: int,
         *     web_idle_ttl_seconds: int,
         *     native_idle_ttl_seconds: int,
         *     login_link_ttl_seconds: int,
         *     login_link_exchange_ttl_seconds: int,
         *     login_link_poll_interval_seconds: int,
         *     login_link_retention_days: int,
         *     rate_limit_retention_days: int,
         *     bootstrap_administrator_emails: list<string>,
         *     application_links: array{homeowner: string, admin: string},
         *     onboarding_home: array{name: string, locale: string, currency: string, timezone: string},
         *     token_pepper: string,
         *     expose_development_tokens: bool,
         *     cookie_secure: bool
         *   },
         *   mail: array{
         *     dsn: string,
         *     from: string,
         *     public_base_url: string,
         *     public_origin: string,
         *     notification_payload_kek: string,
         *     notification_key_version: int,
         *     batch_size: int,
         *     max_attempts: int
         *   }
         * } $config
         */
        $config = $container->get('config');

        if ($requestedName === DbalIdentityStore::class) {
            return new DbalIdentityStore($container->get(Connection::class));
        }
        if ($requestedName === DbalAuthenticationRateLimitStore::class) {
            return new DbalAuthenticationRateLimitStore($container->get(Connection::class));
        }
        if ($requestedName === NativeCredentialHasher::class) {
            return new NativeCredentialHasher($config['identity']['token_pepper']);
        }
        if ($requestedName === SmtpAccountNotificationSender::class) {
            return new SmtpAccountNotificationSender(
                $config['mail']['dsn'],
                $config['mail']['from'],
                $config['mail']['public_base_url'],
                $config['identity']['application_links'],
            );
        }
        if ($requestedName === NativeNotificationPayloadCipher::class) {
            return new NativeNotificationPayloadCipher(
                $config['mail']['notification_payload_kek'],
                $config['mail']['notification_key_version'],
            );
        }
        if ($requestedName === DbalNotificationOutbox::class) {
            return new DbalNotificationOutbox(
                $container->get(Connection::class),
                $container->get(NotificationPayloadCipher::class),
            );
        }
        if ($requestedName === QueuedAccountNotificationSender::class) {
            return new QueuedAccountNotificationSender(
                $container->get(NotificationOutbox::class),
                $container->get(UuidGenerator::class),
                $container->get(Clock::class),
            );
        }
        if ($requestedName === NotificationDeliveryService::class) {
            return new NotificationDeliveryService(
                $container->get(NotificationOutbox::class),
                $container->get(NotificationTransport::class),
                $container->get(Clock::class),
                $config['mail']['batch_size'],
                $config['mail']['max_attempts'],
            );
        }
        if ($requestedName === NotificationDeliverCommand::class) {
            return new NotificationDeliverCommand($container->get(NotificationDeliveryService::class));
        }
        if ($requestedName === PlatformRoleCommand::class) {
            return new PlatformRoleCommand($container->get(PlatformRoleService::class));
        }
        if ($requestedName === AuthenticationService::class) {
            return new AuthenticationService(
                $container->get(IdentityStore::class),
                $container->get(CredentialHasher::class),
                $container->get(AccountNotificationSender::class),
                $container->get(UuidGenerator::class),
                $container->get(Clock::class),
                $container->get(TransactionManager::class),
                $container->get(SecureTokenGenerator::class),
                $config['identity']['access_ttl_seconds'],
                $config['identity']['refresh_ttl_seconds'],
                $config['identity']['web_idle_ttl_seconds'],
                $config['identity']['native_idle_ttl_seconds'],
                $container->get(\Providentia\Access\Application\AccessService::class),
            );
        }
        if ($requestedName === CurrentUserService::class) {
            return new CurrentUserService(
                $container->get(IdentityStore::class),
                $container->get(HomeStore::class),
                $container->get(Clock::class),
                $container->get(\Providentia\Identity\Application\AccountProfileService::class),
                $container->get(\Providentia\Identity\Application\AccountProfileStore::class),
            );
        }
        if ($requestedName === PlatformAdministratorService::class) {
            return new PlatformAdministratorService(
                $container->get(IdentityStore::class),
                $container->get(UuidGenerator::class),
                $container->get(Clock::class),
                $container->get(TransactionManager::class),
                $container->get(AccountNotificationSender::class),
            );
        }
        if ($requestedName === PlatformRoleService::class) {
            return new PlatformRoleService(
                $container->get(PlatformRoleStore::class),
                $container->get(UuidGenerator::class),
                $container->get(Clock::class),
                $container->get(TransactionManager::class),
            );
        }
        if ($requestedName === BearerAuthenticationMiddleware::class) {
            return new BearerAuthenticationMiddleware($container->get(AuthenticationService::class));
        }
        if ($requestedName === 'identity.me') {
            return new CurrentUserHandler($container->get(CurrentUserService::class));
        }
        if ($requestedName === AuthenticationRateLimiter::class) {
            return new AuthenticationRateLimiter(
                $container->get(AuthenticationRateLimitStore::class),
                $container->get(Clock::class),
                $config['identity']['token_pepper'],
            );
        }
        if ($requestedName === AuthenticationRateLimitMiddleware::class) {
            return new AuthenticationRateLimitMiddleware(
                $container->get(AuthenticationRateLimiter::class),
            );
        }
        if (str_starts_with($requestedName, 'identity.platform-administrators-')) {
            return new PlatformAdministratorHandler(
                $container->get(PlatformAdministratorService::class),
                substr($requestedName, strlen('identity.platform-administrators-')),
            );
        }
        if (str_starts_with($requestedName, 'identity.')) {
            return new IdentityHandler(
                $container->get(AuthenticationService::class),
                substr($requestedName, strlen('identity.')),
                $config['identity']['expose_development_tokens'],
                $config['identity']['cookie_secure'],
            );
        }

        throw new \LogicException('Unsupported identity service: ' . $requestedName);
    }
}
