<?php

declare(strict_types=1);

namespace ProvidentiaTest\Unit\Catalog;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Providentia\Catalog\Application\CatalogAuthorization;
use Providentia\Catalog\Application\CatalogMaintenanceService;
use Providentia\Catalog\Application\CatalogMaintenanceStore;
use Providentia\Identity\Application\AuthenticatedIdentity;
use Providentia\SharedKernel\Application\Problem;
use ProvidentiaTest\Unit\Home\HomeFixedClock;
use ProvidentiaTest\Unit\Home\RecordingTransactionManager;

final class CatalogMaintenanceServiceTest extends TestCase
{
    public function testAHomeownerCannotReadOrWriteGlobalMaintenance(): void
    {
        $store = $this->createMock(CatalogMaintenanceStore::class);
        $store->expects(self::never())->method('entities');
        $store->expects(self::never())->method('saveEntity');
        $service = new CatalogMaintenanceService(
            $store,
            new CatalogAuthorization(),
            new HomeFixedClock(new DateTimeImmutable('2026-09-12T12:00:00Z')),
            new RecordingTransactionManager(),
        );
        $identity = new AuthenticatedIdentity('user', 'session', 'device', 'home', []);
        foreach (['read', 'write'] as $operation) {
            try {
                if ($operation === 'read') {
                    $service->list($identity, 'category', 0);
                } else {
                    $service->save($identity, 'category', '01912345-6789-7abc-8def-0123456789ab', []);
                }
                self::fail('Homeowner reached catalog maintenance.');
            } catch (Problem $problem) {
                self::assertSame(403, $problem->status);
            }
        }
    }

    public function testReviewerSearchPreservesProductFilterAndPagination(): void
    {
        $productId = '0198a0b1-c2d3-7e4f-8123-456789abcdef';
        $store = $this->createMock(CatalogMaintenanceStore::class);
        $store->expects(self::once())->method('entities')
            ->with('pack', 100, $productId, 'Rice')
            ->willReturn([]);
        $service = new CatalogMaintenanceService(
            $store,
            new CatalogAuthorization(),
            new HomeFixedClock(new DateTimeImmutable('2026-09-22T12:00:00Z')),
            new RecordingTransactionManager(),
        );
        $identity = new AuthenticatedIdentity('user', 'session', 'device', null, [], ['catalog.review']);
        self::assertSame([], $service->list($identity, 'pack', 100, $productId, '  Rice  '));
    }

    public function testOversizedSearchCannotReachTheDatabase(): void
    {
        $store = $this->createMock(CatalogMaintenanceStore::class);
        $store->expects(self::never())->method('entities');
        $service = new CatalogMaintenanceService(
            $store,
            new CatalogAuthorization(),
            new HomeFixedClock(new DateTimeImmutable('2026-09-22T12:00:00Z')),
            new RecordingTransactionManager(),
        );
        $identity = new AuthenticatedIdentity('user', 'session', 'device', null, [], ['catalog.review']);
        try {
            $service->list($identity, 'category', 0, null, str_repeat('x', 192));
            self::fail('Oversized search was accepted.');
        } catch (Problem $problem) {
            self::assertSame(422, $problem->status);
        }
    }
}
