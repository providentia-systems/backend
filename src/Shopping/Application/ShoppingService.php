<?php

declare(strict_types=1);

namespace Providentia\Shopping\Application;

use DomainException;
use InvalidArgumentException;
use Providentia\Home\Application\HomePermissionAuthorizer;
use Providentia\Home\Application\HomePermission;
use Providentia\Identity\Application\AuthenticatedIdentity;
use Providentia\Inventory\Domain\DecimalQuantity;
use Providentia\SharedKernel\Application\ChangeFeedWriter;
use Providentia\SharedKernel\Application\Clock;
use Providentia\SharedKernel\Application\Problem;
use Providentia\SharedKernel\Application\TransactionManager;
use Providentia\SharedKernel\Application\UuidGenerator;
use Providentia\Shopping\Domain\LegacySuggestionPolicy;

final class ShoppingService
{
    public function __construct(
        private readonly ShoppingStore $shopping,
        private readonly HomePermissionAuthorizer $authorization,
        private readonly LegacySuggestionPolicy $legacyPolicy,
        private readonly UuidGenerator $ids,
        private readonly Clock $clock,
        private readonly TransactionManager $transactions,
        private readonly ?ChangeFeedWriter $changes = null,
        private readonly ?ShoppingIntelligenceService $intelligence = null,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function lists(AuthenticatedIdentity $identity, string $homeId): array
    {
        $this->authorization->requirePermission($identity, $homeId, HomePermission::SHOPPING_READ);

        return $this->shopping->lists($homeId);
    }

    /** @return array<string, mixed> */
    public function shoppingList(
        AuthenticatedIdentity $identity,
        string $homeId,
        string $listId,
    ): array {
        $this->authorization->requirePermission($identity, $homeId, HomePermission::SHOPPING_READ);
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
        ?string $requestedId = null,
    ): array {
        $this->authorization->requirePermission($identity, $homeId, HomePermission::SHOPPING_WRITE);
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 120) {
            throw new Problem(422, 'Invalid shopping list', 'List name must contain 1 to 120 characters.');
        }
        if (! in_array($kind, ['manual', 'mixed', 'suggested'], true)) {
            throw new Problem(422, 'Invalid shopping list', 'List kind is not supported.');
        }
        $id = $this->identifier($requestedId);
        $at = $this->clock->now();
        $this->transactions->transactional(function () use ($id, $homeId, $name, $kind, $identity, $at): void {
            $this->shopping->createList($id, $homeId, $name, $kind, $identity->userId, $at);
            $this->changes?->put(
                $homeId,
                $identity->userId,
                'shopping-list',
                $id,
                1,
                ['name' => $name, 'kind' => $kind, 'status' => 'open'],
                $at,
            );
        });

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
        ?string $requestedId = null,
        ?string $suggestionId = null,
    ): array {
        $this->authorization->requirePermission($identity, $homeId, HomePermission::SHOPPING_WRITE);
        $list = $this->requireOpenList($homeId, $listId);
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
        $id = $this->identifier($requestedId);
        try {
            $this->transactions->transactional(function () use (
                $id,
                $homeId,
                $listId,
                $expectedListRevision,
                $homeProductId,
                $description,
                $quantity,
                $identity,
                $list,
                $suggestionId,
            ): void {
                if (
                    ! $this->shopping->addLine(
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
                        $suggestionId,
                    )
                ) {
                    throw new Problem(409, 'Revision conflict', 'The shopping list changed on another device.');
                }
                $at = $this->clock->now();
                $this->publishLine($identity, $homeId, $listId, $id);
                if ($suggestionId !== null) {
                    $this->recordSuggestionOutcome($identity, $homeId, $suggestionId, $quantity, $id);
                }
                $this->changes?->put(
                    $homeId,
                    $identity->userId,
                    'shopping-list',
                    $listId,
                    $expectedListRevision + 1,
                    [
                        'name' => (string) $list['name'],
                        'kind' => (string) $list['kind'],
                        'status' => (string) $list['status'],
                    ],
                    $at,
                );
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
        $this->authorization->requirePermission($identity, $homeId, HomePermission::SHOPPING_WRITE);
        $this->requireOpenList($homeId, $listId);
        $this->transactions->transactional(function () use (
            $homeId,
            $listId,
            $lineId,
            $checked,
            $expectedRevision,
            $identity,
        ): void {
            if (
                ! $this->shopping->setChecked(
                    $homeId,
                    $listId,
                    $lineId,
                    $checked,
                    $expectedRevision,
                    $this->clock->now(),
                )
            ) {
                throw new Problem(409, 'Revision conflict', 'The shopping-list line changed on another device.');
            }
            $this->publishLine($identity, $homeId, $listId, $lineId);
            $this->publishList($identity, $homeId, $listId);
        });
    }

    /** @return array{id: string, revision: int} */
    public function updateList(
        AuthenticatedIdentity $identity,
        string $homeId,
        string $listId,
        string $name,
        string $status,
        int $expectedRevision,
    ): array {
        $this->authorization->requirePermission($identity, $homeId, HomePermission::SHOPPING_WRITE);
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 120 || ! in_array($status, ['open', 'archived'], true)) {
            throw new Problem(422, 'Invalid shopping list', 'Provide a name and an open or archived status.');
        }
        $this->transactions->transactional(function () use (
            $identity,
            $homeId,
            $listId,
            $name,
            $status,
            $expectedRevision,
        ): void {
            if (
                ! $this->shopping->updateList(
                    $homeId,
                    $listId,
                    $name,
                    $status,
                    $expectedRevision,
                    $this->clock->now(),
                )
            ) {
                throw new Problem(409, 'Revision conflict', 'The shopping list changed on another device.');
            }
            $this->publishList($identity, $homeId, $listId);
        });

        return ['id' => $listId, 'revision' => $expectedRevision + 1];
    }

    /** @return array{id: string, revision: int} */
    public function updateLine(
        AuthenticatedIdentity $identity,
        string $homeId,
        string $listId,
        string $lineId,
        string $description,
        string $quantity,
        bool $archived,
        int $expectedRevision,
    ): array {
        $this->authorization->requirePermission($identity, $homeId, HomePermission::SHOPPING_WRITE);
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
        $this->transactions->transactional(function () use (
            $identity,
            $homeId,
            $listId,
            $lineId,
            $description,
            $quantity,
            $archived,
            $expectedRevision,
        ): void {
            $previous = $this->shopping->line($homeId, $listId, $lineId);
            if (
                ! $this->shopping->updateLine(
                    $homeId,
                    $listId,
                    $lineId,
                    $description,
                    $quantity,
                    $archived,
                    $expectedRevision,
                    $this->clock->now(),
                )
            ) {
                throw new Problem(409, 'Revision conflict', 'The shopping-list line changed on another device.');
            }
            if ($previous !== null && isset($previous['suggestionId'])
                && DecimalQuantity::quantity((string) $previous['quantityToBuy'])->toString() !== $quantity
            ) {
                $this->recordSuggestionOutcome(
                    $identity, $homeId, (string) $previous['suggestionId'], $quantity, null,
                );
            }
            $this->publishLine($identity, $homeId, $listId, $lineId);
            $this->publishList($identity, $homeId, $listId);
        });

        return ['id' => $lineId, 'revision' => $expectedRevision + 1];
    }

    private function recordSuggestionOutcome(
        AuthenticatedIdentity $identity,
        string $homeId,
        string $suggestionId,
        string $quantity,
        ?string $feedbackId,
    ): void {
        if ($this->intelligence === null) {
            throw new \LogicException('Suggestion feedback is not composed for this shopping service.');
        }
        $this->intelligence->feedback(
            $identity, $homeId, $suggestionId, 'accepted', $quantity,
            'Confirmed on the shopping list.', $feedbackId,
        );
    }

    private function publishLine(
        AuthenticatedIdentity $identity,
        string $homeId,
        string $listId,
        string $lineId,
    ): void {
        $line = $this->shopping->line($homeId, $listId, $lineId);
        if ($line === null) {
            throw new \RuntimeException('The updated shopping-list line is unavailable.');
        }
        $revision = (int) $line['revision'];
        unset($line['id'], $line['revision']);
        $line['listId'] = $listId;
        $line['checked'] = ($line['checkedAt'] ?? null) !== null;
        $line['archived'] = ($line['archivedAt'] ?? null) !== null;
        $this->changes?->put(
            $homeId,
            $identity->userId,
            'shopping-list-line',
            $lineId,
            $revision,
            $line,
            $this->clock->now(),
        );
    }

    private function publishList(AuthenticatedIdentity $identity, string $homeId, string $listId): void
    {
        $list = $this->shopping->shoppingList($homeId, $listId);
        if ($list === null) {
            throw new \RuntimeException('The updated shopping list is unavailable.');
        }
        $this->changes?->put(
            $homeId,
            $identity->userId,
            'shopping-list',
            $listId,
            (int) $list['revision'],
            ['name' => $list['name'], 'kind' => $list['kind'], 'status' => $list['status']],
            $this->clock->now(),
        );
    }

    /** @return list<array<string, mixed>> */
    public function legacySuggestions(AuthenticatedIdentity $identity, string $homeId): array
    {
        $this->authorization->requirePermission($identity, $homeId, HomePermission::SHOPPING_READ);
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

    private function identifier(?string $requestedId): string
    {
        if ($requestedId === null) {
            return $this->ids->generate();
        }
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $requestedId) !== 1) {
            throw new Problem(422, 'Invalid identifier', 'The client-provided identifier is invalid.');
        }

        return strtolower($requestedId);
    }
}
