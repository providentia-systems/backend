<?php

declare(strict_types=1);

namespace Providentia\Home\Infrastructure\Factory;

use Doctrine\DBAL\Connection;
use Providentia\Home\Application\HomeAuthorization;
use Providentia\Home\Application\HomeService;
use Providentia\Home\Application\HomeStore;
use Providentia\Home\Http\HomeHandler;
use Providentia\Home\Infrastructure\Doctrine\DbalHomeStore;
use Providentia\Identity\Application\CredentialHasher;
use Providentia\Identity\Application\AccountNotificationSender;
use Providentia\Identity\Application\IdentityStore;
use Providentia\SharedKernel\Application\Clock;
use Providentia\SharedKernel\Application\TransactionManager;
use Providentia\SharedKernel\Application\UuidGenerator;
use Psr\Container\ContainerInterface;

final class HomeFactory
{
    public function __invoke(ContainerInterface $container, string $requestedName): object
    {
        /** @var array{identity: array{expose_development_tokens: bool}} $config */
        $config = $container->get('config');

        return match (true) {
            $requestedName === DbalHomeStore::class => new DbalHomeStore($container->get(Connection::class)),
            $requestedName === HomeAuthorization::class => new HomeAuthorization(
                $container->get(HomeStore::class),
            ),
            $requestedName === HomeService::class => new HomeService(
                $container->get(HomeStore::class),
                $container->get(HomeAuthorization::class),
                $container->get(IdentityStore::class),
                $container->get(CredentialHasher::class),
                $container->get(AccountNotificationSender::class),
                $container->get(UuidGenerator::class),
                $container->get(Clock::class),
                $container->get(TransactionManager::class),
            ),
            str_starts_with($requestedName, 'home.') => new HomeHandler(
                $container->get(HomeService::class),
                substr($requestedName, strlen('home.')),
                $config['identity']['expose_development_tokens'],
            ),
            default => throw new \LogicException('Unsupported home service: ' . $requestedName),
        };
    }
}
