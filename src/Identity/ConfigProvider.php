<?php

declare(strict_types=1);

namespace Providentia\Identity;

use Providentia\Identity\Application\AccountNotificationSender;
use Providentia\Identity\Application\AuthenticationRateLimiter;
use Providentia\Identity\Application\AuthenticationRateLimitStore;
use Providentia\Identity\Application\AuthenticationService;
use Providentia\Identity\Application\CredentialHasher;
use Providentia\Identity\Application\IdentityStore;
use Providentia\Identity\Http\BearerAuthenticationMiddleware;
use Providentia\Identity\Http\AuthenticationRateLimitMiddleware;
use Providentia\Identity\Infrastructure\Doctrine\DbalIdentityStore;
use Providentia\Identity\Infrastructure\Doctrine\DbalAuthenticationRateLimitStore;
use Providentia\Identity\Infrastructure\Factory\IdentityFactory;
use Providentia\Identity\Infrastructure\Notification\SmtpAccountNotificationSender;
use Providentia\Identity\Infrastructure\Security\NativeCredentialHasher;

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
                    AccountNotificationSender::class => SmtpAccountNotificationSender::class,
                    AuthenticationRateLimitStore::class => DbalAuthenticationRateLimitStore::class,
                ],
                'factories' => [
                    DbalIdentityStore::class => IdentityFactory::class,
                    DbalAuthenticationRateLimitStore::class => IdentityFactory::class,
                    NativeCredentialHasher::class => IdentityFactory::class,
                    SmtpAccountNotificationSender::class => IdentityFactory::class,
                    AuthenticationService::class => IdentityFactory::class,
                    BearerAuthenticationMiddleware::class => IdentityFactory::class,
                    AuthenticationRateLimiter::class => IdentityFactory::class,
                    AuthenticationRateLimitMiddleware::class => IdentityFactory::class,
                    'identity.register' => IdentityFactory::class,
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
        ];
    }
}
