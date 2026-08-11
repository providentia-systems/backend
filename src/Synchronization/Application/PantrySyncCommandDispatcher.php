<?php

declare(strict_types=1);

namespace Providentia\Synchronization\Application;

use Providentia\Identity\Application\AuthenticatedIdentity;
use Providentia\Inventory\Application\InventoryService;
use Providentia\Purchasing\Application\PurchasingService;
use Providentia\Shopping\Application\ShoppingService;

/**
 * Thin protocol adapter into the authoritative pantry application services.
 */
final readonly class PantrySyncCommandDispatcher implements SyncCommandDispatcher
{
    public function __construct(
        private InventoryService $inventory,
        private PurchasingService $purchasing,
        private ShoppingService $shopping,
    ) {
    }

    public function dispatch(
        AuthenticatedIdentity $identity,
        string $homeId,
        SyncCommand $command,
    ): array {
        $payload = $command->payload;

        return match ($command->commandType) {
            'inventory.location.create' => $this->inventory->createLocation(
                $identity,
                $homeId,
                $this->string($payload, 'name'),
                $this->string($payload, 'kind'),
                $command->entityId,
            ),
            'inventory.home-product.create' => $this->inventory->addHomeProduct(
                $identity,
                $homeId,
                $this->nullableString($payload, 'productId'),
                $this->nullableString($payload, 'packId'),
                $this->nullableString($payload, 'privateName'),
                $this->nullableString($payload, 'originalPackText'),
                $command->entityId,
            ),
            'inventory.adjustment.create' => $this->inventory->manualAdjustment(
                $identity,
                $homeId,
                $command->entityId,
                $this->string($payload, 'quantityDelta'),
                $this->string($payload, 'reason'),
                $command->operationId,
            ),
            'inventory.count-session.create' => $this->inventory->startCount(
                $identity,
                $homeId,
                $this->nullableString($payload, 'locationId'),
                $this->string($payload, 'notes'),
                $this->boolean($payload, 'scopeComplete'),
                $this->string($payload, 'reliability'),
                $command->entityId,
            ),
            'inventory.count-line.upsert' => $this->inventory->recordCount(
                $identity,
                $homeId,
                $this->string($payload, 'sessionId'),
                $command->entityId,
                $this->string($payload, 'homeProductId'),
                $this->string($payload, 'quantity'),
                $this->nullableString($payload, 'confidence'),
                $this->string($payload, 'source'),
                $this->string($payload, 'notes'),
                $this->revision($command),
            ),
            'inventory.count-session.close' => $this->inventory->closeCount(
                $identity,
                $homeId,
                $command->entityId,
                $this->revision($command),
            ),
            'inventory.count-session.cancel' => $this->inventory->cancelCount(
                $identity,
                $homeId,
                $command->entityId,
                $this->revision($command),
            ),
            'purchasing.store.create' => $this->purchasing->createStore(
                $identity,
                $homeId,
                $this->string($payload, 'name'),
                $this->string($payload, 'location'),
                $command->entityId,
            ),
            'purchasing.receipt.create' => $this->purchasing->createReceipt(
                $identity,
                $homeId,
                $this->nullableString($payload, 'storeId'),
                $this->string($payload, 'purchaseDate'),
                $this->string($payload, 'currency'),
                $this->nullableString($payload, 'totalAmount'),
                $this->string($payload, 'notes'),
                $this->nullableString($payload, 'sourceReference'),
                $command->entityId,
            ),
            'purchasing.receipt-line.create' => $this->purchasing->addLine(
                $identity,
                $homeId,
                $this->string($payload, 'receiptId'),
                $this->revision($command),
                $this->string($payload, 'rawDescription'),
                $this->string($payload, 'quantity'),
                $this->nullableString($payload, 'originalPackText'),
                $this->nullableString($payload, 'unitPrice'),
                $this->nullableString($payload, 'lineTotal'),
                $command->entityId,
            ),
            'purchasing.receipt-line.approve' => $this->approveReceiptLine(
                $identity,
                $homeId,
                $command,
            ),
            'purchasing.receipt-line.unresolve' => $this->purchasing->unresolveLine(
                $identity,
                $homeId,
                $this->string($payload, 'receiptId'),
                $command->entityId,
                $this->revision($command),
            ),
            'purchasing.receipt.commit' => $this->purchasing->commit(
                $identity,
                $homeId,
                $command->entityId,
                $this->revision($command),
            ),
            'shopping.list.create' => $this->shopping->createList(
                $identity,
                $homeId,
                $this->string($payload, 'name'),
                $this->string($payload, 'kind'),
                $command->entityId,
            ),
            'shopping.list-line.create' => $this->shopping->addLine(
                $identity,
                $homeId,
                $this->string($payload, 'listId'),
                $this->revision($command),
                $this->nullableString($payload, 'homeProductId'),
                $this->string($payload, 'description'),
                $this->string($payload, 'quantity'),
                $command->entityId,
            ),
            'shopping.list-line.checked' => $this->checkShoppingLine(
                $identity,
                $homeId,
                $command,
            ),
            default => throw new \LogicException('A validated synchronization command has no dispatcher.'),
        };
    }

    /** @return array<string, mixed> */
    private function approveReceiptLine(
        AuthenticatedIdentity $identity,
        string $homeId,
        SyncCommand $command,
    ): array {
        $this->purchasing->approveLine(
            $identity,
            $homeId,
            $this->string($command->payload, 'receiptId'),
            $command->entityId,
            $this->string($command->payload, 'homeProductId'),
            $this->revision($command),
        );

        return ['id' => $command->entityId];
    }

    /** @return array<string, mixed> */
    private function checkShoppingLine(
        AuthenticatedIdentity $identity,
        string $homeId,
        SyncCommand $command,
    ): array {
        $this->shopping->setChecked(
            $identity,
            $homeId,
            $this->string($command->payload, 'listId'),
            $command->entityId,
            $this->boolean($command->payload, 'checked'),
            $this->revision($command),
        );

        return ['id' => $command->entityId];
    }

    /** @param array<string, mixed> $payload */
    private function string(array $payload, string $field): string
    {
        $value = $payload[$field] ?? null;
        if (! is_string($value)) {
            throw new \LogicException('A validated synchronization string is missing: ' . $field);
        }

        return $value;
    }

    /** @param array<string, mixed> $payload */
    private function nullableString(array $payload, string $field): ?string
    {
        $value = $payload[$field] ?? null;
        if ($value !== null && ! is_string($value)) {
            throw new \LogicException('A validated synchronization string is invalid: ' . $field);
        }

        return $value;
    }

    /** @param array<string, mixed> $payload */
    private function boolean(array $payload, string $field): bool
    {
        $value = $payload[$field] ?? null;
        if (! is_bool($value)) {
            throw new \LogicException('A validated synchronization boolean is missing: ' . $field);
        }

        return $value;
    }

    private function revision(SyncCommand $command): int
    {
        return $command->baseRevision
            ?? throw new \LogicException('A validated synchronization revision is missing.');
    }
}
