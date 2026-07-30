<?php

declare(strict_types=1);

namespace Providentia\Inventory\Application;

use DateTimeImmutable;
use DomainException;
use InvalidArgumentException;
use Providentia\Home\Application\HomeAuthorization;
use Providentia\Identity\Application\AuthenticatedIdentity;
use Providentia\Inventory\Domain\DecimalQuantity;
use Providentia\SharedKernel\Application\Clock;
use Providentia\SharedKernel\Application\Problem;
use Providentia\SharedKernel\Application\TransactionManager;
use Providentia\SharedKernel\Application\UuidGenerator;

final class InventoryService implements InventoryMovementGateway
{
    private const WRITERS = [
        HomeAuthorization::OWNER,
        HomeAuthorization::MANAGER,
        HomeAuthorization::MEMBER,
    ];

    public function __construct(
        private readonly InventoryStore $inventory,
        private readonly HomeAuthorization $authorization,
        private readonly UuidGenerator $ids,
        private readonly Clock $clock,
        private readonly TransactionManager $transactions,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function locations(AuthenticatedIdentity $identity, string $homeId): array
    {
        $this->authorization->requireMember($identity, $homeId);

        return $this->inventory->locations($homeId);
    }

    /** @return array{id: string} */
    public function createLocation(
        AuthenticatedIdentity $identity,
        string $homeId,
        string $name,
        string $kind,
    ): array {
        $this->authorization->requireRole($identity, $homeId, self::WRITERS);
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 120) {
            throw new Problem(422, 'Invalid location', 'Location name must contain 1 to 120 characters.');
        }
        if (! in_array($kind, ['pantry', 'shelf', 'fridge', 'freezer', 'household', 'other'], true)) {
            throw new Problem(422, 'Invalid location', 'Location kind is not supported.');
        }
        $id = $this->ids->generate();
        $this->inventory->createLocation($id, $homeId, $name, $this->normalize($name), $kind, $this->clock->now());

        return ['id' => $id];
    }

    /** @return list<array<string, mixed>> */
    public function itemMaster(
        AuthenticatedIdentity $identity,
        string $homeId,
        string $query,
        ?string $categoryId,
        int $limit,
        int $offset,
    ): array {
        $this->authorization->requireMember($identity, $homeId);

        return $this->inventory->itemMaster(
            $homeId,
            mb_substr(trim($query), 0, 191),
            $categoryId === '' ? null : $categoryId,
            min(100, max(1, $limit)),
            max(0, $offset),
        );
    }

    /** @return list<array<string, mixed>> */
    public function stock(
        AuthenticatedIdentity $identity,
        string $homeId,
        string $query,
        ?string $categoryId,
        int $limit,
        int $offset,
    ): array {
        $this->authorization->requireMember($identity, $homeId);

        return $this->inventory->stock(
            $homeId,
            mb_substr(trim($query), 0, 191),
            $categoryId === '' ? null : $categoryId,
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
    ): array {
        $this->authorization->requireRole($identity, $homeId, self::WRITERS);
        $privateName = $privateName === null ? null : trim($privateName);
        if ($productId === null && ($privateName === null || $privateName === '')) {
            throw new Problem(422, 'Invalid item', 'Choose a catalog product or provide a private product name.');
        }
        if ($productId !== null && $productId === '') {
            $productId = null;
        }
        if ($packId !== null && $packId === '') {
            $packId = null;
        }
        if ($privateName !== null && mb_strlen($privateName) > 191) {
            throw new Problem(422, 'Invalid item', 'Private product name exceeds 191 characters.');
        }
        $originalPackText = $originalPackText === null ? null : trim($originalPackText);
        if ($originalPackText !== null && mb_strlen($originalPackText) > 191) {
            throw new Problem(422, 'Invalid item', 'Original pack text exceeds 191 characters.');
        }
        $id = $this->ids->generate();
        try {
            $this->inventory->createHomeProduct(
                $id,
                $homeId,
                $productId,
                $packId,
                $privateName,
                $privateName === null ? null : $this->normalize($privateName),
                $originalPackText,
                $this->clock->now(),
            );
        } catch (DomainException $error) {
            throw new Problem(422, 'Invalid item', $error->getMessage());
        }

        return ['id' => $id];
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
        $this->authorization->requireRole($identity, $homeId, self::WRITERS);
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

    /** @return array{id: string, revision: int} */
    public function startCount(
        AuthenticatedIdentity $identity,
        string $homeId,
        ?string $locationId,
        string $notes,
    ): array {
        $this->authorization->requireRole($identity, $homeId, self::WRITERS);
        $notes = trim($notes);
        if (mb_strlen($notes) > 2000) {
            throw new Problem(422, 'Invalid count session', 'Count-session notes exceed 2000 characters.');
        }
        $id = $this->ids->generate();
        try {
            $this->inventory->createCountSession(
                $id,
                $homeId,
                $locationId === '' ? null : $locationId,
                $notes,
                $identity->userId,
                $this->clock->now(),
            );
        } catch (DomainException $error) {
            throw new Problem(422, 'Invalid count session', $error->getMessage());
        }

        return ['id' => $id, 'revision' => 1];
    }

    /** @return list<array<string, mixed>> */
    public function countSessions(
        AuthenticatedIdentity $identity,
        string $homeId,
        int $limit,
        int $offset,
    ): array {
        $this->authorization->requireMember($identity, $homeId);

        return $this->inventory->countSessions($homeId, min(100, max(1, $limit)), max(0, $offset));
    }

    /** @return array<string, mixed> */
    public function countSession(
        AuthenticatedIdentity $identity,
        string $homeId,
        string $sessionId,
    ): array {
        $this->authorization->requireMember($identity, $homeId);
        $session = $this->inventory->countSession($homeId, $sessionId);
        if ($session === null) {
            throw new Problem(404, 'Not found', 'The requested resource is unavailable.');
        }
        $session['lines'] = $this->inventory->countLines($homeId, $sessionId);

        return $session;
    }

    /** @return array{id: string} */
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
        $this->authorization->requireRole($identity, $homeId, self::WRITERS);
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
        $lineId = $lineId === '' ? $this->ids->generate() : $lineId;
        $this->transactions->transactional(function () use (
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
        ): void {
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
        });

        return ['id' => $lineId];
    }

    /** @return array{sessionId: string, movements: int} */
    public function closeCount(
        AuthenticatedIdentity $identity,
        string $homeId,
        string $sessionId,
        int $expectedRevision,
    ): array {
        $this->authorization->requireRole($identity, $homeId, self::WRITERS);

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
                return ['sessionId' => $sessionId, 'movements' => 0];
            }
            if ((string) $session['status'] !== 'open' || (int) $session['revision'] !== $expectedRevision) {
                throw new Problem(409, 'Revision conflict', 'The count session changed on another device.');
            }
            $lines = $this->inventory->countLines($homeId, $sessionId);
            if ($lines === []) {
                throw new Problem(422, 'Empty count', 'At least one confirmed count line is required.');
            }
            $movementCount = 0;
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
                $movementCount++;
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

            return ['sessionId' => $sessionId, 'movements' => $movementCount];
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
        $this->authorization->requireMember($identity, $homeId);

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
        $this->authorization->requireRole($identity, $homeId, [
            HomeAuthorization::OWNER,
            HomeAuthorization::MANAGER,
        ]);

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

        return $this->inventory->appendMovement(
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
}
