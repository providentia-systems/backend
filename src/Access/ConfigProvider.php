<?php

declare(strict_types=1);

namespace Providentia\Access;

use Providentia\Access\Infrastructure\Factory\AccessFactory;

final class ConfigProvider
{
    /** @return array<string, mixed> */
    public function __invoke(): array
    {
        return [
            'dependencies'
                => [
                'aliases'
                    => [
                    \Providentia\Identity\Application\ProfileMediaStore::class
                        => \Providentia\Identity\Infrastructure\Doctrine\DbalProfileMediaStore::class,
                    \Providentia\Administration\Application\OperatorWorkspaceStore::class
                        => \Providentia\Administration\Infrastructure\Doctrine\DbalOperatorWorkspaceStore::class,
                    \Providentia\Access\Application\AccessStore::class
                        => \Providentia\Access\Infrastructure\Doctrine\DbalAccessStore::class,
                    \Providentia\Identity\Application\AccountProfileStore::class
                        => \Providentia\Identity\Infrastructure\Doctrine\DbalAccountProfileStore::class,
                    \Providentia\Identity\Application\EmailCodeStore::class
                        => \Providentia\Identity\Infrastructure\Doctrine\DbalEmailCodeStore::class,
                    \Providentia\Geography\Application\CountryStore::class
                        => \Providentia\Geography\Infrastructure\Doctrine\DbalCountryStore::class,
                ],
                'factories'
                    => [
                    \Providentia\Administration\Application\OperatorWorkspaceService::class
                        => AccessFactory::class,
                    \Providentia\Administration\Infrastructure\Doctrine\DbalOperatorWorkspaceStore::class
                        => AccessFactory::class,
                    \Providentia\Identity\Application\ProfileMediaService::class => AccessFactory::class,
                    \Providentia\Identity\Infrastructure\Doctrine\DbalProfileMediaStore::class
                        => AccessFactory::class,
                    \Providentia\Identity\Infrastructure\Cli\SystemOwnerCommand::class
                        => AccessFactory::class,
                    \Providentia\Geography\Infrastructure\Cli\ReferenceUpdateCommand::class
                        => AccessFactory::class,
                    'operator-workspace.homes' => AccessFactory::class,
                    'operator-workspace.home' => AccessFactory::class,
                    'operator-workspace.records' => AccessFactory::class,
                    'operator-workspace.administrators' => AccessFactory::class,
                    'operator-workspace.review-administrator' => AccessFactory::class,
                    'operator-workspace.audit' => AccessFactory::class,
                    'profile-media.home-profile' => AccessFactory::class,
                    'profile-media.image' => AccessFactory::class,
                    'profile-media.operator-image' => AccessFactory::class,
                    'profile-media.gravatar' => AccessFactory::class,
                    \Providentia\Access\Infrastructure\Doctrine\DbalAccessStore::class
                        => AccessFactory::class,
                    \Providentia\Identity\Infrastructure\Doctrine\DbalAccountProfileStore::class
                        => AccessFactory::class,
                    \Providentia\Identity\Infrastructure\Doctrine\DbalEmailCodeStore::class
                        => AccessFactory::class,
                    \Providentia\Geography\Infrastructure\Doctrine\DbalCountryStore::class
                        => AccessFactory::class,
                    \Providentia\Access\Application\AccessService::class => AccessFactory::class,
                    \Providentia\Identity\Application\EmailCodeService::class => AccessFactory::class,
                    \Providentia\Identity\Application\EmailLoginService::class => AccessFactory::class,
                    \Providentia\Identity\Application\AccountProfileService::class
                        => AccessFactory::class,
                    \Providentia\Geography\Application\CountryService::class => AccessFactory::class,
                    'email-login.request' => AccessFactory::class,
                    'email-login.verify' => AccessFactory::class,
                    'profile.get' => AccessFactory::class,
                    'profile.update' => AccessFactory::class,
                    'profile.onboard' => AccessFactory::class,
                    'profile.email-request' => AccessFactory::class,
                    'profile.email-verify' => AccessFactory::class,
                    'profile.security-request' => AccessFactory::class,
                    'profile.security-verify' => AccessFactory::class,
                    'profile.email-primary' => AccessFactory::class,
                    'profile.email-remove' => AccessFactory::class,
                    'access.list' => AccessFactory::class,
                    'access.create' => AccessFactory::class,
                    'access.update' => AccessFactory::class,
                    'access.assign' => AccessFactory::class,
                    'access.catalog' => AccessFactory::class,
                    'country.list' => AccessFactory::class,
                    'country.policy' => AccessFactory::class,
                    'country.states' => AccessFactory::class,
                    'country.cities' => AccessFactory::class,
                    'country.admin-list' => AccessFactory::class,
                    'country.settings' => AccessFactory::class,
                    'country.configure' => AccessFactory::class,
                    'country.policies' => AccessFactory::class,
                    'country.policy-create' => AccessFactory::class,
                    'country.policy-update' => AccessFactory::class,
                    'country.jobs' => AccessFactory::class,
                    'country.update' => AccessFactory::class,
                ],
            ],
            'laminas-cli'
                => [
                'commands'
                    => [
                    'system:owner' => \Providentia\Identity\Infrastructure\Cli\SystemOwnerCommand::class,
                    'reference:update'
                        => \Providentia\Geography\Infrastructure\Cli\ReferenceUpdateCommand::class,
                ],
            ],
        ];
    }
}
