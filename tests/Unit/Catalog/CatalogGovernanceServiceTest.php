<?php

declare(strict_types=1);

namespace ProvidentiaTest\Unit\Catalog;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Providentia\Catalog\Application\CatalogAuthorization;
use Providentia\Catalog\Application\CatalogGovernanceService;
use Providentia\Catalog\Application\CatalogGovernanceStore;
use Providentia\Identity\Application\AuthenticatedIdentity;
use Providentia\SharedKernel\Application\Problem;
use Providentia\SharedKernel\Application\UuidGenerator;
use ProvidentiaTest\Unit\Home\HomeFixedClock;
use ProvidentiaTest\Unit\Home\RecordingTransactionManager;

final class CatalogGovernanceServiceTest extends TestCase
{
    public function testAuthenticatedUserCanSubmitOnlyTheSanitizedProposalShape(): void
    {
        $store = $this->createMock(CatalogGovernanceStore::class);
        $store->expects(self::once())
            ->method('conflictFor')
            ->with('product', self::anything(), [
                'canonicalName' => 'Brown Rice',
                'brand' => 'Example',
                'categoryId' => 'category-1',
            ])
            ->willReturn(null);
        $store->expects(self::once())
            ->method('createProposal')
            ->with(
                'proposal-1',
                'product',
                self::anything(),
                [
                    'canonicalName' => 'Brown Rice',
                    'brand' => 'Example',
                    'categoryId' => 'category-1',
                ],
                'pending',
                null,
                'user-1',
                self::isInstanceOf(DateTimeImmutable::class),
            );
        $ids = $this->createStub(UuidGenerator::class);
        $ids->method('generate')->willReturn('proposal-1');
        $service = new CatalogGovernanceService(
            $store,
            new CatalogAuthorization(),
            $ids,
            new HomeFixedClock(new DateTimeImmutable('2026-07-30T12:00:00+00:00')),
            new RecordingTransactionManager(),
        );

        $result = $service->submit($this->identity([]), 'product', [
            'canonicalName' => ' Brown Rice ',
            'brand' => 'Example',
            'categoryId' => 'category-1',
        ]);

        self::assertSame(['id' => 'proposal-1', 'status' => 'pending', 'revision' => 1], $result);
    }

    public function testPrivateHouseholdFieldsCannotEnterCatalogProposal(): void
    {
        $service = new CatalogGovernanceService(
            $this->createStub(CatalogGovernanceStore::class),
            new CatalogAuthorization(),
            $this->createStub(UuidGenerator::class),
            new HomeFixedClock(new DateTimeImmutable('2026-07-30T12:00:00+00:00')),
            new RecordingTransactionManager(),
        );

        $this->expectException(Problem::class);
        $service->submit($this->identity([]), 'product', [
            'canonicalName' => 'Brown Rice',
            'brand' => 'Example',
            'categoryId' => 'category-1',
            'price' => '12.50',
        ]);
    }

    public function testHomeMembershipDoesNotGrantCatalogWorkbenchAccess(): void
    {
        $service = new CatalogGovernanceService(
            $this->createStub(CatalogGovernanceStore::class),
            new CatalogAuthorization(),
            $this->createStub(UuidGenerator::class),
            new HomeFixedClock(new DateTimeImmutable('2026-07-30T12:00:00+00:00')),
            new RecordingTransactionManager(),
        );

        $this->expectException(Problem::class);
        $service->workbench($this->identity([]), 'proposals', 50, 0);
    }

    public function testGtinProposalRequiresAValidLengthAndCheckDigit(): void
    {
        $service = new CatalogGovernanceService(
            $this->createStub(CatalogGovernanceStore::class),
            new CatalogAuthorization(),
            $this->createStub(UuidGenerator::class),
            new HomeFixedClock(new DateTimeImmutable('2026-07-30T12:00:00+00:00')),
            new RecordingTransactionManager(),
        );

        $this->expectException(Problem::class);
        $service->submit($this->identity([]), 'barcode', [
            'packId' => 'pack-1',
            'barcode' => '4006381333932',
            'barcodeType' => 'gtin-13',
        ]);
    }

    /** @param list<string> $roles */
    private function identity(array $roles): AuthenticatedIdentity
    {
        return new AuthenticatedIdentity('user-1', 'session-1', 'device-1', 'home-1', $roles);
    }
}
