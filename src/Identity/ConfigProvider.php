<?php

declare(strict_types=1);

namespace Providentia\Identity;

use Providentia\Identity\Application\AccountNotificationSender;
use Providentia\Identity\Application\AuthenticationRateLimiter;
use Providentia\Identity\Application\AuthenticationRateLimitStore;
use Providentia\Identity\Application\AuthenticationService;
use Providentia\Identity\Application\CredentialHasher;
use Providentia\Identity\Application\IdentityStore;
use Providentia\Identity\Application\NotificationDeliveryService;
use Providentia\Identity\Application\NotificationOutbox;
use Providentia\Identity\Application\NotificationPayloadCipher;
use Providentia\Identity\Application\NotificationTransport;
use Providentia\Identity\Application\QueuedAccountNotificationSender;
use Providentia\Identity\Http\BearerAuthenticationMiddleware;
use Providentia\Identity\Http\AuthenticationRateLimitMiddleware;
use Providentia\Identity\Infrastructure\Doctrine\DbalIdentityStore;
use Providentia\Identity\Infrastructure\Doctrine\DbalNotificationOutbox;
use Providentia\Identity\Infrastructure\Cli\NotificationDeliverCommand;
use Providentia\Identity\Infrastructure\Doctrine\DbalAuthenticationRateLimitStore;
use Providentia\Identity\Infrastructure\Factory\IdentityFactory;
use Providentia\Identity\Infrastructure\Notification\SmtpAccountNotificationSender;
use Providentia\Identity\Infrastructure\Security\NativeCredentialHasher;
use Providentia\Identity\Infrastructure\Security\NativeNotificationPayloadCipher;

final class ConfigProvider
{
    /** @return array<string, mixed> */
    public function __invoke(): array
    {
        return [
            'dependencies' => [
                'aliases' => [
                    IdentityStore::class => DbalIdentityStore::class,
                    CredentialHasher::class => NativeCredentialHasher::class,
                    AccountNotificationSender::class => QueuedAccountNotificationSender::class,
                    NotificationOutbox::class => DbalNotificationOutbox::class,
                    NotificationPayloadCipher::class => NativeNotificationPayloadCipher::class,
                    NotificationTransport::class => SmtpAccountNotificationSender::class,
                    AuthenticationRateLimitStore::class => DbalAuthenticationRateLimitStore::class,
                ],
                'factories' => [
                    DbalIdentityStore::class => IdentityFactory::class,
                    DbalAuthenticationRateLimitStore::class => IdentityFactory::class,
                    NativeCredentialHasher::class => IdentityFactory::class,
                    SmtpAccountNotificationSender::class => IdentityFactory::class,
                    DbalNotificationOutbox::class => IdentityFactory::class,
                    NativeNotificationPayloadCipher::class => IdentityFactory::class,
                    QueuedAccountNotificationSender::class => IdentityFactory::class,
                    NotificationDeliveryService::class => IdentityFactory::class,
                    NotificationDeliverCommand::class => IdentityFactory::class,
                    AuthenticationService::class => IdentityFactory::class,
                    BearerAuthenticationMiddleware::class => IdentityFactory::class,
                    AuthenticationRateLimiter::class => IdentityFactory::class,
                    AuthenticationRateLimitMiddleware::class => IdentityFactory::class,
                    'identity.register' => IdentityFactory::class,
                    'identity.magic-link-request' => IdentityFactory::class,
                    'identity.magic-link-exchange' => IdentityFactory::class,
                    'identity.step-up-request' => IdentityFactory::class,
                    'identity.verify' => IdentityFactory::class,
                    'identity.resend-verification' => IdentityFactory::class,
                    'identity.login' => IdentityFactory::class,
                    'identity.refresh' => IdentityFactory::class,
                    'identity.request-reset' => IdentityFactory::class,
                    'identity.reset' => IdentityFactory::class,
                    'identity.sessions' => IdentityFactory::class,
                    'identity.revoke-session' => IdentityFactory::class,
                    'identity.logout' => IdentityFactory::class,
                ],
            ],
            'laminas-cli' => [
                'commands' => [
                    'notification:deliver' => NotificationDeliverCommand::class,
                ],
            ],
        ];
    }
}
