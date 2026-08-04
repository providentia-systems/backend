<?php

declare(strict_types=1);

namespace Providentia\Home;

use Providentia\Home\Application\HomeAuthorization;
use Providentia\Home\Application\HomeAuditRecorder;
use Providentia\Home\Application\HomeService;
use Providentia\Home\Application\HomeStore;
use Providentia\Home\Infrastructure\Doctrine\DbalHomeStore;
use Providentia\Home\Infrastructure\Factory\HomeFactory;

final class ConfigProvider
{
    /** @return array<string, mixed> */
    public function __invoke(): array
    {
        return [
            'dependencies' => [
                'aliases' => [
                    HomeStore::class => DbalHomeStore::class,
                    HomeAuditRecorder::class => DbalHomeStore::class,
                ],
                'factories' => [
                    DbalHomeStore::class => HomeFactory::class,
                    HomeAuthorization::class => HomeFactory::class,
                    HomeService::class => HomeFactory::class,
                    'home.create' => HomeFactory::class,
                    'home.list' => HomeFactory::class,
                    'home.get' => HomeFactory::class,
                    'home.memberships' => HomeFactory::class,
                    'home.permission-policies' => HomeFactory::class,
                    'home.configure-permissions' => HomeFactory::class,
                    'home.invitations' => HomeFactory::class,
                    'home.invite' => HomeFactory::class,
                    'home.revoke-invitation' => HomeFactory::class,
                    'home.accept-invitation' => HomeFactory::class,
                    'home.switch' => HomeFactory::class,
                    'home.change-role' => HomeFactory::class,
                    'home.transfer-ownership' => HomeFactory::class,
                    'home.ownership-transfers' => HomeFactory::class,
                    'home.propose-ownership-transfer' => HomeFactory::class,
                    'home.accept-ownership-transfer' => HomeFactory::class,
                    'home.reject-ownership-transfer' => HomeFactory::class,
                    'home.revoke-ownership-transfer' => HomeFactory::class,
                    'home.leave' => HomeFactory::class,
                ],
            ],
        ];
    }
}
