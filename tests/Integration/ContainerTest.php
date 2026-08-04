<?php

declare(strict_types=1);

namespace ProvidentiaTest\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Interop\Queue\Context;
use PHPUnit\Framework\TestCase;
use Providentia\AiIntegration\Application\AiService;
use Providentia\AiIntegration\Application\Media\PrivateMediaService;
use Providentia\Catalog\Application\CatalogSeedService;
use Providentia\Home\Application\HomeService;
use Providentia\Identity\Application\AuthenticationService;
use Providentia\PublicSite\Http\HomePageHandler;
use Providentia\SharedKernel\Application\Async\AsyncMessageBus;
use Providentia\SharedKernel\Application\Async\OutboxStore;
use Providentia\SharedKernel\Http\Health\LivenessHandler;
use Providentia\Synchronization\Application\SynchronizationService;
use Psr\Container\ContainerInterface;

final class ContainerTest extends TestCase
{
    private ContainerInterface $container;

    protected function setUp(): void
    {
        putenv('APP_ENV=test');
        putenv('DATABASE_URL=sqlite:///:memory:');
        putenv('QUEUE_DSN=redis+phpredis://127.0.0.1:6379');
        $this->container = require dirname(__DIR__, 2) . '/config/container.php';
    }

    public function testExplicitFactoriesCompileTheFoundationObjectGraph(): void
    {
        self::assertInstanceOf(Connection::class, $this->container->get(Connection::class));
        self::assertInstanceOf(EntityManagerInterface::class, $this->container->get(EntityManagerInterface::class));
        self::assertInstanceOf(Context::class, $this->container->get(Context::class));
        self::assertInstanceOf(OutboxStore::class, $this->container->get(OutboxStore::class));
        self::assertInstanceOf(AsyncMessageBus::class, $this->container->get(AsyncMessageBus::class));
        self::assertInstanceOf(LivenessHandler::class, $this->container->get(LivenessHandler::class));
        self::assertInstanceOf(HomePageHandler::class, $this->container->get(HomePageHandler::class));
        self::assertInstanceOf(AuthenticationService::class, $this->container->get(AuthenticationService::class));
        self::assertInstanceOf(HomeService::class, $this->container->get(HomeService::class));
        self::assertInstanceOf(CatalogSeedService::class, $this->container->get(CatalogSeedService::class));
        self::assertInstanceOf(AiService::class, $this->container->get(AiService::class));
        self::assertInstanceOf(
            PrivateMediaService::class,
            $this->container->get(PrivateMediaService::class),
        );
        self::assertInstanceOf(
            SynchronizationService::class,
            $this->container->get(SynchronizationService::class),
        );
    }
}
