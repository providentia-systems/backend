<?php

declare(strict_types=1);

namespace Providentia\Inventory\Application;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use InvalidArgumentException;
use Providentia\Home\Application\HomeAuthorization;
use Providentia\Home\Application\HomePermission;
use Providentia\Identity\Application\AuthenticatedIdentity;
use Providentia\Inventory\Domain\DecimalQuantity;
use Providentia\SharedKernel\Application\ChangeFeedWriter;
use Providentia\SharedKernel\Application\Clock;
use Providentia\SharedKernel\Application\Problem;
use Providentia\SharedKernel\Application\TransactionManager;
use Providentia\SharedKernel\Application\UuidGenerator;

final class InventoryService implements InventoryMovementGateway
{
    public function __construct(
        private readonly InventoryStore $inventory,
        private readonly HomeAuthorization $authorization,
        private readonly UuidGenerator $ids,
        private readonly Clock $clock,
        private readonly TransactionManager $transactions,
        private readonly ?ChangeFeedWriter $changes = null,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function categories(
        AuthenticatedIdentity $identity,
        string $homeId,
        bool $includeArchived = false,
    ): array {
        $this->authorization->requirePermission($identity, $homeId, HomePermission::INVENTORY_READ);

        return $this->inventory->categories($homeId, $includeArchived);
    }

    /** @return array<string, mixed> */
    public function createHomeCategory(
        AuthenticatedIdentity $identity,
        string $homeId,
        string $name,
        ?string $requestedId = null,
    ): array {
        $this->authorization->requirePermission($identity, $homeId, HomePermission::INVENTORY_WRITE);
        $name = $this->categoryName($name);
        $id = $this->identifier($requestedId);
        $at = $this->clock->now();
        try {
            $this->transactions->transactional(function () use ($identity, $homeId, $id, $name, $at): void {
                $this->inventory->createHomeCategory($id, $homeId, $name, $this->normalize($name), $at);
                $this->changes?->put(
                    $homeId,
                    $identity->userId,
                    'inventory-home-category',
                    $id,
                    1,
                    ['name' => $name, 'status' => 'active'],
                    $at,
                );
            });
        } catch (DomainException $error) {
            throw new Problem(422, 'Invalid category', $error->getMessage());
        }

        $timestamp = $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');

        return [
            'id' => $id,
            'name' => $name,
            'status' => 'active',
            'revision' => 1,
            'createdAt' => $timestamp,
            'updatedAt' => $timestamp,
            'archivedAt' => null,
        ];
    }

    /** @return array<string, mixed> */
    public function updateHomeCategory(
        AuthenticatedIdentity $identity,
        string $homeId,
        string $categoryId,
        ?string $name,
        ?string $status,
        int $expectedRevision,
    ): array {
        $this->authorization->requirePermission($identity, $homeId, HomePermission::INVENTORY_WRITE);
        if ($expectedRevision < 1) {
            throw new Problem(422, 'Invalid category', 'A positive expected revision is required.');
        }
        if ($name === null && $status === null) {
            throw new Problem(422, 'Invalid category', 'Provide a name or status change.');
        }
        $name = $name === null ? null : $this->categoryName($name);
        if ($status !== null && ! in_array($status, ['active', 'archived'], true)) {
            throw new Problem(422, 'Invalid category', 'Category status must be active or archived.');
        }
        $at = $this->clock->now();
        try {
            $result = $this->transactions->transactional(function () use (
                $identity,
                $homeId,
                $categoryId,
                $name,
                $status,
                $expectedRevision,
                $at,
            ): array {
                $result = $this->inventory->updateHomeCategory(
                    $homeId,
                    $categoryId,
                    $name,
                    $name === null ? null : $this->normalize($name),
                    $status,
                    $expectedRevision,
                    $at,
                );
                if ($result['status'] === 'updated') {
                    $record = $result['record'];
                    $this->changes?->put(
                        $homeId,
                        $identity->userId,
                        'inventory-home-category',
                        $categoryId,
                        (int) $record['revision'],
                        ['name' => (string) $record['name'], 'status' => (string) $record['status']],
                        $at,
                    );
                }

                return $result;
            });
        } catch (DomainException $error) {
            throw new Problem(422, 'Invalid category', $error->getMessage());
        }

        return match ($result['status']) {
            'updated' => $result['record'],
            'not-found' => throw new Problem(404, 'Not found', 'The category is unavailable.'),
            'revision-conflict' => throw new Problem(
                409,
                'Revision conflict',
                'The category changed on another device.',
            ),
            'category-in-use' => throw new Problem(
                409,
                'Category in use',
                'Move or archive active products before archiving this category.',
            ),
            default => throw new \LogicException('Unknown home-category update result.'),
        };
    }

    /** @return list<array<string, mixed>> */
    public function locations(AuthenticatedIdentity $identity, string $homeId): array
    {
        $this->authorization->requirePermission($identity, $homeId, HomePermission::INVENTORY_READ);

        return $this->inventory->locations($homeId);
    }

    /** @return array{id: string} */
    public function createLocation(
        AuthenticatedIdentity $identity,
        string $homeId,
        string $name,
        string $kind,
        ?string $requestedId = null,
    ): array {
        $this->authorization->requirePermission($identity, $homeId, HomePermission::INVENTORY_WRITE);
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 120) {
            throw new Problem(422, 'Invalid location', 'Location name must contain 1 to 120 characters.');
        }
        if (! in_array($kind, ['pantry', 'shelf', 'fridge', 'freezer', 'household', 'other'], true)) {
            throw new Problem(422, 'Invalid location', 'Location kind is not supported.');
        }
        $id = $this->identifier($requestedId);
        $at = $this->clock->now();
        $this->transactions->transactional(function () use ($id, $homeId, $name, $kind, $identity, $at): void {
            $this->inventory->createLocation($id, $homeId, $name, $this->normalize($name), $kind, $at);
            $this->changes?->put(
                $homeId,
                $identity->userId,
                'inventory-location',
                $id,
                1,
                ['name' => $name, 'kind' => $kind, 'status' => 'active'],
                $at,
            );
        });

        return ['id' => $id];
    }

    /** @return array{data: list<array<string, mixed>>, pagination: array<string, int|bool|null>} */
    public function itemMaster(
        AuthenticatedIdentity $identity,
        string $homeId,
        string $query,
        ?string $categoryId,
        ?string $homeCategoryId,
        int $limit,
        int $offset,
    ): array {
        $this->authorization->requirePermission($identity, $homeId, HomePermission::INVENTORY_READ);

        [$categoryId, $homeCategoryId] = $this->categoryFilters($categoryId, $homeCategoryId);

        $limit = min(100, max(1, $limit));
        $offset = max(0, $offset);
        $page = $this->inventory->itemMaster(
            $homeId,
            mb_substr(trim($query), 0, 191),
            $categoryId,
            $homeCategoryId,
            $limit,
            $offset,
        );
        $returned = count($page['items']);
        $nextOffset = $offset + $returned;
        $hasMore = $nextOffset < $page['total'];

        return [
            'data' => $page['items'],
            'pagination' => [
                'limit' => $limit,
                'offset' => $offset,
                'returned' => $returned,
                'total' => $page['total'],
                'hasMore' => $hasMore,
                'nextOffset' => $hasMore ? $nextOffset : null,
            ],
        ];
    }

    /** @return list<array<string, mixed>> */
    public function stock(
        AuthenticatedIdentity $identity,
        string $homeId,
        string $query,
        ?string $categoryId,
        ?string $homeCategoryId,
        int $limit,
        int $offset,
    ): array {
        $this->authorization->requirePermission($identity, $homeId, HomePermission::INVENTORY_READ);

        [$categoryId, $homeCategoryId] = $this->categoryFilters($categoryId, $homeCategoryId);

        return $this->inventory->stock(
            $homeId,
            mb_substr(trim($query), 0, 191),
            $categoryId,
            $homeCategoryId,
            min(100, max(1, $limit)),
            max(0, $offset),
        );
    }

    /** @return array{id: string} */
    public function addHomeProduct(
        AuthenticatedIdentity $identity,
        string $homeId,
        ?string $productId,
        ?string $packId,
        ?string $privateName,
        ?string $originalPackText,
        ?string $homeCategoryId = null,
        ?string $requestedId = null,
    ): array {
        $this->authorization->requirePermission($identity, $homeId, HomePermission::INVENTORY_WRITE);
        if ($productId !== null && $productId === '') {
            $productId = null;
        }
        if ($packId !== null && $packId === '') {
            $packId = null;
        }
        if ($homeCategoryId === '') {
            $homeCategoryId = null;
        }
        $privateName = $privateName === null ? null : trim($privateName);
        if ($productId === null && $packId === null && ($privateName === null || $privateName === '')) {
            throw new Problem(422, 'Invalid item', 'Choose a catalog product or provide a private product name.');
        }
        if ($privateName !== null && mb_strlen($privateName) > 191) {
            throw new Problem(422, 'Invalid item', 'Private product name exceeds 191 characters.');
        }
        $originalPackText = $originalPackText === null ? null : trim($originalPackText);
        if ($originalPackText !== null && mb_strlen($originalPackText) > 191) {
            throw new Problem(422, 'Invalid item', 'Original pack text exceeds 191 characters.');
        }
        $id = $this->identifier($requestedId);
        $at = $this->clock->now();
        try {
            $this->transactions->transactional(function () use (
                $id,
                $homeId,
                $productId,
                $packId,
                $privateName,
                $originalPackText,
                $homeCategoryId,
                $identity,
                $at,
            ): void {
                $this->inventory->createHomeProduct(
                    $id,
                    $homeId,
                    $productId,
                    $packId,
                    $privateName,
                    $privateName === null ? null : $this->normalize($privateName),
                    $originalPackText,
                    $homeCategoryId,
                    $at,
                );
                $this->changes?->put(
                    $homeId,
                    $identity->userId,
                    'inventory-home-product',
                    $id,
                    1,
                    [
                        'productId' => $productId,
                        'packId' => $packId,
                        'privateName' => $privateName,
                        'originalPackText' => $originalPackText,
                        'homeCategoryId' => $homeCategoryId,
                        'status' => 'active',
                    ],
                    $at,
                );
            });
        } catch (DomainException $error) {
            throw new Problem(422, 'Invalid item', $error->getMessage());
        }

        return ['id' => $id];
    }

    /** @return array<string, mixed> */
    public function updateHomeProduct(
        AuthenticatedIdentity $identity,
        string $homeId,
        string $homeProductId,
        bool $privateNameProvided,
        ?string $privateName,
        bool $originalPackTextProvided,
        ?string $originalPackText,
        bool $homeCategoryProvided,
        ?string $homeCategoryId,
        ?string $status,
        int $expectedRevision,
    ): array {
        $this->authorization->requirePermission($identity, $homeId, HomePermission::INVENTORY_WRITE);
        if ($expectedRevision < 1) {
            throw new Problem(422, 'Invalid item', 'A positive expected revision is required.');
        }
        if (! $privateNameProvided && ! $originalPackTextProvided && ! $homeCategoryProvided && $status === null) {
            throw new Problem(422, 'Invalid item', 'Provide at least one product change.');
        }
        if ($privateNameProvided) {
            $privateName = trim((string) $privateName);
            if ($privateName === '' || mb_strlen($privateName) > 191) {
                throw new Problem(422, 'Invalid item', 'Private product name must contain 1 to 191 characters.');
            }
        }
        if ($originalPackTextProvided) {
            $originalPackText = $originalPackText === null ? null : trim($originalPackText);
            if ($originalPackText !== null && mb_strlen($originalPackText) > 191) {
                throw new Problem(422, 'Invalid item', 'Original pack text exceeds 191 characters.');
            }
        }
        if ($homeCategoryId === '') {
            $homeCategoryId = null;
        }
        if ($status !== null && ! in_array($status, ['active', 'archived'], true)) {
            throw new Problem(422, 'Invalid item', 'Product status must be active or archived.');
        }
        $at = $this->clock->now();
        $result = $this->transactions->transactional(function () use (
            $identity,
            $homeId,
            $homeProductId,
            $privateNameProvided,
            $privateName,
            $originalPackTextProvided,
            $originalPackText,
            $homeCategoryProvided,
            $homeCategoryId,
            $status,
            $expectedRevision,
            $at,
        ): array {
            $result = $this->inventory->updateHomeProduct(
                $homeId,
                $homeProductId,
                $privateNameProvided,
                $privateName,
                $privateNameProvided ? $this->normalize((string) $privateName) : null,
                $originalPackTextProvided,
                $originalPackText,
                $homeCategoryProvided,
                $homeCategoryId,
                $status,
                $expectedRevision,
                $at,
            );
            if ($result['status'] === 'updated') {
                $record = $result['record'];
                $this->changes?->put(
                    $homeId,
                    $identity->userId,
                    'inventory-home-product',
                    $homeProductId,
                    (int) $record['revision'],
                    [
                        'productId' => $record['productId'],
                        'packId' => $record['packId'],
                        'privateName' => $record['privateName'],
                        'originalPackText' => $record['originalPackText'],
                        'homeCategoryId' => $record['homeCategoryId'],
                        'status' => $record['status'],
                    ],
                    $at,
                );
            }

            return $result;
        });

        return match ($result['status']) {
            'updated' => $result['record'],
            'not-found' => throw new Problem(404, 'Not found', 'The product is unavailable.'),
            'revision-conflict' => throw new Problem(
                409,
                'Revision conflict',
                'The product changed on another device.',
            ),
            'category-unavailable' => throw new Problem(
                422,
                'Invalid category',
                'The private category is unavailable.',
            ),
            'balance-not-zero' => throw new Problem(
                409,
                'Product has stock',
                'Adjust the product balance to zero before archiving it.',
            ),
            'product-in-use' => throw new Problem(
                409,
                'Product in use',
                'Finish active counts and draft receipts before archiving this product.',
            ),
            'catalog-product' => throw new Problem(
                422,
                'Catalog product',
                'Only home-private products can be edited here.',
            ),
            default => throw new \LogicException('Unknown home-product update result.'),
        };
    }

    /** @return array<string, mixed> */
    public function manualAdjustment(
        AuthenticatedIdentity $identity,
        string $homeId,
        string $homeProductId,
        string $quantityDelta,
        string $reason,
        string $operationId,
    ): array {
        $this->authorization->requirePermission($identity, $homeId, HomePermission::INVENTORY_WRITE);
        $delta = $this->delta($quantityDelta);
        if ($delta->isZero()) {
            throw new Problem(422, 'Invalid adjustment', 'A manual adjustment cannot be zero.');
        }
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 191) {
            throw new Problem(422, 'Invalid adjustment', 'A concise adjustment reason is required.');
        }
        if ($operationId === '') {
            throw new Problem(422, 'Invalid adjustment', 'An idempotency operation ID is required.');
        }

        return $this->transactions->transactional(fn (): array => $this->recordMovement(
            $identity->userId,
            $homeId,
            $homeProductId,
            'manual-adjustment',
            $delta->toString(),
            'client-operation',
            $operationId,
            $reason,
            $this->clock->now(),
        ));
    }

    /** @return array<string, mixed> */
    public function startCount(
        AuthenticatedIdentity $identity,
        string $homeId,
        ?string $locationId,
        string $notes,
        bool $scopeComplete = false,
        string $reliability = 'unassessed',
        ?string $requestedId = null,
    ): array {
        $this->authorization->requirePermission($identity, $homeId, HomePermission::INVENTORY_WRITE);
        $notes = trim($notes);
        if (mb_strlen($notes) > 2000) {
            throw new Problem(422, 'Invalid count session', 'Count-session notes exceed 2000 characters.');
        }
        if (
            ! in_array($reliability, ['reliable', 'partial', 'unassessed'], true)
            || ($reliability === 'reliable' && ! $scopeComplete)
        ) {
            throw new Problem(
                422,
                'Invalid count session',
                'Reliable evidence requires an explicitly complete count scope.',
            );
        }
        $id = $this->identifier($requestedId);
        $at = $this->clock->now();
        try {
            $this->transactions->transactional(function () use (
                $id,
                $homeId,
                $locationId,
                $notes,
                $scopeComplete,
                $reliability,
                $identity,
                $at,
            ): void {
                $locationId = $locationId === '' ? null : $locationId;
                $this->inventory->createCountSession(
                    $id,
                    $homeId,
                    $locationId,
                    $notes,
                    $scopeComplete,
                    $reliability,
                    $identity->userId,
                    $at,
                );
                $this->changes?->put(
                    $homeId,
                    $identity->userId,
                    'inventory-count-session',
                    $id,
                    1,
                    [
                        'locationId' => $locationId,
                        'notes' => $notes,
                        'scopeComplete' => $scopeComplete,
                        'reliability' => $reliability,
                        'status' => 'open',
                    ],
                    $at,
                );
            });
        } catch (DomainException $error) {
            throw new Problem(422, 'Invalid count session', $error->getMessage());
        }

        $session = $this->inventory->countSession($homeId, $id);
        if ($session === null) {
            throw new \LogicException('Created stock-count session is unavailable.');
        }

        return $this->stockCountSession($homeId, $session, []);
    }

    /** @return list<array<string, mixed>> */
    public function countSessions(
        AuthenticatedIdentity $identity,
        string $homeId,
        int $limit,
        int $offset,
    ): array {
        $this->authorization->requirePermission($identity, $homeId, HomePermission::INVENTORY_READ);

        return array_map(
            fn (array $session): array => $this->stockCountSession($homeId, $session),
            $this->inventory->countSessions($homeId, min(100, max(1, $limit)), max(0, $offset)),
        );
    }

    /** @return array<string, mixed> */
    public function countSession(
        AuthenticatedIdentity $identity,
        string $homeId,
        string $sessionId,
    ): array {
        $this->authorization->requirePermission($identity, $homeId, HomePermission::INVENTORY_READ);
        $session = $this->inventory->countSession($homeId, $sessionId);
        if ($session === null) {
            throw new Problem(404, 'Not found', 'The requested resource is unavailable.');
        }
        return $this->stockCountSession(
            $homeId,
            $session,
            $this->inventory->countLines($homeId, $sessionId),
        );
    }

    /** @return array<string, mixed> */
    public function recordCount(
        AuthenticatedIdentity $identity,
        string $homeId,
        string $sessionId,
        string $lineId,
        string $homeProductId,
        string $quantity,
        ?string $confidence,
        string $source,
        string $notes,
        int $expectedRevision,
    ): array {
        $this->authorization->requirePermission($identity, $homeId, HomePermission::INVENTORY_WRITE);
        $session = $this->inventory->countSession($homeId, $sessionId);
        if ($session === null || (string) $session['status'] !== 'open') {
            throw new Problem(409, 'Count session closed', 'Only an open count session can be changed.');
        }
        $quantity = $this->quantity($quantity)->toString();
        if (! in_array($source, ['manual', 'photo-confirmed', 'import'], true)) {
            throw new Problem(422, 'Invalid count line', 'Count-line source is not supported.');
        }
        if ($confidence !== null) {
            $confidence = trim($confidence);
            if (preg_match('/^(?:0(?:\.\d{1,4})?|1(?:\.0{1,4})?)$/', $confidence) !== 1) {
                throw new Problem(422, 'Invalid count line', 'Confidence must be between zero and one.');
            }
        }
        $notes = trim($notes);
        if (mb_strlen($notes) > 2000) {
            throw new Problem(422, 'Invalid count line', 'Count-line notes exceed 2000 characters.');
        }
        if ($expectedRevision < 0) {
            throw new Problem(422, 'Invalid count line', 'Expected revision cannot be negative.');
        }
        $lineId = $lineId === '' ? $this->ids->generate() : $lineId;

        return $this->transactions->transactional(function () use (
            $lineId,
            $homeId,
            $sessionId,
            $homeProductId,
            $quantity,
            $confidence,
            $source,
            $notes,
            $identity,
            $expectedRevision,
            $session,
        ): array {
            if (
                ! $this->inventory->saveCountLine(
                    $lineId,
                    $homeId,
                    $sessionId,
                    $homeProductId,
                    $quantity,
                    $confidence,
                    $source,
                    $notes,
                    $identity->userId,
                    $expectedRevision,
                    $this->clock->now(),
                )
            ) {
                throw new Problem(409, 'Revision conflict', 'The count line changed on another device.');
            }
            $this->changes?->put(
                $homeId,
                $identity->userId,
                'inventory-count-line',
                $lineId,
                $expectedRevision + 1,
                [
                    'sessionId' => $sessionId,
                    'homeProductId' => $homeProductId,
                    'quantity' => $quantity,
                    'confidence' => $confidence,
                    'source' => $source,
                    'notes' => $notes,
                    'status' => 'confirmed',
                ],
                $this->clock->now(),
            );
            $this->changes?->put(
                $homeId,
                $identity->userId,
                'inventory-count-session',
                $sessionId,
                (int) $session['revision'] + 1,
                [
                    'locationId' => $session['locationId'] ?? null,
                    'notes' => (string) ($session['notes'] ?? ''),
                    'scopeComplete' => (bool) ($session['scopeComplete'] ?? false),
                    'reliability' => (string) ($session['reliability'] ?? 'unassessed'),
                    'status' => 'open',
                ],
                $this->clock->now(),
            );

            $line = $this->inventory->countLine($homeId, $sessionId, $lineId);
            if ($line === null) {
                throw new \LogicException('Committed stock-count line is unavailable.');
            }

            return $this->stockCountLine($line);
        });
    }

    /** @return array<string, mixed> */
    public function closeCount(
        AuthenticatedIdentity $identity,
        string $homeId,
        string $sessionId,
        int $expectedRevision,
    ): array {
        $this->authorization->requirePermission($identity, $homeId, HomePermission::INVENTORY_WRITE);

        return $this->transactions->transactional(function () use (
            $identity,
            $homeId,
            $sessionId,
            $expectedRevision,
        ): array {
            $session = $this->inventory->countSession($homeId, $sessionId);
            if ($session === null) {
                throw new Problem(404, 'Not found', 'The requested resource is unavailable.');
            }
            if ((string) $session['status'] === 'closed') {
                return $this->stockCountSession(
                    $homeId,
                    $session,
                    $this->inventory->countLines($homeId, $sessionId),
                );
            }
            if ((string) $session['status'] !== 'open' || (int) $session['revision'] !== $expectedRevision) {
                throw new Problem(409, 'Revision conflict', 'The count session changed on another device.');
            }
            $lines = $this->inventory->countLines($homeId, $sessionId);
            if ($lines === []) {
                throw new Problem(422, 'Empty count', 'At least one confirmed count line is required.');
            }
            foreach ($lines as $line) {
                $observed = $this->quantity((string) $line['quantity']);
                $balance = $this->inventory->balance($homeId, (string) $line['homeProductId']);
                $current = $this->quantity((string) ($balance['quantity'] ?? '0'));
                $delta = $observed->subtract($current);
                if ($delta->isZero()) {
                    continue;
                }
                $this->recordMovement(
                    $identity->userId,
                    $homeId,
                    (string) $line['homeProductId'],
                    'count-reconciliation',
                    $delta->toString(),
                    'stock-count-line',
                    (string) $line['id'],
                    'Closed physical count reconciliation',
                    $this->clock->now(),
                );
            }
            if (
                ! $this->inventory->closeCountSession(
                    $homeId,
                    $sessionId,
                    $expectedRevision,
                    $identity->userId,
                    $this->clock->now(),
                )
            ) {
                throw new Problem(409, 'Revision conflict', 'The count session changed on another device.');
            }
            $this->changes?->put(
                $homeId,
                $identity->userId,
                'inventory-count-session',
                $sessionId,
                $expectedRevision + 1,
                [
                    'locationId' => $session['locationId'] ?? null,
                    'notes' => (string) ($session['notes'] ?? ''),
                    'scopeComplete' => (bool) ($session['scopeComplete'] ?? false),
                    'reliability' => (string) ($session['reliability'] ?? 'unassessed'),
                    'status' => 'closed',
                ],
                $this->clock->now(),
            );
            $closedSession = $this->inventory->countSession($homeId, $sessionId);
            if ($closedSession === null || (string) ($closedSession['status'] ?? '') !== 'closed') {
                throw new \LogicException('Closed stock-count session is unavailable.');
            }

            return $this->stockCountSession(
                $homeId,
                $closedSession,
                $this->inventory->countLines($homeId, $sessionId),
            );
        });
    }

    /** @return array{sessionId: string, status: string, revision: int} */
    public function cancelCount(
        AuthenticatedIdentity $identity,
        string $homeId,
        string $sessionId,
        int $expectedRevision,
    ): array {
        $this->authorization->requirePermission($identity, $homeId, HomePermission::INVENTORY_WRITE);

        return $this->transactions->transactional(function () use (
            $identity,
            $homeId,
            $sessionId,
            $expectedRevision,
        ): array {
            $session = $this->inventory->countSession($homeId, $sessionId);
            if ($session === null) {
                throw new Problem(404, 'Not found', 'The requested resource is unavailable.');
            }
            if ((string) $session['status'] === 'cancelled') {
                if ((int) $session['revision'] - 1 !== $expectedRevision) {
                    throw new Problem(409, 'Revision conflict', 'The count session changed on another device.');
                }

                return [
                    'sessionId' => $sessionId,
                    'status' => 'cancelled',
                    'revision' => (int) $session['revision'],
                ];
            }
            if ((string) $session['status'] !== 'open' || (int) $session['revision'] !== $expectedRevision) {
                throw new Problem(409, 'Revision conflict', 'The count session changed on another device.');
            }
            $at = $this->clock->now();
            if (
                ! $this->inventory->cancelCountSession(
                    $homeId,
                    $sessionId,
                    $expectedRevision,
                    $identity->userId,
                    $at,
                )
            ) {
                throw new Problem(409, 'Revision conflict', 'The count session changed on another device.');
            }
            $revision = $expectedRevision + 1;
            $this->changes?->put(
                $homeId,
                $identity->userId,
                'inventory-count-session',
                $sessionId,
                $revision,
                [
                    'locationId' => $session['locationId'] ?? null,
                    'notes' => (string) ($session['notes'] ?? ''),
                    'scopeComplete' => (bool) ($session['scopeComplete'] ?? false),
                    'reliability' => (string) ($session['reliability'] ?? 'unassessed'),
                    'status' => 'cancelled',
                ],
                $at,
            );

            return ['sessionId' => $sessionId, 'status' => 'cancelled', 'revision' => $revision];
        });
    }

    public function recordApprovedInbound(
        string $actorUserId,
        string $homeId,
        string $homeProductId,
        string $quantity,
        string $sourceType,
        string $sourceId,
        string $reason,
        DateTimeImmutable $occurredAt,
    ): array {
        return $this->recordMovement(
            $actorUserId,
            $homeId,
            $homeProductId,
            'purchase-in',
            $this->quantity($quantity)->toString(),
            $sourceType,
            $sourceId,
            $reason,
            $occurredAt,
        );
    }

    /** @return list<array<string, mixed>> */
    public function movements(
        AuthenticatedIdentity $identity,
        string $homeId,
        ?string $homeProductId,
        int $limit,
        int $offset,
    ): array {
        $this->authorization->requirePermission($identity, $homeId, HomePermission::INVENTORY_READ);

        return $this->inventory->movements(
            $homeId,
            $homeProductId === '' ? null : $homeProductId,
            min(100, max(1, $limit)),
            max(0, $offset),
        );
    }

    /** @return array{products: int, quantity: string} */
    public function rebuild(AuthenticatedIdentity $identity, string $homeId): array
    {
        $this->authorization->requirePermission($identity, $homeId, HomePermission::INVENTORY_MANAGE);

        return $this->transactions->transactional(
            fn (): array => $this->inventory->rebuildBalances($homeId, $this->clock->now()),
        );
    }

    /** @return array<string, mixed> */
    private function recordMovement(
        string $actorUserId,
        string $homeId,
        string $homeProductId,
        string $movementType,
        string $quantityDelta,
        string $sourceType,
        string $sourceId,
        string $reason,
        DateTimeImmutable $occurredAt,
    ): array {
        if ($this->inventory->homeProduct($homeId, $homeProductId) === null) {
            throw new Problem(404, 'Not found', 'The requested resource is unavailable.');
        }

        try {
            $result = $this->inventory->appendMovement(
                $this->ids->generate(),
                $homeId,
                $homeProductId,
                $movementType,
                $quantityDelta,
                $sourceType,
                $sourceId,
                mb_substr($reason, 0, 191),
                $actorUserId,
                $occurredAt,
                $this->clock->now(),
            );
        } catch (DomainException) {
            throw new Problem(409, 'Product unavailable', 'The product was archived while stock was changing.');
        }
        if (! (bool) ($result['replayed'] ?? false)) {
            $this->changes?->put(
                $homeId,
                $actorUserId,
                'inventory-balance',
                $homeProductId,
                (int) $result['balanceRevision'],
                [
                    'homeProductId' => $homeProductId,
                    'quantity' => (string) $result['balance'],
                    'lastMovementId' => (string) $result['id'],
                ],
                $this->clock->now(),
            );
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $session
     * @param list<array<string, mixed>>|null $lines
     * @return array<string, mixed>
     */
    private function stockCountSession(string $homeId, array $session, ?array $lines = null): array
    {
        $session['homeId'] = $homeId;
        $session['revision'] = (int) ($session['revision'] ?? 0);
        if (array_key_exists('scopeComplete', $session)) {
            $session['scopeComplete'] = (bool) $session['scopeComplete'];
        }
        if (array_key_exists('lineCount', $session)) {
            $session['lineCount'] = (int) $session['lineCount'];
        }
        if ($lines !== null) {
            $session['lines'] = array_map(
                fn (array $line): array => $this->stockCountLine($line),
                $lines,
            );
        }

        return $session;
    }

    /**
     * SQLite's NUMERIC affinity hydrates whole and fractional DECIMAL values
     * as integers/floats while MySQL and MariaDB hydrate the same columns as
     * strings. Keep the public representation deterministic across every
     * supported database instead of leaking driver-specific JSON types.
     *
     * @param array<string, mixed> $line
     * @return array<string, mixed>
     */
    private function stockCountLine(array $line): array
    {
        $line['quantity'] = $this->quantity((string) ($line['quantity'] ?? '0'))->toString();
        $line['confidence'] = ($line['confidence'] ?? null) === null
            ? null
            : DecimalQuantity::quantity((string) $line['confidence'])->toString();
        $line['revision'] = (int) ($line['revision'] ?? 0);

        return $line;
    }

    private function quantity(string|int $value): DecimalQuantity
    {
        try {
            return DecimalQuantity::quantity($value);
        } catch (InvalidArgumentException $error) {
            throw new Problem(422, 'Invalid quantity', $error->getMessage());
        }
    }

    private function delta(string|int $value): DecimalQuantity
    {
        try {
            return DecimalQuantity::delta($value);
        } catch (InvalidArgumentException $error) {
            throw new Problem(422, 'Invalid quantity', $error->getMessage());
        }
    }

    private function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value) ?? $value;

        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }

    private function categoryName(string $name): string
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 191) {
            throw new Problem(422, 'Invalid category', 'Category name must contain 1 to 191 characters.');
        }

        return $name;
    }

    /** @return array{?string, ?string} */
    private function categoryFilters(?string $categoryId, ?string $homeCategoryId): array
    {
        $categoryId = $categoryId === null || trim($categoryId) === '' ? null : trim($categoryId);
        $homeCategoryId = $homeCategoryId === null || trim($homeCategoryId) === ''
            ? null
            : trim($homeCategoryId);
        if ($categoryId !== null && $homeCategoryId !== null) {
            throw new Problem(
                422,
                'Invalid category filter',
                'Use either categoryId for the global catalog or homeCategoryId for private products.',
            );
        }
        if (
            ($categoryId !== null && ! $this->validIdentifier($categoryId))
            || ($homeCategoryId !== null && ! $this->validIdentifier($homeCategoryId))
        ) {
            throw new Problem(422, 'Invalid category filter', 'Category filters must be UUIDs.');
        }

        return [$categoryId, $homeCategoryId];
    }

    private function identifier(?string $requestedId): string
    {
        if ($requestedId === null) {
            return $this->ids->generate();
        }
        if (! $this->validIdentifier($requestedId)) {
            throw new Problem(422, 'Invalid identifier', 'The client-provided identifier is invalid.');
        }

        return strtolower($requestedId);
    }

    private function validIdentifier(string $value): bool
    {
        return preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $value,
        ) === 1;
    }
}
