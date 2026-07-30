<?php

declare(strict_types=1);

namespace Providentia\Shopping\Application;

use DomainException;
use InvalidArgumentException;
use Providentia\Home\Application\HomeAuthorization;
use Providentia\Identity\Application\AuthenticatedIdentity;
use Providentia\Inventory\Domain\DecimalQuantity;
use Providentia\SharedKernel\Application\Clock;
use Providentia\SharedKernel\Application\Problem;
use Providentia\SharedKernel\Application\TransactionManager;
use Providentia\SharedKernel\Application\UuidGenerator;
use Providentia\Shopping\Domain\LegacySuggestionPolicy;

final class ShoppingService
{
    private const WRITERS = [
        HomeAuthorization::OWNER,
        HomeAuthorization::MANAGER,
        HomeAuthorization::MEMBER,
    ];

    public function __construct(
        private readonly ShoppingStore $shopping,
        private readonly HomeAuthorization $authorization,
        private readonly LegacySuggestionPolicy $legacyPolicy,
        private readonly UuidGenerator $ids,
        private readonly Clock $clock,
        private readonly TransactionManager $transactions,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function lists(AuthenticatedIdentity $identity, string $homeId): array
    {
        $this->authorization->requireMember($identity, $homeId);

        return $this->shopping->lists($homeId);
    }

    /** @return array<string, mixed> */
    public function shoppingList(
        AuthenticatedIdentity $identity,
        string $homeId,
        string $listId,
    ): array {
        $this->authorization->requireMember($identity, $homeId);
        $list = $this->shopping->shoppingList($homeId, $listId);
        if ($list === null) {
            throw new Problem(404, 'Not found', 'The requested resource is unavailable.');
        }
        $list['lines'] = $this->shopping->lines($homeId, $listId);

        return $list;
    }

    /** @return array{id: string, revision: int} */
    public function createList(
        AuthenticatedIdentity $identity,
        string $homeId,
        string $name,
        string $kind,
    ): array {
        $this->authorization->requireRole($identity, $homeId, self::WRITERS);
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 120) {
            throw new Problem(422, 'Invalid shopping list', 'List name must contain 1 to 120 characters.');
        }
        if (! in_array($kind, ['manual', 'mixed', 'suggested'], true)) {
            throw new Problem(422, 'Invalid shopping list', 'List kind is not supported.');
        }
        $id = $this->ids->generate();
        $this->shopping->createList($id, $homeId, $name, $kind, $identity->userId, $this->clock->now());

        return ['id' => $id, 'revision' => 1];
    }

    /** @return array{id: string} */
    public function addLine(
        AuthenticatedIdentity $identity,
        string $homeId,
        string $listId,
        int $expectedListRevision,
        ?string $homeProductId,
        string $description,
        string $quantity,
    ): array {
        $this->authorization->requireRole($identity, $homeId, self::WRITERS);
        $this->requireOpenList($homeId, $listId);
        $description = trim($description);
        if ($description === '' || mb_strlen($description) > 191) {
            throw new Problem(422, 'Invalid list line', 'Description must contain 1 to 191 characters.');
        }
        try {
            $quantity = DecimalQuantity::quantity($quantity)->toString();
        } catch (InvalidArgumentException $error) {
            throw new Problem(422, 'Invalid quantity', $error->getMessage());
        }
        if ($quantity === '0') {
            throw new Problem(422, 'Invalid quantity', 'Quantity to buy must be greater than zero.');
        }
        $id = $this->ids->generate();
        try {
            $this->transactions->transactional(function () use (
                $id,
                $homeId,
                $listId,
                $expectedListRevision,
                $homeProductId,
                $description,
                $quantity,
            ): void {
                if (! $this->shopping->addLine(
                    $id,
                    $homeId,
                    $listId,
                    $expectedListRevision,
                    $homeProductId === '' ? null : $homeProductId,
                    $description,
                    'manual',
                    $quantity,
                    'Added manually.',
                    null,
                    $this->clock->now(),
                )) {
                    throw new Problem(409, 'Revision conflict', 'The shopping list changed on another device.');
                }
            });
        } catch (DomainException $error) {
            throw new Problem(422, 'Invalid list line', $error->getMessage());
        }

        return ['id' => $id];
    }

    public function setChecked(
        AuthenticatedIdentity $identity,
        string $homeId,
        string $listId,
        string $lineId,
        bool $checked,
        int $expectedRevision,
    ): void {
        $this->authorization->requireRole($identity, $homeId, self::WRITERS);
        $this->requireOpenList($homeId, $listId);
        $this->transactions->transactional(function () use (
            $homeId,
            $listId,
            $lineId,
            $checked,
            $expectedRevision,
        ): void {
            if (! $this->shopping->setChecked(
                $homeId,
                $listId,
                $lineId,
                $checked,
                $expectedRevision,
                $this->clock->now(),
            )) {
                throw new Problem(409, 'Revision conflict', 'The shopping-list line changed on another device.');
            }
        });
    }

    /** @return list<array<string, mixed>> */
    public function legacySuggestions(AuthenticatedIdentity $identity, string $homeId): array
    {
        $this->authorization->requireMember($identity, $homeId);
        $suggestions = [];
        foreach ($this->shopping->legacySuggestionCandidates($homeId) as $candidate) {
            $quantity = $this->legacyPolicy->suggest(
                (string) $candidate['threeMonthPurchases'],
                (string) $candidate['currentQuantity'],
            );
            if ($quantity === 0 || (bool) $candidate['neverSuggest']) {
                continue;
            }
            $suggestions[] = [
                'homeProductId' => $candidate['homeProductId'],
                'productName' => $candidate['productName'],
                'packText' => $candidate['packText'],
                'quantityToBuy' => (string) $quantity,
                'algorithm' => 'legacy-apr-jun-v1',
                'confidence' => 'low',
                'dataCoverage' => 'April through June 2026 purchase history only',
                'explanation' => sprintf(
                    'Provisional parity estimate: ceil((%s / 3 months) - %s currently counted).',
                    (string) $candidate['threeMonthPurchases'],
                    (string) $candidate['currentQuantity'],
                ),
                'limitations' => [
                    'Purchase history is not the same as measured consumption.',
                    'This policy is retained only for Phase 5 parity and is replaced in Phase 8.',
                ],
            ];
        }

        return $suggestions;
    }

    /** @return array<string, mixed> */
    private function requireOpenList(string $homeId, string $listId): array
    {
        $list = $this->shopping->shoppingList($homeId, $listId);
        if ($list === null) {
            throw new Problem(404, 'Not found', 'The requested resource is unavailable.');
        }
        if ((string) $list['status'] !== 'open') {
            throw new Problem(409, 'Shopping list closed', 'Only an open shopping list can be changed.');
        }

        return $list;
    }
}
