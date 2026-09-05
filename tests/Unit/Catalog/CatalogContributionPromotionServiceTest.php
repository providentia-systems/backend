<?php

declare(strict_types=1);

namespace ProvidentiaTest\Unit\Catalog;

use DateTimeImmutable;
use Laminas\Diactoros\ServerRequest;
use Laminas\Diactoros\Uri;
use PHPUnit\Framework\TestCase;
use Providentia\Catalog\Application\CatalogAuditRecorder;
use Providentia\Catalog\Application\CatalogAuthorization;
use Providentia\Catalog\Application\CatalogContributionPromotionService;
use Providentia\Catalog\Application\CatalogContributionStore;
use Providentia\Catalog\Application\CatalogGovernanceService;
use Providentia\Catalog\Application\CatalogGovernanceStore;
use Providentia\Catalog\Application\PublishedCategoryReader;
use Providentia\Catalog\Http\CatalogContributionPromotionHandler;
use Providentia\Identity\Application\AuthenticatedIdentity;
use Providentia\Identity\Http\BearerAuthenticationMiddleware;
use Providentia\SharedKernel\Application\Problem;
use Providentia\SharedKernel\Application\UuidGenerator;
use ProvidentiaTest\Unit\Home\HomeFixedClock;
use ProvidentiaTest\Unit\Home\RecordingTransactionManager;

final class CatalogContributionPromotionServiceTest extends TestCase
{
    private const CONTRIBUTION = '01991f22-6b2f-7e30-8ef6-4f62cc89a001';
    private const CATEGORY = '01991f22-6b2f-7e30-8ef6-4f62cc89a002';
    private const OTHER_CATEGORY = '01991f22-6b2f-7e30-8ef6-4f62cc89a003';
    private const PROPOSAL = '01991f22-6b2f-7e30-8ef6-4f62cc89a004';
    private const USER = '01991f22-6b2f-7e30-8ef6-4f62cc89a005';
    private const AUDIT = '01991f22-6b2f-7e30-8ef6-4f62cc89a006';

    public function testPromotionHandlerRejectsFieldsOutsideTheClosedContract(): void
    {
        $contributions = $this->createMock(CatalogContributionStore::class);
        $contributions->expects(self::never())->method('contributionForProposal');
        $handler = new CatalogContributionPromotionHandler($this->service(
            $contributions,
            $this->governance($this->createStub(CatalogGovernanceStore::class), false),
            $this->createStub(PublishedCategoryReader::class),
        ));
        $request = (new ServerRequest(
            [],
            [],
            new Uri('https://app.example.test/api/v1/catalog-contributions/' . self::CONTRIBUTION . '/proposal'),
            'PUT',
            'php://memory',
        ))
            ->withAttribute('contributionId', self::CONTRIBUTION)
            ->withAttribute(BearerAuthenticationMiddleware::ATTRIBUTE, $this->curator())
            ->withParsedBody([
                'publishedCategoryId' => self::CATEGORY,
                'expectedRevision' => 2,
                'unexpected' => 'ignored',
            ]);

        try {
            $handler->handle($request);
            self::fail('A promotion request outside the closed JSON contract was accepted.');
        } catch (Problem $problem) {
            self::assertSame(422, $problem->status);
        }
    }

    public function testApprovedIdentityCreatesOneGovernedProductProposalAndDurableLink(): void
    {
        $contributions = $this->createMock(CatalogContributionStore::class);
        $contributions->expects(self::once())->method('contributionForProposal')->willReturn([
            'id' => self::CONTRIBUTION,
            'contributionType' => 'product_identity',
            'status' => 'approved',
            'revision' => 2,
            'payload' => ['canonicalName' => 'Rolled oats', 'brand' => 'Example'],
            'proposalId' => null,
        ]);
        $contributions->expects(self::once())
            ->method('linkContributionProposal')
            ->with(
                self::CONTRIBUTION,
                2,
                self::PROPOSAL,
                self::CATEGORY,
                self::USER,
                self::isInstanceOf(DateTimeImmutable::class),
            )
            ->willReturn(true);
        $audit = $this->createMock(CatalogAuditRecorder::class);
        $audit->expects(self::once())->method('recordAudit')->with(
            self::AUDIT,
            self::USER,
            'catalog.contribution.proposal-linked',
            'catalog_contribution',
            self::CONTRIBUTION,
            null,
            self::callback(static fn (string $details): bool =>
                str_contains($details, self::PROPOSAL)
                && str_contains($details, self::CATEGORY)),
            self::isInstanceOf(DateTimeImmutable::class),
        );
        $categories = $this->createMock(PublishedCategoryReader::class);
        $categories->expects(self::once())->method('publishedCategory')->with(self::CATEGORY)->willReturn([
            'id' => self::CATEGORY,
            'canonicalName' => 'Breakfast',
            'revision' => 1,
        ]);
        $governance = $this->governance();

        self::assertSame([
            'contributionId' => self::CONTRIBUTION,
            'contributionRevision' => 2,
            'proposalId' => self::PROPOSAL,
            'proposalStatus' => 'pending',
            'publishedCategoryId' => self::CATEGORY,
            'publishedCategoryName' => 'Breakfast',
            'linkedAt' => '2026-08-24T12:00:00+00:00',
        ], $this->service($contributions, $governance, $categories, $audit)->put(
            $this->curator(),
            self::CONTRIBUTION,
            self::CATEGORY,
            2,
        ));
    }

    public function testExactReplayReturnsTheExistingLinkWithoutCreatingAnotherProposal(): void
    {
        $contributions = $this->createMock(CatalogContributionStore::class);
        $contributions->expects(self::once())->method('contributionForProposal')->willReturn($this->linkedSource());
        $contributions->expects(self::never())->method('linkContributionProposal');
        $categories = $this->createMock(PublishedCategoryReader::class);
        $categories->expects(self::never())->method('publishedCategory');
        $governanceStore = $this->createMock(CatalogGovernanceStore::class);
        $governanceStore->expects(self::never())->method('createProposal');

        $result = $this->service(
            $contributions,
            $this->governance($governanceStore, false),
            $categories,
        )->put($this->curator(), self::CONTRIBUTION, self::CATEGORY, 2);

        self::assertSame(self::PROPOSAL, $result['proposalId']);
        self::assertSame('Breakfast', $result['publishedCategoryName']);
        self::assertSame('2026-08-24T12:00:00+00:00', $result['linkedAt']);
    }

    public function testLostUniqueLinkRaceRollsBackTheCandidateAndReturnsTheWinningLink(): void
    {
        $unlinked = $this->linkedSource();
        $unlinked['proposalId'] = null;
        $unlinked['linkedContributionRevision'] = null;
        $unlinked['publishedCategoryId'] = null;
        $unlinked['publishedCategoryName'] = null;
        $unlinked['linkedAt'] = null;
        $unlinked['proposalStatus'] = null;
        $contributions = $this->createMock(CatalogContributionStore::class);
        $contributions->expects(self::exactly(2))
            ->method('contributionForProposal')
            ->willReturnOnConsecutiveCalls($unlinked, $this->linkedSource());
        $contributions->expects(self::once())->method('linkContributionProposal')->willReturn(false);
        $categories = $this->createStub(PublishedCategoryReader::class);
        $categories->method('publishedCategory')->willReturn([
            'id' => self::CATEGORY,
            'canonicalName' => 'Breakfast',
            'revision' => 1,
        ]);

        $result = $this->service($contributions, $this->governance(), $categories)->put(
            $this->curator(),
            self::CONTRIBUTION,
            self::CATEGORY,
            2,
        );

        self::assertSame(self::PROPOSAL, $result['proposalId']);
    }

    public function testReplayRejectsAMismatchedCategory(): void
    {
        $contributions = $this->createStub(CatalogContributionStore::class);
        $contributions->method('contributionForProposal')->willReturn($this->linkedSource());

        $this->expectException(Problem::class);
        $this->service(
            $contributions,
            $this->governance($this->createStub(CatalogGovernanceStore::class), false),
            $this->createStub(PublishedCategoryReader::class),
        )->put($this->curator(), self::CONTRIBUTION, self::OTHER_CATEGORY, 2);
    }

    public function testWithdrawnLinkedSourceCannotBeReplayed(): void
    {
        $source = $this->linkedSource();
        $source['status'] = 'withdrawn';
        $source['revision'] = 3;
        $contributions = $this->createStub(CatalogContributionStore::class);
        $contributions->method('contributionForProposal')->willReturn($source);

        $this->expectException(Problem::class);
        $this->service(
            $contributions,
            $this->governance($this->createStub(CatalogGovernanceStore::class), false),
            $this->createStub(PublishedCategoryReader::class),
        )->put($this->curator(), self::CONTRIBUTION, self::CATEGORY, 2);
    }

    private function governance(
        ?CatalogGovernanceStore $store = null,
        bool $expectSubmission = true,
    ): CatalogGovernanceService {
        $store ??= $this->createMock(CatalogGovernanceStore::class);
        if ($expectSubmission && $store instanceof \PHPUnit\Framework\MockObject\MockObject) {
            $store->expects(self::once())
                ->method('conflictFor')
                ->with('product', self::anything(), [
                    'canonicalName' => 'Rolled oats',
                    'brand' => 'Example',
                    'categoryId' => self::CATEGORY,
                ])
                ->willReturn(null);
            $store->expects(self::once())->method('createProposal');
        }
        $ids = $this->createStub(UuidGenerator::class);
        $ids->method('generate')->willReturn(self::PROPOSAL);

        return new CatalogGovernanceService(
            $store,
            new CatalogAuthorization(),
            $ids,
            new HomeFixedClock(new DateTimeImmutable('2026-08-24T12:00:00+00:00')),
            new RecordingTransactionManager(),
        );
    }

    private function service(
        CatalogContributionStore $contributions,
        CatalogGovernanceService $governance,
        PublishedCategoryReader $categories,
        ?CatalogAuditRecorder $audit = null,
    ): CatalogContributionPromotionService {
        $ids = $this->createStub(UuidGenerator::class);
        $ids->method('generate')->willReturn(self::AUDIT);

        return new CatalogContributionPromotionService(
            $contributions,
            $governance,
            $categories,
            new CatalogAuthorization(),
            $audit ?? $this->createStub(CatalogAuditRecorder::class),
            $ids,
            new HomeFixedClock(new DateTimeImmutable('2026-08-24T12:00:00+00:00')),
            new RecordingTransactionManager(),
        );
    }

    /** @return array<string, mixed> */
    private function linkedSource(): array
    {
        return [
            'id' => self::CONTRIBUTION,
            'contributionType' => 'product_identity',
            'status' => 'approved',
            'revision' => 2,
            'payload' => ['canonicalName' => 'Rolled oats', 'brand' => 'Example'],
            'linkedContributionRevision' => 2,
            'proposalId' => self::PROPOSAL,
            'proposalStatus' => 'pending',
            'publishedCategoryId' => self::CATEGORY,
            'publishedCategoryName' => 'Breakfast',
            'linkedAt' => '2026-08-24 12:00:00.000000',
        ];
    }

    private function curator(): AuthenticatedIdentity
    {
        return new AuthenticatedIdentity(
            self::USER,
            '01991f22-6b2f-7e30-8ef6-4f62cc89a007',
            '01991f22-6b2f-7e30-8ef6-4f62cc89a008',
            null,
            [CatalogAuthorization::CURATOR],
            \ProvidentiaTest\Support\AccessFixture::administratorPermissions([CatalogAuthorization::CURATOR])
        );
    }
}
