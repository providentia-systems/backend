<?php

declare(strict_types=1);

namespace Providentia\Administration;

use Providentia\Administration\Application\BaselineImportService;
use Providentia\Administration\Application\BaselineImportStore;
use Providentia\Administration\Application\OperatorAccountService;
use Providentia\Administration\Infrastructure\Cli\BaselineImportCommand;
use Providentia\Administration\Infrastructure\Doctrine\DbalBaselineImportStore;
use Providentia\Administration\Infrastructure\Factory\AdministrationFactory;

final class ConfigProvider
{
    /** @return array<string, mixed> */
    public function __invoke(): array
    {
        return [
            'dependencies' => [
                'aliases' => [
                    BaselineImportStore::class => DbalBaselineImportStore::class,
                ],
                'factories' => [
                    DbalBaselineImportStore::class => AdministrationFactory::class,
                    BaselineImportService::class => AdministrationFactory::class,
                    BaselineImportCommand::class => AdministrationFactory::class,
                    OperatorAccountService::class => AdministrationFactory::class,
                    'administration.operator-accounts-list' => AdministrationFactory::class,
                    'administration.operator-accounts-get' => AdministrationFactory::class,
                    'administration.operator-accounts-status' => AdministrationFactory::class,
                    'administration.operator-accounts-role-grant' => AdministrationFactory::class,
                    'administration.operator-accounts-role-revoke' => AdministrationFactory::class,
                ],
            ],
            'laminas-cli' => [
                'commands' => [
                    'baseline:import' => BaselineImportCommand::class,
                ],
            ],
        ];
    }
}
