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
    public function testCategoryProposalUsesTheSameSanitizedGovernancePath(): void
    {
        $store = $this->createMock(CatalogGovernanceStore::class);
        $store->expects(self::once())
            ->method('conflictFor')
            ->with('category', 'dry goods', ['canonicalName' => 'Dry Goods'])
            ->willReturn(null);
        $store->expects(self::once())
            ->method('createProposal')
            ->with(
                'proposal-1',
                'category',
                'dry goods',
                ['canonicalName' => 'Dry Goods'],
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

        self::assertSame(
            ['id' => 'proposal-1', 'status' => 'pending', 'revision' => 1],
            $service->submit($this->identity([]), 'category', ['canonicalName' => ' Dry Goods ']),
        );
    }

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

    public function testWithdrawnContributionCannotBePublishedThroughItsLinkedProposal(): void
    {
        $store = $this->createMock(CatalogGovernanceStore::class);
        $store->method('proposal')->willReturn([
            'id' => 'proposal-1',
            'proposalType' => 'product',
            'payload' => [
                'canonicalName' => 'Rolled oats',
                'brand' => '',
                'categoryId' => 'category-1',
            ],
            'moderationStatus' => 'pending',
            'revision' => 1,
        ]);
        $store->expects(self::once())->method('proposalSourceEligible')->with('proposal-1')->willReturn(false);
        $store->expects(self::never())->method('publishProposal');
        $store->expects(self::never())->method('decideProposal');
        $service = new CatalogGovernanceService(
            $store,
            new CatalogAuthorization(),
            $this->createStub(UuidGenerator::class),
            new HomeFixedClock(new DateTimeImmutable('2026-08-24T12:00:00+00:00')),
            new RecordingTransactionManager(),
        );

        $this->expectException(Problem::class);
        $service->decideProposal(
            $this->identity([CatalogAuthorization::REVIEWER]),
            'proposal-1',
            'approve',
            'Approved public fact',
            1,
        );
    }

    /** @param list<string> $roles */
    private function identity(array $roles): AuthenticatedIdentity
    {
        return new AuthenticatedIdentity('user-1', 'session-1', 'device-1', 'home-1', $roles);
    }
}
