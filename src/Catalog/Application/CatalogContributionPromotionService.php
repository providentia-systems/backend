<?php

declare(strict_types=1);

namespace Providentia\Catalog\Application;

use DateTimeImmutable;
use DateTimeZone;
use Providentia\Identity\Application\AuthenticatedIdentity;
use Providentia\SharedKernel\Application\Clock;
use Providentia\SharedKernel\Application\Problem;
use Providentia\SharedKernel\Application\TransactionManager;
use Providentia\SharedKernel\Application\UuidGenerator;

final class CatalogContributionPromotionService
{
    public function __construct(
        private readonly CatalogContributionStore $contributions,
        private readonly CatalogGovernanceService $governance,
        private readonly PublishedCategoryReader $categories,
        private readonly CatalogAuthorization $authorization,
        private readonly CatalogAuditRecorder $audit,
        private readonly UuidGenerator $ids,
        private readonly Clock $clock,
        private readonly TransactionManager $transactions,
    ) {
    }

    /**
     * @return array{
     *   contributionId: string,
     *   contributionRevision: int,
     *   proposalId: string,
     *   proposalStatus: string,
     *   publishedCategoryId: string,
     *   publishedCategoryName: string,
     *   linkedAt: string
     * }
     */
    public function put(
        AuthenticatedIdentity $identity,
        string $contributionId,
        string $publishedCategoryId,
        int $expectedRevision,
    ): array {
        $this->authorization->requireCurator($identity);
        if (
            ! $this->isUuid($contributionId)
            || ! $this->isUuid($publishedCategoryId)
            || $expectedRevision < 1
        ) {
            throw new Problem(422, 'Invalid contribution proposal', 'Valid identifiers and a revision are required.');
        }

        try {
            return $this->transactions->transactional(function () use (
                $identity,
                $contributionId,
                $publishedCategoryId,
                $expectedRevision,
            ): array {
                $source = $this->contributions->contributionForProposal($contributionId);
                if ($source === null) {
                    throw new Problem(404, 'Not found', 'The requested resource is unavailable.');
                }
                if (isset($source['proposalId']) && is_string($source['proposalId'])) {
                    return $this->replay($source, $publishedCategoryId, $expectedRevision);
                }
                if ((int) $source['revision'] !== $expectedRevision) {
                    throw new Problem(409, 'Contribution conflict', 'The contribution changed since it was read.');
                }
                if (
                    (string) $source['status'] !== 'approved'
                    || (string) $source['contributionType'] !== 'product_identity'
                ) {
                    throw new Problem(
                        409,
                        'Contribution cannot become a proposal',
                        'Only an approved product-identity contribution can be linked.',
                    );
                }
                $category = $this->categories->publishedCategory($publishedCategoryId);
                if ($category === null) {
                    throw new Problem(422, 'Invalid published category', 'Select a currently published category.');
                }
                $payload = $source['payload'] ?? [];
                if (! is_array($payload)) {
                    throw new Problem(
                        409,
                        'Contribution cannot become a proposal',
                        'The approved contribution is invalid.',
                    );
                }
                $proposal = $this->governance->submit($identity, 'product', [
                    'canonicalName' => (string) ($payload['canonicalName'] ?? ''),
                    'brand' => (string) ($payload['brand'] ?? ''),
                    'categoryId' => $publishedCategoryId,
                ]);
                $linkedAt = $this->clock->now();
                $linked = $this->contributions->linkContributionProposal(
                    $contributionId,
                    $expectedRevision,
                    $proposal['id'],
                    $publishedCategoryId,
                    $identity->userId,
                    $linkedAt,
                );
                if (! $linked) {
                    throw new ConcurrentCatalogProposalLink();
                }
                $this->audit->recordAudit(
                    $this->ids->generate(),
                    $identity->userId,
                    'catalog.contribution.proposal-linked',
                    'catalog_contribution',
                    $contributionId,
                    null,
                    json_encode([
                        'contributionRevision' => $expectedRevision,
                        'proposalId' => $proposal['id'],
                        'publishedCategoryId' => $publishedCategoryId,
                    ], JSON_THROW_ON_ERROR),
                    $linkedAt,
                );

                return [
                    'contributionId' => $contributionId,
                    'contributionRevision' => $expectedRevision,
                    'proposalId' => $proposal['id'],
                    'proposalStatus' => $proposal['status'],
                    'publishedCategoryId' => $publishedCategoryId,
                    'publishedCategoryName' => (string) $category['canonicalName'],
                    'linkedAt' => $linkedAt->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM),
                ];
            });
        } catch (ConcurrentCatalogProposalLink) {
            $replay = $this->contributions->contributionForProposal($contributionId);
            if ($replay === null || ! isset($replay['proposalId'])) {
                throw new Problem(
                    409,
                    'Contribution proposal conflict',
                    'The contribution proposal link changed concurrently.',
                );
            }

            return $this->replay($replay, $publishedCategoryId, $expectedRevision);
        }
    }

    /**
     * @param array<string, mixed> $source
     * @return array{
     *   contributionId: string,
     *   contributionRevision: int,
     *   proposalId: string,
     *   proposalStatus: string,
     *   publishedCategoryId: string,
     *   publishedCategoryName: string,
     *   linkedAt: string
     * }
     */
    private function replay(array $source, string $categoryId, int $expectedRevision): array
    {
        $categoryName = $source['publishedCategoryName'] ?? null;
        if (
            (string) ($source['status'] ?? '') !== 'approved'
            || (int) ($source['revision'] ?? 0) !== $expectedRevision
            || (int) ($source['linkedContributionRevision'] ?? 0) !== $expectedRevision
            || (string) ($source['publishedCategoryId'] ?? '') !== $categoryId
        ) {
            throw new Problem(
                409,
                'Contribution proposal conflict',
                'This contribution is already linked using a different revision or category.',
            );
        }
        if (! is_string($categoryName) || $categoryName === '') {
            throw new Problem(
                500,
                'Invalid contribution proposal state',
                'The linked published category is unavailable.',
            );
        }

        return [
            'contributionId' => (string) $source['id'],
            'contributionRevision' => $expectedRevision,
            'proposalId' => (string) $source['proposalId'],
            'proposalStatus' => (string) $source['proposalStatus'],
            'publishedCategoryId' => $categoryId,
            'publishedCategoryName' => $categoryName,
            'linkedAt' => $this->date((string) $source['linkedAt']),
        ];
    }

    private function date(string $value): string
    {
        try {
            return (new DateTimeImmutable($value, new DateTimeZone('UTC')))
                ->setTimezone(new DateTimeZone('UTC'))
                ->format(DATE_ATOM);
        } catch (\Exception) {
            throw new Problem(500, 'Invalid contribution proposal state', 'The proposal link timestamp is invalid.');
        }
    }

    private function isUuid(string $value): bool
    {
        return preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $value,
        ) === 1;
    }
}
