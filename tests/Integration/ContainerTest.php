<?php

declare(strict_types=1);

namespace ProvidentiaTest\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Interop\Queue\Context;
use Laminas\Diactoros\ServerRequest;
use Laminas\Diactoros\Uri;
use Mezzio\Application;
use PHPUnit\Framework\TestCase;
use Providentia\AiIntegration\Application\AiService;
use Providentia\AiIntegration\Application\Media\PrivateMediaService;
use Providentia\Catalog\Application\CatalogSeedService;
use Providentia\Home\Application\HomeService;
use Providentia\Identity\Application\AuthenticationService;
use Providentia\SharedKernel\Application\Async\AsyncMessageBus;
use Providentia\SharedKernel\Application\Async\OutboxStore;
use Providentia\SharedKernel\Http\Health\LivenessHandler;
use Providentia\SharedKernel\Http\NotFoundHandler;
use Providentia\Synchronization\Application\SynchronizationService;
use Psr\Container\ContainerInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

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
        self::assertInstanceOf(Application::class, $this->container->get(Application::class));
        self::assertInstanceOf(Connection::class, $this->container->get(Connection::class));
        self::assertInstanceOf(EntityManagerInterface::class, $this->container->get(EntityManagerInterface::class));
        self::assertInstanceOf(Context::class, $this->container->get(Context::class));
        self::assertInstanceOf(OutboxStore::class, $this->container->get(OutboxStore::class));
        self::assertInstanceOf(AsyncMessageBus::class, $this->container->get(AsyncMessageBus::class));
        self::assertInstanceOf(LivenessHandler::class, $this->container->get(LivenessHandler::class));
        self::assertInstanceOf(NotFoundHandler::class, $this->container->get(NotFoundHandler::class));
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

    public function testHeadlessFallbackReturnsProblemDetailsWithoutHtml(): void
    {
        $response = $this->container->get(NotFoundHandler::class)->handle(
            new ServerRequest([], [], new Uri('http://127.0.0.1/')),
        );

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('application/problem+json', $response->getHeaderLine('Content-Type'));
        self::assertJsonStringEqualsJsonString(json_encode([
            'type' => 'about:blank',
            'title' => 'Not Found',
            'status' => 404,
            'detail' => 'The requested API resource is unavailable.',
            'instance' => '/',
            'requestId' => $response->getHeaderLine('X-Request-Id'),
        ], JSON_THROW_ON_ERROR), (string) $response->getBody());
        self::assertStringNotContainsString('<html', (string) $response->getBody());
    }

    public function testEntityManagerUsesAnExplicitProcessLocalCache(): void
    {
        $entityManager = $this->container->get(EntityManagerInterface::class);

        self::assertInstanceOf(
            ArrayAdapter::class,
            $entityManager->getConfiguration()->getMetadataCache(),
        );
    }
}
