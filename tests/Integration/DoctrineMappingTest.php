<?php

declare(strict_types=1);

namespace ProvidentiaTest\Integration;

use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Providentia\SharedKernel\Domain\FoundationRecord;
use Psr\Container\ContainerInterface;

final class DoctrineMappingTest extends TestCase
{
    public function testInfrastructureMappingDoesNotLeakIntoDomainModel(): void
    {
        putenv('DATABASE_URL=sqlite:///:memory:');
        /** @var ContainerInterface $container */
        $container = require dirname(__DIR__, 2) . '/config/container.php';
        $metadata = $container->get(EntityManagerInterface::class)
            ->getClassMetadata(FoundationRecord::class);

        self::assertSame('foundation_records', $metadata->getTableName());
        self::assertSame(['id'], $metadata->getIdentifierFieldNames());
        self::assertStringNotContainsString('Doctrine', (string) file_get_contents(
            dirname(__DIR__, 2) . '/src/SharedKernel/Domain/FoundationRecord.php',
        ));
    }
}
