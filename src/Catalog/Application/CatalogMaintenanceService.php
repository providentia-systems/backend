<?php

declare(strict_types=1);

namespace Providentia\Catalog\Application;

use DomainException;
use Providentia\Identity\Application\AuthenticatedIdentity;
use Providentia\SharedKernel\Application\Clock;
use Providentia\SharedKernel\Application\Problem;
use Providentia\SharedKernel\Application\TransactionManager;

final readonly class CatalogMaintenanceService
{
    public function __construct(
        private CatalogMaintenanceStore $store,
        private CatalogAuthorization $authorization,
        private Clock $clock,
        private TransactionManager $transactions,
    ) {}

    /** @return list<array<string, mixed>> */
    public function list(AuthenticatedIdentity $identity, string $type, int $offset): array
    {
        $this->authorization->requireReviewer($identity);
        $this->type($type);
        return $this->store->entities($type, max(0, $offset));
    }

    /** @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function save(AuthenticatedIdentity $identity, string $type, string $id, array $body): array
    {
        $this->authorization->requireCurator($identity);
        $this->type($type);
        $revision = $body['expectedRevision'] ?? null;
        $reason = $body['reason'] ?? null;
        $status = $body['status'] ?? null;
        $fields = $body['fields'] ?? null;
        if (
            preg_match(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
                $id,
            ) !== 1 ||
            !is_int($revision) ||
            $revision < 0 ||
            !is_string($reason) ||
            trim($reason) === '' ||
            mb_strlen($reason) > 500 ||
            !in_array($status, ['published', 'archived'], true) ||
            !is_array($fields) ||
            array_diff(array_keys($body), ['fields', 'status', 'reason', 'expectedRevision']) !== []
        ) {
            throw new Problem(
                422,
                'Invalid catalog change',
                'Valid fields, revision, status and audit reason are required.',
            );
        }
        try {
            return $this->transactions->transactional(
                fn(): array => $this->store->saveEntity(
                    $type,
                    $id,
                    $fields,
                    $status,
                    $revision,
                    trim($reason),
                    $identity->userId,
                    $this->clock->now(),
                ),
            );
        } catch (DomainException $error) {
            throw new Problem(409, 'Catalog change rejected', $error->getMessage());
        }
    }

    private function type(string $type): void
    {
        if (
            !in_array(
                $type,
                ['category', 'product', 'unit', 'pack', 'variant', 'alias', 'barcode', 'identity-rule'],
                true,
            )
        ) {
            throw new Problem(422, 'Invalid catalog type', 'Choose a supported catalog entity.');
        }
    }
}
