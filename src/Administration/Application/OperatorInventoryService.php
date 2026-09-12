<?php

declare(strict_types=1);

namespace Providentia\Administration\Application;

use Closure;
use Providentia\Access\Application\AccessService;
use Providentia\Access\Application\AccessStore;
use Providentia\Home\Application\HomePermission;
use Providentia\Identity\Application\AuthenticatedIdentity;
use Providentia\Inventory\Application\InventoryService;
use Providentia\Inventory\Application\InventoryStore;
use Providentia\Purchasing\Application\PurchasingService;
use Providentia\Purchasing\Application\PurchasingStore;
use Providentia\SharedKernel\Application\Problem;
use Providentia\SharedKernel\Application\TransactionManager;

/** Operator policy and audit surround the same inventory rules used by homeowners. */
final class OperatorInventoryService
{
    public function __construct(
        private readonly InventoryService $inventory,
        private readonly InventoryStore $records,
        private readonly PurchasingService $purchasing,
        private readonly PurchasingStore $stores,
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
    public function createProduct(AuthenticatedIdentity $actor, string $homeId, array $input): array
    {
        $this->authorizeInput($actor, $homeId, $input, true, [
            'productId', 'packId', 'privateName', 'originalPackText', 'homeCategoryId',
        ]);
        $id = (string) $input['id'];
        return $this->mutate($actor, $homeId, 'product', $id, $input, function () use (
            $actor,
            $homeId,
            $input,
            $id,
        ): array {
            return $this->inventory->addHomeProduct(
                $actor,
                $homeId,
                $this->nullableText($input, 'productId'),
                $this->nullableText($input, 'packId'),
                $this->nullableText($input, 'privateName'),
                $this->nullableText($input, 'originalPackText'),
                $this->nullableText($input, 'homeCategoryId'),
                $id,
            );
        });
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function updateProduct(
        AuthenticatedIdentity $actor,
        string $homeId,
        string $id,
        array $input,
    ): array {
        $this->authorizeInput($actor, $homeId, $input, false, [
            'privateName', 'originalPackText', 'homeCategoryId', 'status',
        ]);
        return $this->mutate($actor, $homeId, 'product', $id, $input, function () use (
            $actor,
            $homeId,
            $input,
            $id,
        ): array {
            return $this->inventory->updateHomeProduct(
                $actor,
                $homeId,
                $id,
                array_key_exists('privateName', $input),
                $this->nullableText($input, 'privateName'),
                array_key_exists('originalPackText', $input),
                $this->nullableText($input, 'originalPackText'),
                array_key_exists('homeCategoryId', $input),
                $this->nullableText($input, 'homeCategoryId'),
                $this->nullableText($input, 'status'),
                (int) $input['expectedRevision'],
            );
        });
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function createCategory(AuthenticatedIdentity $actor, string $homeId, array $input): array
    {
        $this->authorizeInput($actor, $homeId, $input, true, ['name']);
        $id = (string) $input['id'];
        return $this->mutate($actor, $homeId, 'category', $id, $input, function () use (
            $actor,
            $homeId,
            $input,
            $id,
        ): array {
            return $this->inventory->createHomeCategory($actor, $homeId, (string) ($input['name'] ?? ''), $id);
        });
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function updateCategory(
        AuthenticatedIdentity $actor,
        string $homeId,
        string $id,
        array $input,
    ): array {
        $this->authorizeInput($actor, $homeId, $input, false, ['name', 'status']);
        return $this->mutate($actor, $homeId, 'category', $id, $input, function () use (
            $actor,
            $homeId,
            $input,
            $id,
        ): array {
            return $this->inventory->updateHomeCategory(
                $actor,
                $homeId,
                $id,
                $this->nullableText($input, 'name'),
                $this->nullableText($input, 'status'),
                (int) $input['expectedRevision'],
            );
        });
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function createLocation(AuthenticatedIdentity $actor, string $homeId, array $input): array
    {
        $this->authorizeInput(
            $actor,
            $homeId,
            $input,
            true,
            ['name', 'kind'],
            HomePermission::INVENTORY_WRITE,
        );
        $id = (string) $input['id'];
        return $this->mutate($actor, $homeId, 'location', $id, $input, function () use (
            $actor,
            $homeId,
            $input,
            $id,
        ): array {
            $this->inventory->createLocation(
                $actor,
                $homeId,
                $this->nullableText($input, 'name') ?? '',
                $this->nullableText($input, 'kind') ?? '',
                $id,
            );
            return $this->record($homeId, 'location', $id)
                ?? throw new \LogicException('Created location is unavailable.');
        });
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function updateLocation(
        AuthenticatedIdentity $actor,
        string $homeId,
        string $id,
        array $input,
    ): array {
        $this->authorizeInput(
            $actor,
            $homeId,
            $input,
            false,
            ['name', 'kind', 'status'],
            HomePermission::INVENTORY_WRITE,
        );
        return $this->mutate($actor, $homeId, 'location', $id, $input, function () use (
            $actor,
            $homeId,
            $input,
            $id,
        ): array {
            return $this->inventory->updateLocation(
                $actor,
                $homeId,
                $id,
                $this->nullableText($input, 'name'),
                $this->nullableText($input, 'kind'),
                $this->nullableText($input, 'status'),
                (int) $input['expectedRevision'],
            );
        });
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function createStore(AuthenticatedIdentity $actor, string $homeId, array $input): array
    {
        $this->authorizeInput(
            $actor,
            $homeId,
            $input,
            true,
            ['name', 'location'],
            HomePermission::PURCHASES_WRITE,
        );
        $id = (string) $input['id'];
        return $this->mutate($actor, $homeId, 'store', $id, $input, function () use (
            $actor,
            $homeId,
            $input,
            $id,
        ): array {
            $this->purchasing->createStore(
                $actor,
                $homeId,
                $this->nullableText($input, 'name') ?? '',
                $this->nullableText($input, 'location') ?? '',
                $id,
            );
            return $this->record($homeId, 'store', $id)
                ?? throw new \LogicException('Created store is unavailable.');
        });
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function updateStore(
        AuthenticatedIdentity $actor,
        string $homeId,
        string $id,
        array $input,
    ): array {
        $this->authorizeInput(
            $actor,
            $homeId,
            $input,
            false,
            ['name', 'location', 'status'],
            HomePermission::PURCHASES_WRITE,
        );
        return $this->mutate($actor, $homeId, 'store', $id, $input, function () use (
            $actor,
            $homeId,
            $input,
            $id,
        ): array {
            return $this->purchasing->updateStore(
                $actor,
                $homeId,
                $id,
                $this->nullableText($input, 'name'),
                $this->nullableText($input, 'location'),
                $this->nullableText($input, 'status'),
                (int) $input['expectedRevision'],
            );
        });
    }

    /**
     * @param array<string, mixed> $input
     * @param list<string> $fields
     */
    private function authorizeInput(
        AuthenticatedIdentity $actor,
        string $homeId,
        array $input,
        bool $creating,
        array $fields,
        string $permission = HomePermission::INVENTORY_WRITE,
    ): void {
        $this->authorization->requirePermission($actor, $homeId, $permission);
        $allowed = [...$fields, 'reason', 'expectedRevision', ...($creating ? ['id'] : [])];
        if (array_diff(array_keys($input), $allowed) !== []) {
            throw new Problem(422, 'Invalid change', 'The request contains unsupported fields.');
        }
        foreach ($fields as $field) {
            $this->nullableText($input, $field);
        }
        $reason = $input['reason'] ?? null;
        if (! is_string($reason) || trim($reason) === '' || mb_strlen(trim($reason)) > 500) {
            throw new Problem(422, 'Audit reason required', 'Provide a reason containing 1 to 500 characters.');
        }
        $revision = $input['expectedRevision'] ?? null;
        if (! is_int($revision) || ($creating ? $revision !== 0 : $revision < 1)) {
            throw new Problem(422, 'Invalid revision', 'Use zero for creation or the current record revision.');
        }
        if ($creating) {
            $this->identifier($input['id'] ?? null);
        }
    }

    /**
     * @param array<string, mixed> $input
     * @param Closure(): array<string, mixed> $operation
     * @return array<string, mixed>
     */
    private function mutate(
        AuthenticatedIdentity $actor,
        string $homeId,
        string $kind,
        string $id,
        array $input,
        Closure $operation,
    ): array {
        $this->identifier($id);
        return $this->transactions->transactional(function () use (
            $actor,
            $homeId,
            $kind,
            $id,
            $input,
            $operation,
        ): array {
            $this->access->serialize('home', $homeId);
            $before = $this->record($homeId, $kind, $id);
            if ($input['expectedRevision'] === 0 && $before !== null) {
                throw new Problem(409, 'Record already exists', 'Reload the household records before retrying.');
            }
            $result = $operation();
            if (isset($result['revision'])) {
                $result['revision'] = (int) $result['revision'];
            }
            $this->audit->audit($actor->userId, 'operator.home.' . $kind . '.saved', 'home', $homeId, [
                'entityId' => $id,
                'reason' => trim((string) $input['reason']),
                'expectedRevision' => $input['expectedRevision'],
                'before' => $before,
                'after' => $this->record($homeId, $kind, $id),
            ]);
            return $result;
        });
    }

    /** @return array<string, mixed>|null */
    private function record(string $homeId, string $kind, string $id): ?array
    {
        return match ($kind) {
            'product' => $this->records->homeProduct($homeId, $id, true),
            'category' => $this->records->homeCategory($homeId, $id),
            'location' => $this->records->location($homeId, $id),
            'store' => $this->stores->store($homeId, $id),
            default => throw new \LogicException('Unsupported operator record.'),
        };
    }

    /** @param array<string, mixed> $input */
    private function nullableText(array $input, string $key): ?string
    {
        $value = $input[$key] ?? null;
        if ($value !== null && ! is_string($value)) {
            throw new Problem(422, 'Invalid change', 'Text fields must be strings or null.');
        }
        return $value;
    }

    private function identifier(mixed $id): void
    {
        $pattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';
        if (! is_string($id) || preg_match($pattern, $id) !== 1) {
            throw new Problem(422, 'Invalid identifier', 'Use a UUID for the household record.');
        }
    }
}
