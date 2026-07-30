<?php

declare(strict_types=1);

namespace ProvidentiaTest\Unit\SharedKernel;

use PHPUnit\Framework\TestCase;
use Providentia\SharedKernel\Application\Health\DatabaseReadinessProbe;
use Providentia\SharedKernel\Application\Health\QueueReadinessProbe;
use Providentia\SharedKernel\Application\ReadinessService;

final class ReadinessServiceTest extends TestCase
{
    public function testAllNonDownChecksAreReady(): void
    {
        $database = $this->createStub(DatabaseReadinessProbe::class);
        $database->method('check')->willReturn(['status' => 'up']);
        $queue = $this->createStub(QueueReadinessProbe::class);
        $queue->method('check')->willReturn(['status' => 'degraded', 'detail' => 'optional']);

        $result = (new ReadinessService($database, $queue))->check();

        self::assertSame('ready', $result['status']);
        self::assertSame('up', $result['checks']['database']['status']);
        self::assertSame('degraded', $result['checks']['queue']['status']);
    }

    public function testAnyDownCheckMakesApplicationNotReady(): void
    {
        $database = $this->createStub(DatabaseReadinessProbe::class);
        $database->method('check')->willReturn(['status' => 'down', 'detail' => 'offline']);
        $queue = $this->createStub(QueueReadinessProbe::class);
        $queue->method('check')->willReturn(['status' => 'up']);

        $result = (new ReadinessService($database, $queue))->check();

        self::assertSame('not_ready', $result['status']);
    }
}
