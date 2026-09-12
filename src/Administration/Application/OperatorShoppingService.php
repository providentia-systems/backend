<?php

declare(strict_types=1);

namespace Providentia\Administration\Application;

use Closure;
use Providentia\Access\Application\AccessService;
use Providentia\Access\Application\AccessStore;
use Providentia\Home\Application\HomePermission;
use Providentia\Identity\Application\AuthenticatedIdentity;
use Providentia\SharedKernel\Application\Problem;
use Providentia\SharedKernel\Application\TransactionManager;
use Providentia\Shopping\Application\ShoppingService;
use Providentia\Shopping\Application\ShoppingStore;

/** Operator access and audit around the ordinary shopping domain rules. */
final class OperatorShoppingService
{
    public function __construct(
        private readonly ShoppingService $shopping,
        private readonly ShoppingStore $records,
        private readonly OperatorInventoryAuthorization $authorization,
        private readonly AccessService $access,
        private readonly AccessStore $audit,
        private readonly TransactionManager $transactions,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function saveList(
        AuthenticatedIdentity $actor,
        string $homeId,
        string $listId,
        array $input,
        bool $creating,
    ): array {
        return $this->mutate(
            $actor,
            $homeId,
            $listId,
            null,
            $input,
            $creating,
            $creating ? ['name' => 'string', 'kind' => 'string'] : ['name' => 'string', 'status' => 'string'],
            function (?array $before) use ($actor, $homeId, $listId, $input, $creating): void {
                if ($creating) {
                    $this->shopping->createList(
                        $actor,
                        $homeId,
                        (string) ($input['name'] ?? ''),
                        (string) ($input['kind'] ?? 'manual'),
                        $listId,
                    );
                } else {
                    $before ??= throw new \LogicException('Existing shopping record is required.');
                    $this->shopping->updateList(
                        $actor,
                        $homeId,
                        $listId,
                        (string) ($input['name'] ?? $before['name']),
                        (string) ($input['status'] ?? $before['status']),
                        (int) $input['expectedRevision'],
                    );
                }
            },
        );
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function saveLine(
        AuthenticatedIdentity $actor,
        string $homeId,
        string $listId,
        string $lineId,
        array $input,
        bool $creating,
    ): array {
        $fields = ['description' => 'string', 'quantityToBuy' => 'string'];
        $fields += $creating
            ? ['homeProductId' => 'nullable-string', 'expectedListRevision' => 'integer']
            : ['archived' => 'boolean'];
        return $this->mutate(
            $actor,
            $homeId,
            $listId,
            $lineId,
            $input,
            $creating,
            $fields,
            function (?array $before) use ($actor, $homeId, $listId, $lineId, $input, $creating): void {
                if ($creating) {
                    $revision = $input['expectedListRevision'] ?? 0;
                    if ($revision < 1) {
                        throw new Problem(422, 'Invalid revision', 'Provide the current parent list revision.');
                    }
                    $this->shopping->addLine(
                        $actor,
                        $homeId,
                        $listId,
                        (int) $revision,
                        isset($input['homeProductId']) ? (string) $input['homeProductId'] : null,
                        (string) ($input['description'] ?? ''),
                        (string) ($input['quantityToBuy'] ?? ''),
                        $lineId,
                    );
                } else {
                    $before ??= throw new \LogicException('Existing shopping record is required.');
                    $this->shopping->updateLine(
                        $actor,
                        $homeId,
                        $listId,
                        $lineId,
                        (string) ($input['description'] ?? $before['description']),
                        (string) ($input['quantityToBuy'] ?? $before['quantityToBuy']),
                        $input['archived'] ?? ($before['archivedAt'] !== null),
                        (int) $input['expectedRevision'],
                    );
                }
            },
        );
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function checkLine(
        AuthenticatedIdentity $actor,
        string $homeId,
        string $listId,
        string $lineId,
        array $input,
    ): array {
        return $this->mutate(
            $actor,
            $homeId,
            $listId,
            $lineId,
            $input,
            false,
            ['checked' => 'boolean'],
            function () use ($actor, $homeId, $listId, $lineId, $input): void {
                $this->shopping->setChecked(
                    $actor,
                    $homeId,
                    $listId,
                    $lineId,
                    (bool) $input['checked'],
                    (int) $input['expectedRevision'],
                );
            },
        );
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, string> $fields
     * @param Closure(?array<string, mixed>): void $operation
     * @return array<string, mixed>
     */
    private function mutate(
        AuthenticatedIdentity $actor,
        string $homeId,
        string $listId,
        ?string $lineId,
        array $input,
        bool $creating,
        array $fields,
        Closure $operation,
    ): array {
        $this->authorization->requirePermission($actor, $homeId, HomePermission::SHOPPING_WRITE);
        $this->identifier($listId);
        if ($lineId !== null) {
            $this->identifier($lineId);
        }
        $this->validate($input, $creating, $fields);
        if ($creating && $input['id'] !== ($lineId ?? $listId)) {
            throw new Problem(422, 'Invalid identifier', 'The requested shopping identifier does not match.');
        }
        return $this->transactions->transactional(function () use (
            $actor,
            $homeId,
            $listId,
            $lineId,
            $input,
            $creating,
            $operation,
        ): array {
            $this->access->serialize('home', $homeId);
            $parent = $this->records->shoppingList($homeId, $listId);
            if ($lineId !== null && $parent === null) {
                throw new Problem(404, 'Shopping list unavailable', 'Choose a list from this household.');
            }
            $before = $lineId === null ? $parent : $this->records->line($homeId, $listId, $lineId);
            if ($creating && $before !== null) {
                throw new Problem(409, 'Record already exists', 'Reload before retrying creation.');
            }
            if (! $creating && $before === null) {
                throw new Problem(404, 'Record unavailable', 'Choose a record from this household and list.');
            }
            $operation($before);
            $after = $lineId === null
                ? $this->records->shoppingList($homeId, $listId)
                : $this->records->line($homeId, $listId, $lineId);
            if ($after === null) {
                throw new \LogicException('Saved shopping record is unavailable.');
            }
            $after['revision'] = (int) $after['revision'];
            if ($lineId === null) {
                $after['homeId'] = $homeId;
            } else {
                $after['checked'] = $after['checkedAt'] !== null;
                $after['archived'] = $after['archivedAt'] !== null;
            }
            $kind = $lineId === null ? 'shopping-list' : 'shopping-line';
            $this->audit->audit($actor->userId, 'operator.home.' . $kind . '.saved', 'home', $homeId, [
                'entityId' => $lineId ?? $listId,
                'listId' => $listId,
                'reason' => trim((string) $input['reason']),
                'expectedRevision' => $input['expectedRevision'],
                'before' => $before,
                'after' => $after,
                'parentBefore' => $lineId === null ? null : $parent,
                'parentAfter' => $lineId === null ? null : $this->records->shoppingList($homeId, $listId),
            ]);
            return $after;
        });
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, string> $fields
     */
    private function validate(array $input, bool $creating, array $fields): void
    {
        $allowed = [...array_keys($fields), 'reason', 'expectedRevision', ...($creating ? ['id'] : [])];
        if (
            array_diff(array_keys($input), $allowed) !== []
            || array_intersect(array_keys($input), array_keys($fields)) === []
        ) {
            throw new Problem(422, 'Invalid change', 'Provide only supported shopping fields.');
        }
        foreach ($fields as $field => $type) {
            if (! array_key_exists($field, $input)) {
                continue;
            }
            $valid = match ($type) {
                'string' => is_string($input[$field]),
                'nullable-string' => $input[$field] === null || is_string($input[$field]),
                'integer' => is_int($input[$field]),
                'boolean' => is_bool($input[$field]),
                default => false,
            };
            if (! $valid) {
                throw new Problem(422, 'Invalid change', 'A shopping field has an invalid type.');
            }
        }
        $revision = $input['expectedRevision'] ?? null;
        if (! is_int($revision) || ($creating ? $revision !== 0 : $revision < 1)) {
            throw new Problem(422, 'Invalid revision', 'Provide zero for creation or the current record revision.');
        }
        $reason = $input['reason'] ?? null;
        if (! is_string($reason) || trim($reason) === '' || mb_strlen(trim($reason)) > 500) {
            throw new Problem(422, 'Audit reason required', 'Provide a reason containing 1 to 500 characters.');
        }
        if ($creating) {
            $this->identifier($input['id'] ?? null);
        }
    }

    private function identifier(mixed $id): void
    {
        $pattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';
        if (! is_string($id) || preg_match($pattern, $id) !== 1) {
            throw new Problem(422, 'Invalid identifier', 'Use a UUID for the shopping record.');
        }
    }
}
