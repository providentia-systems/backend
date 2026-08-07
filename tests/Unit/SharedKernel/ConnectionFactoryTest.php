<?php

declare(strict_types=1);

namespace ProvidentiaTest\Unit\SharedKernel;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Providentia\SharedKernel\Infrastructure\Factory\ConnectionFactory;
use Psr\Container\ContainerInterface;
use RuntimeException;

final class ConnectionFactoryTest extends TestCase
{
    /**
     * @return iterable<string, array{url: string, extension: string, package: string}>
     */
    public static function missingDriverProvider(): iterable
    {
        yield 'SQLite' => [
            'url' => 'sqlite:///:memory:',
            'extension' => 'pdo_sqlite',
            'package' => 'php8.5-sqlite3',
        ];
        yield 'MySQL' => [
            'url' => 'mysql://user:password@database:3306/providentia',
            'extension' => 'pdo_mysql',
            'package' => 'php8.5-mysql',
        ];
    }

    #[DataProvider('missingDriverProvider')]
    public function testMissingSelectedDriverFailsWithActionableGuidance(
        string $url,
        string $extension,
        string $package,
    ): void {
        $factory = new ConnectionFactory(static fn (): array => []);

        try {
            $factory($this->containerWithDatabaseUrl($url));
            self::fail('The missing PDO driver was not rejected.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString($extension, $exception->getMessage());
            self::assertStringContainsString($package, $exception->getMessage());
            self::assertStringContainsString('Docker Compose', $exception->getMessage());
        }
    }

    /** @return iterable<string, array{url: string, availableDriver: string}> */
    public static function availableDriverProvider(): iterable
    {
        yield 'SQLite' => ['url' => 'sqlite:///:memory:', 'availableDriver' => 'sqlite'];
        yield 'MySQL' => [
            'url' => 'mysql://user:password@database:3306/providentia',
            'availableDriver' => 'mysql',
        ];
    }

    #[DataProvider('availableDriverProvider')]
    public function testAvailableSelectedDriverBuildsALazyDoctrineConnection(
        string $url,
        string $availableDriver,
    ): void {
        $factory = new ConnectionFactory(static fn (): array => [$availableDriver]);

        self::assertInstanceOf(Connection::class, $factory($this->containerWithDatabaseUrl($url)));
    }

    private function containerWithDatabaseUrl(string $url): ContainerInterface
    {
        return new class ($url) implements ContainerInterface {
            public function __construct(private readonly string $url)
            {
            }

            public function get(string $id): mixed
            {
                if ($id !== 'config') {
                    throw new RuntimeException('Unexpected service requested: ' . $id);
                }

                return ['database' => ['url' => $this->url]];
            }

            public function has(string $id): bool
            {
                return $id === 'config';
            }
        };
    }
}
