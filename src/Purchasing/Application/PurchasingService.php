<?php

declare(strict_types=1);

namespace Providentia\Purchasing\Application;

use DateTimeImmutable;
use DomainException;
use InvalidArgumentException;
use Providentia\Home\Application\HomePermissionAuthorizer;
use Providentia\Home\Application\HomePermission;
use Providentia\Identity\Application\AuthenticatedIdentity;
use Providentia\Inventory\Application\InventoryMovementGateway;
use Providentia\Inventory\Domain\DecimalQuantity;
use Providentia\SharedKernel\Application\ChangeFeedWriter;
use Providentia\SharedKernel\Application\Clock;
use Providentia\SharedKernel\Application\Problem;
use Providentia\SharedKernel\Application\TransactionManager;
use Providentia\SharedKernel\Application\UuidGenerator;
use Throwable;

final class PurchasingService
{
    public function __construct(
        private readonly PurchasingStore $purchases,
        private readonly InventoryMovementGateway $inventory,
        private readonly HomePermissionAuthorizer $authorization,
        private readonly UuidGenerator $ids,
        private readonly Clock $clock,
        private readonly TransactionManager $transactions,
        private readonly ?ChangeFeedWriter $changes = null,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function history(
        AuthenticatedIdentity $identity,
        string $homeId,
        ?string $from,
        ?string $to,
        ?string $storeId,
        int $limit,
        int $offset,
    ): array {
        $this->authorization->requirePermission($identity, $homeId, HomePermission::PURCHASES_READ);
        $this->optionalDate($from);
        $this->optionalDate($to);

        return $this->purchases->receipts(
            $homeId,
            $from,
            $to,
            $storeId === '' ? null : $storeId,
            min(100, max(1, $limit)),
            max(0, $offset),
        );
    }

    /** @return array<string, mixed> */
    public function receipt(
        AuthenticatedIdentity $identity,
        string $homeId,
        string $receiptId,
    ): array {
        $this->authorization->requirePermission($identity, $homeId, HomePermission::PURCHASES_READ);
        $receipt = $this->purchases->receipt($homeId, $receiptId);
        if ($receipt === null) {
            throw new Problem(404, 'Not found', 'The requested resource is unavailable.');
        }
        $receipt['lines'] = $this->purchases->receiptLines($homeId, $receiptId);

        return $receipt;
    }

    /** @return list<array<string, mixed>> */
    public function stores(
        AuthenticatedIdentity $identity,
        string $homeId,
        bool $includeArchived = false,
    ): array {
        $this->authorization->requirePermission($identity, $homeId, HomePermission::PURCHASES_READ);

        return $this->purchases->stores($homeId, $includeArchived);
    }

    /** @return array<string, mixed> */
    public function updateStore(
        AuthenticatedIdentity $identity,
        string $homeId,
        string $storeId,
        ?string $name,
        ?string $location,
        ?string $status,
        int $expectedRevision,
    ): array {
        $this->authorization->requirePermission($identity, $homeId, HomePermission::PURCHASES_WRITE);
        if ($expectedRevision < 1 || ($name === null && $location === null && $status === null)) {
            throw new Problem(
                422,
                'Invalid store',
                'A positive revision and a metadata change are required.',
            );
        }
        $name = $name === null ? null : trim($name);
        if ($name !== null && ($name === '' || mb_strlen($name) > 191)) {
            throw new Problem(422, 'Invalid store', 'Name must contain 1 to 191 characters.');
        }
        $location = $location === null ? null : trim($location);
        if ($location !== null && mb_strlen($location) > 191) {
            throw new Problem(422, 'Invalid store', 'Store location exceeds 191 characters.');
        }
        if ($status !== null && !in_array($status, ['active', 'archived'], true)) {
            throw new Problem(422, 'Invalid store', 'Status must be active or archived.');
        }
        $at = $this->clock->now();
        try {
            $result = $this->transactions->transactional(function () use (
                $identity,
                $homeId,
                $storeId,
                $name,
                $location,
                $status,
                $expectedRevision,
                $at,
            ): array {
                $result = $this->purchases->updateStore(
                    $homeId,
                    $storeId,
                    $name,
                    $name === null ? null : $this->normalize($name),
                    $location,
                    $status,
                    $expectedRevision,
                    $at,
                );
                if ($result['status'] === 'updated') {
                    $record = $result['record'];
                    $this->changes?->put(
                        $homeId,
                        $identity->userId,
                        'purchasing-store',
                        $storeId,
                        (int) $record['revision'],
                        [
                            'name' => $record['name'],
                            'location' => $record['location'],
                            'status' => $record['status'],
                        ],
                        $at,
                    );
                }

                return $result;
            });
        } catch (DomainException $error) {
            throw new Problem(422, 'Invalid store', $error->getMessage());
        }

        return match ($result['status']) {
            'updated' => $result['record'],
            'not-found' => throw new Problem(404, 'Not found', 'The store is unavailable.'),
            'revision-conflict' => throw new Problem(
                409,
                'Revision conflict',
                'The store changed on another device.',
            ),
            'store-in-use' => throw new Problem(
                409,
                'Store in use',
                'Finish or discard draft receipts before removing this store.',
            ),
            default => throw new \LogicException('Unknown store update result.'),
        };
    }

    /** @return array{id: string} */
    public function createStore(
        AuthenticatedIdentity $identity,
        string $homeId,
        string $name,
        string $location,
        ?string $requestedId = null,
    ): array {
        $this->authorization->requirePermission($identity, $homeId, HomePermission::PURCHASES_WRITE);
        $name = trim($name);
        $location = trim($location);
        if ($name === '' || mb_strlen($name) > 191 || mb_strlen($location) > 191) {
            throw new Problem(422, 'Invalid store', 'Store name and location are invalid.');
        }
        $normalized = $this->normalize($name);
        $existing = $this->purchases->storeByName($homeId, $normalized, $location);
        if ($existing !== null) {
            if ($requestedId !== null && $requestedId !== (string) $existing['id']) {
                throw new Problem(409, 'Store exists', 'Select the existing store after synchronization.');
            }
            return ['id' => (string) $existing['id']];
        }
        $id = $this->identifier($requestedId);
        $at = $this->clock->now();
        try {
            $this->transactions->transactional(function () use (
                $id,
                $homeId,
                $name,
                $normalized,
                $location,
                $identity,
                $at,
            ): void {
                $this->purchases->createStore($id, $homeId, $name, $normalized, $location, $at);
                $this->changes?->put(
                    $homeId,
                    $identity->userId,
                    'purchasing-store',
                    $id,
                    1,
                    ['name' => $name, 'location' => $location, 'status' => 'active'],
                    $at,
                );
            });
        } catch (DomainException $error) {
            throw new Problem(422, 'Invalid store', $error->getMessage());
        }

        return ['id' => $id];
    }

    /** @return array{id: string, revision: int} */
    public function createReceipt(
        AuthenticatedIdentity $identity,
        string $homeId,
        ?string $storeId,
        string $purchaseDate,
        string $currency,
        ?string $totalAmount,
        string $notes,
        ?string $sourceReference,
        ?string $requestedId = null,
    ): array {
        $this->authorization->requirePermission($identity, $homeId, HomePermission::PURCHASES_WRITE);
        $this->date($purchaseDate);
        $currency = strtoupper(trim($currency));
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new Problem(422, 'Invalid receipt', 'Currency must be an ISO 4217 code.');
        }
        $totalAmount = $this->money($totalAmount, true);
        if (mb_strlen($notes) > 2000) {
            throw new Problem(422, 'Invalid receipt', 'Receipt notes exceed 2000 characters.');
        }
        $id = $this->identifier($requestedId);
        $notes = trim($notes);
        $storeId = $storeId === '' ? null : $storeId;
        $sourceReference = $sourceReference === '' ? null : $sourceReference;
        $at = $this->clock->now();
        try {
            $this->transactions->transactional(function () use (
                $id,
                $homeId,
                $storeId,
                $purchaseDate,
                $currency,
                $totalAmount,
                $sourceReference,
                $notes,
                $identity,
                $at,
            ): void {
                $this->purchases->createReceipt(
                    $id,
                    $homeId,
                    $storeId,
                    $purchaseDate,
                    $currency,
                    $totalAmount,
                    'manual',
                    $sourceReference,
                    $notes,
                    $identity->userId,
                    $at,
                );
                $this->changes?->put(
                    $homeId,
                    $identity->userId,
                    'purchasing-receipt',
                    $id,
                    1,
                    [
                        'storeId' => $storeId,
                        'purchaseDate' => $purchaseDate,
                        'currency' => $currency,
                        'totalAmount' => $totalAmount,
                        'status' => 'draft',
                        'source' => 'manual',
                        'sourceReference' => $sourceReference,
                        'notes' => $notes,
                    ],
                    $at,
                );
            });
        } catch (DomainException $error) {
            throw new Problem(422, 'Invalid receipt', $error->getMessage());
        }

        return ['id' => $id, 'revision' => 1];
    }

    /** @return array{id: string} */
    public function addLine(
        AuthenticatedIdentity $identity,
        string $homeId,
        string $receiptId,
        int $expectedReceiptRevision,
        string $rawDescription,
        string $quantity,
        ?string $originalPackText,
        ?string $unitPrice,
        ?string $lineTotal,
        ?string $requestedId = null,
    ): array {
        $this->authorization->requirePermission($identity, $homeId, HomePermission::PURCHASES_WRITE);
        $receipt = $this->requireDraft($homeId, $receiptId);
        $rawDescription = trim($rawDescription);
        if ($rawDescription === '' || mb_strlen($rawDescription) > 500) {
            throw new Problem(422, 'Invalid receipt line', 'Raw description must contain 1 to 500 characters.');
        }
        $quantity = $this->quantity($quantity);
        $unitPrice = $this->money($unitPrice, true);
        $lineTotal = $this->money($lineTotal, true);
        $originalPackText = $originalPackText === null
            ? null
            : mb_substr(trim($originalPackText), 0, 191);
        if ($unitPrice === null && $lineTotal === null) {
            throw new Problem(422, 'Invalid receipt line', 'A unit price or line total is required.');
        }
        $id = $this->identifier($requestedId);
        $this->transactions->transactional(function () use (
            $id,
            $homeId,
            $receiptId,
            $expectedReceiptRevision,
            $rawDescription,
            $quantity,
            $originalPackText,
            $unitPrice,
            $lineTotal,
            $identity,
            $receipt,
        ): void {
            $lines = $this->purchases->receiptLines($homeId, $receiptId);
            if (
                ! $this->purchases->addReceiptLine(
                    $id,
                    $homeId,
                    $receiptId,
                    $expectedReceiptRevision,
                    count($lines) + 1,
                    $rawDescription,
                    $quantity,
                    $originalPackText,
                    $unitPrice,
                    $lineTotal,
                    $this->clock->now(),
                )
            ) {
                throw new Problem(409, 'Revision conflict', 'The receipt changed on another device.');
            }
            $this->changes?->put(
                $homeId,
                $identity->userId,
                'purchasing-receipt-line',
                $id,
                1,
                [
                    'receiptId' => $receiptId,
                    'rawDescription' => $rawDescription,
                    'quantity' => $quantity,
                    'originalPackText' => $originalPackText,
                    'unitPrice' => $unitPrice,
                    'lineTotal' => $lineTotal,
                    'homeProductId' => null,
                    'approvalStatus' => 'unreviewed',
                ],
                $this->clock->now(),
            );
            $this->changes?->put(
                $homeId,
                $identity->userId,
                'purchasing-receipt',
                $receiptId,
                $expectedReceiptRevision + 1,
                [
                    'storeId' => $receipt['storeId'] ?? null,
                    'purchaseDate' => (string) $receipt['purchaseDate'],
                    'currency' => (string) $receipt['currency'],
                    'totalAmount' => $receipt['totalAmount'] ?? null,
                    'status' => (string) $receipt['status'],
                    'source' => (string) ($receipt['source'] ?? 'manual'),
                    'sourceReference' => $receipt['sourceReference'] ?? null,
                    'notes' => (string) ($receipt['notes'] ?? ''),
                ],
                $this->clock->now(),
            );
        });

        return ['id' => $id];
    }

    public function approveLine(
        AuthenticatedIdentity $identity,
        string $homeId,
        string $receiptId,
        string $lineId,
        string $homeProductId,
        int $expectedRevision,
    ): void {
        $this->authorization->requirePermission($identity, $homeId, HomePermission::PURCHASES_WRITE);
        $this->requireDraft($homeId, $receiptId);
        $this->transactions->transactional(function () use (
            $homeId,
            $receiptId,
            $lineId,
            $homeProductId,
            $expectedRevision,
            $identity,
        ): void {
            if (
                ! $this->purchases->approveReceiptLine(
                    $this->ids->generate(),
                    $homeId,
                    $receiptId,
                    $lineId,
                    $homeProductId,
                    $expectedRevision,
                    $identity->userId,
                    $this->clock->now(),
                )
            ) {
                throw new Problem(409, 'Revision conflict', 'The receipt line changed on another device.');
            }
            $line = $this->purchases->receiptLine($homeId, $receiptId, $lineId);
            if ($line === null) {
                throw new \RuntimeException('The updated receipt line is unavailable.');
            }
            $receipt = $this->purchases->receipt($homeId, $receiptId);
            if ($receipt === null) {
                throw new \RuntimeException('The updated receipt is unavailable.');
            }
            unset($line['id'], $line['revision']);
            $this->changes?->put(
                $homeId,
                $identity->userId,
                'purchasing-receipt-line',
                $lineId,
                $expectedRevision + 1,
                $line,
                $this->clock->now(),
            );
            $this->changes?->put(
                $homeId,
                $identity->userId,
                'purchasing-receipt',
                $receiptId,
                (int) $receipt['revision'],
                [
                    'storeId' => $receipt['storeId'] ?? null,
                    'purchaseDate' => (string) $receipt['purchaseDate'],
                    'currency' => (string) $receipt['currency'],
                    'totalAmount' => $receipt['totalAmount'] ?? null,
                    'status' => (string) $receipt['status'],
                    'source' => (string) ($receipt['source'] ?? 'manual'),
                    'sourceReference' => $receipt['sourceReference'] ?? null,
                    'notes' => (string) ($receipt['notes'] ?? ''),
                ],
                $this->clock->now(),
            );
        });
    }

    /** @return array{id: string, revision: int, approvalStatus: string} */
    public function unresolveLine(
        AuthenticatedIdentity $identity,
        string $homeId,
        string $receiptId,
        string $lineId,
        int $expectedRevision,
    ): array {
        $this->authorization->requirePermission($identity, $homeId, HomePermission::PURCHASES_WRITE);
        if ($expectedRevision < 1) {
            throw new Problem(422, 'Invalid revision', 'expectedRevision must be a positive integer.');
        }
        $receipt = $this->purchases->receipt($homeId, $receiptId);
        $line = $this->purchases->receiptLine($homeId, $receiptId, $lineId);
        if ($receipt === null || $line === null) {
            throw new Problem(404, 'Not found', 'The requested resource is unavailable.');
        }
        $lineRevision = (int) $line['revision'];
        if (
            (string) $line['approvalStatus'] === 'unresolved'
            && $lineRevision === $expectedRevision + 1
        ) {
            return [
                'id' => $lineId,
                'revision' => $lineRevision,
                'approvalStatus' => 'unresolved',
            ];
        }
        if ((string) $receipt['status'] !== 'draft') {
            throw new Problem(409, 'Receipt immutable', 'Only a draft receipt can be changed.');
        }
        if ($lineRevision !== $expectedRevision) {
            throw new Problem(409, 'Revision conflict', 'The receipt line changed on another device.');
        }

        return $this->transactions->transactional(function () use (
            $homeId,
            $receiptId,
            $lineId,
            $expectedRevision,
            $identity,
        ): array {
            if (
                ! $this->purchases->markReceiptLineUnresolved(
                    $homeId,
                    $receiptId,
                    $lineId,
                    $expectedRevision,
                    $this->clock->now(),
                )
            ) {
                throw new Problem(409, 'Revision conflict', 'The receipt line changed on another device.');
            }
            $updatedLine = $this->purchases->receiptLine($homeId, $receiptId, $lineId);
            $updatedReceipt = $this->purchases->receipt($homeId, $receiptId);
            if ($updatedLine === null || $updatedReceipt === null) {
                throw new \RuntimeException('The updated receipt decision is unavailable.');
            }
            unset($updatedLine['id'], $updatedLine['revision']);
            $revision = $expectedRevision + 1;
            $at = $this->clock->now();
            $this->changes?->put(
                $homeId,
                $identity->userId,
                'purchasing-receipt-line',
                $lineId,
                $revision,
                $updatedLine,
                $at,
            );
            $this->changes?->put(
                $homeId,
                $identity->userId,
                'purchasing-receipt',
                $receiptId,
                (int) $updatedReceipt['revision'],
                [
                    'storeId' => $updatedReceipt['storeId'] ?? null,
                    'purchaseDate' => (string) $updatedReceipt['purchaseDate'],
                    'currency' => (string) $updatedReceipt['currency'],
                    'totalAmount' => $updatedReceipt['totalAmount'] ?? null,
                    'status' => (string) $updatedReceipt['status'],
                    'source' => (string) ($updatedReceipt['source'] ?? 'manual'),
                    'sourceReference' => $updatedReceipt['sourceReference'] ?? null,
                    'notes' => (string) ($updatedReceipt['notes'] ?? ''),
                ],
                $at,
            );

            return [
                'id' => $lineId,
                'revision' => $revision,
                'approvalStatus' => 'unresolved',
            ];
        });
    }

    /**
     * @param array<string, mixed> $fields
     * @return array{id: string, revision: int}
     */
    public function updateReceipt(
        AuthenticatedIdentity $identity,
        string $homeId,
        string $receiptId,
        array $fields,
        int $expectedRevision,
    ): array {
        $this->authorization->requirePermission($identity, $homeId, HomePermission::PURCHASES_WRITE);
        $this->draftFields($fields, ['storeId', 'purchaseDate', 'currency', 'totalAmount', 'notes']);
        if (
            !is_string($fields['purchaseDate']) ||
            preg_match('/^\d{4}-\d{2}-\d{2}$/', $fields['purchaseDate']) !== 1 ||
            !is_string($fields['notes'])
        ) {
            throw new Problem(422, 'Invalid receipt', 'Provide a calendar date and text notes.');
        }
        $date = $this->date($fields['purchaseDate'])->format('Y-m-d');
        $currency = (string) $fields['currency'];
        $notes = trim((string) $fields['notes']);
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1 || mb_strlen($notes) > 2000) {
            throw new Problem(422, 'Invalid receipt', 'Provide a currency and notes of at most 2000 characters.');
        }
        $total = $this->money($fields['totalAmount'] === null ? null : (string) $fields['totalAmount'], true);
        $storeId = $fields['storeId'] === null ? null : $this->identifier((string) $fields['storeId']);
        return $this->changeDraftReceipt(
            $identity,
            $homeId,
            $receiptId,
            [
                'store_id' => $storeId,
                'purchase_date' => $date,
                'currency' => $currency,
                'total_amount' => $total,
                'notes' => $notes,
            ],
            $expectedRevision,
        );
    }

    /** @return array{id: string, revision: int} */
    public function cancelReceipt(
        AuthenticatedIdentity $identity,
        string $homeId,
        string $receiptId,
        int $expectedRevision,
    ): array {
        $this->authorization->requirePermission($identity, $homeId, HomePermission::PURCHASES_WRITE);
        return $this->changeDraftReceipt($identity, $homeId, $receiptId, ['status' => 'cancelled'], $expectedRevision);
    }

    /**
     * @param array<string, mixed> $fields
     * @return array{id: string, revision: int}
     */
    public function updateLine(
        AuthenticatedIdentity $identity,
        string $homeId,
        string $receiptId,
        string $lineId,
        array $fields,
        int $expectedRevision,
    ): array {
        $this->authorization->requirePermission($identity, $homeId, HomePermission::PURCHASES_WRITE);
        $this->draftFields($fields, ['rawDescription', 'quantity', 'originalPackText', 'unitPrice', 'lineTotal']);
        $description = trim((string) $fields['rawDescription']);
        $pack = $fields['originalPackText'] === null ? null : trim((string) $fields['originalPackText']);
        $quantity = $this->quantity((string) $fields['quantity']);
        if (
            $description === '' ||
            mb_strlen($description) > 500 ||
            ($pack !== null && mb_strlen($pack) > 191) ||
            DecimalQuantity::quantity($quantity)->isZero()
        ) {
            throw new Problem(422, 'Invalid receipt line', 'Provide a description, positive quantity and pack text.');
        }
        $price = $this->money($fields['unitPrice'] === null ? null : (string) $fields['unitPrice'], true);
        $total = $this->money($fields['lineTotal'] === null ? null : (string) $fields['lineTotal'], true);
        if ($price === null && $total === null) {
            throw new Problem(422, 'Invalid receipt line', 'A unit price or line total is required.');
        }
        return $this->changeDraftLine(
            $identity,
            $homeId,
            $receiptId,
            $lineId,
            [
                'raw_description' => $description,
                'quantity' => $quantity,
                'original_pack_text' => $pack,
                'unit_price' => $price,
                'line_total' => $total,
                'approval_status' => 'unreviewed',
            ],
            $expectedRevision,
        );
    }

    /** @return array{id: string, revision: int} */
    public function removeLine(
        AuthenticatedIdentity $identity,
        string $homeId,
        string $receiptId,
        string $lineId,
        int $expectedRevision,
    ): array {
        $this->authorization->requirePermission($identity, $homeId, HomePermission::PURCHASES_WRITE);
        return $this->changeDraftLine(
            $identity,
            $homeId,
            $receiptId,
            $lineId,
            ['approval_status' => 'removed'],
            $expectedRevision,
        );
    }

    /**
     * @param array<string, mixed> $fields
     * @param list<string> $keys
     */
    private function draftFields(array $fields, array $keys): void
    {
        if (array_diff(array_keys($fields), $keys) !== [] || array_diff($keys, array_keys($fields)) !== []) {
            throw new Problem(422, 'Invalid draft change', 'Provide exactly the editable draft fields.');
        }
        foreach ($fields as $value) {
            if ($value !== null && ! is_string($value)) {
                throw new Problem(422, 'Invalid draft change', 'Draft fields must be text or nullable values.');
            }
        }
    }

    /**
     * @param array<string, mixed> $fields
     * @return array{id: string, revision: int}
     */
    private function changeDraftReceipt(
        AuthenticatedIdentity $identity,
        string $homeId,
        string $receiptId,
        array $fields,
        int $expectedRevision,
    ): array {
        try {
            return $this->transactions->transactional(function () use (
                $identity,
                $homeId,
                $receiptId,
                $fields,
                $expectedRevision,
            ): array {
                $this->requireDraft($homeId, $receiptId);
                if (
                    $expectedRevision < 1 ||
                    !$this->purchases->updateDraftReceipt(
                        $homeId,
                        $receiptId,
                        $fields,
                        $expectedRevision,
                        $this->clock->now(),
                    )
                ) {
                    throw new Problem(409, 'Revision conflict', 'Refresh this draft receipt before changing it.');
                }
                $this->publishDraftReceipt($identity, $homeId, $receiptId);
                return ['id' => $receiptId, 'revision' => $expectedRevision + 1];
            });
        } catch (DomainException $error) {
            throw new Problem(409, 'Draft receipt conflict', $error->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $fields
     * @return array{id: string, revision: int}
     */
    private function changeDraftLine(
        AuthenticatedIdentity $identity,
        string $homeId,
        string $receiptId,
        string $lineId,
        array $fields,
        int $expectedRevision,
    ): array {
        return $this->transactions->transactional(function () use (
            $identity,
            $homeId,
            $receiptId,
            $lineId,
            $fields,
            $expectedRevision,
        ): array {
            $this->requireDraft($homeId, $receiptId);
            if (
                $expectedRevision < 1 ||
                !$this->purchases->updateDraftReceiptLine(
                    $homeId,
                    $receiptId,
                    $lineId,
                    $fields,
                    $expectedRevision,
                    $this->clock->now(),
                )
            ) {
                throw new Problem(409, 'Revision conflict', 'Refresh this draft receipt line before changing it.');
            }
            $line = $this->purchases->receiptLine($homeId, $receiptId, $lineId);
            if ($line === null) {
                throw new \RuntimeException('The updated receipt line is unavailable.');
            }
            $revision = (int) $line['revision'];
            unset($line['id'], $line['revision']);
            $this->changes?->put(
                $homeId,
                $identity->userId,
                'purchasing-receipt-line',
                $lineId,
                $revision,
                $line,
                $this->clock->now(),
            );
            $this->publishDraftReceipt($identity, $homeId, $receiptId);
            return ['id' => $lineId, 'revision' => $revision];
        });
    }

    private function publishDraftReceipt(AuthenticatedIdentity $identity, string $homeId, string $receiptId): void
    {
        $receipt = $this->purchases->receipt($homeId, $receiptId);
        if ($receipt === null) {
            throw new \RuntimeException('The updated receipt is unavailable.');
        }
        $fields = array_intersect_key(
            $receipt,
            array_flip([
                'storeId',
                'purchaseDate',
                'currency',
                'totalAmount',
                'status',
                'source',
                'sourceReference',
                'notes',
            ]),
        );
        $this->changes?->put(
            $homeId,
            $identity->userId,
            'purchasing-receipt',
            $receiptId,
            (int) $receipt['revision'],
            $fields,
            $this->clock->now(),
        );
    }

    /** @return array{receiptId: string, movements: int} */
    public function commit(
        AuthenticatedIdentity $identity,
        string $homeId,
        string $receiptId,
        int $expectedRevision,
    ): array {
        $this->authorization->requirePermission($identity, $homeId, HomePermission::PURCHASES_WRITE);

        return $this->transactions->transactional(function () use (
            $identity,
            $homeId,
            $receiptId,
            $expectedRevision,
        ): array {
            $receipt = $this->purchases->receipt($homeId, $receiptId);
            if ($receipt === null) {
                throw new Problem(404, 'Not found', 'The requested resource is unavailable.');
            }
            if ((string) $receipt['status'] === 'committed') {
                return ['receiptId' => $receiptId, 'movements' => 0];
            }
            if ((string) $receipt['status'] !== 'draft' || (int) $receipt['revision'] !== $expectedRevision) {
                throw new Problem(409, 'Revision conflict', 'The receipt changed on another device.');
            }
            $this->changes?->put(
                $homeId,
                $identity->userId,
                'purchasing-receipt',
                $receiptId,
                $expectedRevision + 1,
                [
                    'storeId' => $receipt['storeId'] ?? null,
                    'purchaseDate' => (string) $receipt['purchaseDate'],
                    'currency' => (string) $receipt['currency'],
                    'totalAmount' => $receipt['totalAmount'] ?? null,
                    'status' => 'committed',
                    'source' => (string) ($receipt['source'] ?? 'manual'),
                    'sourceReference' => $receipt['sourceReference'] ?? null,
                    'notes' => (string) ($receipt['notes'] ?? ''),
                ],
                $this->clock->now(),
            );
            $lines = array_values(array_filter(
                $this->purchases->receiptLines($homeId, $receiptId),
                static fn (array $line): bool => $line['approvalStatus'] !== 'removed',
            ));
            if ($lines === []) {
                throw new Problem(422, 'Empty receipt', 'At least one receipt line is required.');
            }
            $movements = 0;
            foreach ($lines as $line) {
                $approvalStatus = (string) $line['approvalStatus'];
                if ($approvalStatus === 'unresolved' && $line['homeProductId'] === null) {
                    continue;
                }
                if ($approvalStatus !== 'approved' || $line['homeProductId'] === null) {
                    throw new Problem(
                        422,
                        'Receipt review incomplete',
                        'Every receipt line must be explicitly approved or left unresolved before commit.',
                    );
                }
                $this->inventory->recordApprovedInbound(
                    $identity->userId,
                    $homeId,
                    (string) $line['homeProductId'],
                    (string) $line['quantity'],
                    'receipt-line',
                    (string) $line['id'],
                    'Approved receipt line',
                    $this->date((string) $receipt['purchaseDate']),
                );
                $movements++;
                if ($line['lineTotal'] !== null) {
                    $this->purchases->recordPriceObservation(
                        $this->ids->generate(),
                        $homeId,
                        (string) $line['id'],
                        $line['packId'] === null ? null : (string) $line['packId'],
                        $receipt['storeId'] === null ? null : (string) $receipt['storeId'],
                        (string) $receipt['currency'],
                        (string) $line['quantity'],
                        $line['unitPrice'] === null ? null : (string) $line['unitPrice'],
                        (string) $line['lineTotal'],
                        $this->date((string) $receipt['purchaseDate']),
                        $this->clock->now(),
                    );
                }
            }
            if (
                ! $this->purchases->markReceiptCommitted(
                    $homeId,
                    $receiptId,
                    $expectedRevision,
                    $this->clock->now(),
                )
            ) {
                throw new Problem(409, 'Revision conflict', 'The receipt changed on another device.');
            }

            return ['receiptId' => $receiptId, 'movements' => $movements];
        });
    }

    /** @return array<string, mixed> */
    public function summary(AuthenticatedIdentity $identity, string $homeId, int $recentDays = 90): array
    {
        $this->authorization->requirePermission($identity, $homeId, HomePermission::PURCHASES_READ);

        return $this->purchases->summary($homeId, min(365, max(1, $recentDays)));
    }

    /** @return array<string, mixed> */
    private function requireDraft(string $homeId, string $receiptId): array
    {
        $receipt = $this->purchases->receipt($homeId, $receiptId);
        if ($receipt === null) {
            throw new Problem(404, 'Not found', 'The requested resource is unavailable.');
        }
        if ((string) $receipt['status'] !== 'draft') {
            throw new Problem(409, 'Receipt immutable', 'Only a draft receipt can be changed.');
        }

        return $receipt;
    }

    private function quantity(string $value): string
    {
        try {
            return DecimalQuantity::quantity($value)->toString();
        } catch (InvalidArgumentException $error) {
            throw new Problem(422, 'Invalid quantity', $error->getMessage());
        }
    }

    private function money(?string $value, bool $nullable): ?string
    {
        if ($value === null || trim($value) === '') {
            if ($nullable) {
                return null;
            }
            throw new Problem(422, 'Invalid amount', 'A monetary amount is required.');
        }
        $value = trim($value);
        if (preg_match('/^(?:0|[1-9]\d{0,11})(?:\.\d{1,2})?$/', $value) !== 1) {
            throw new Problem(422, 'Invalid amount', 'Monetary values require at most two decimal places.');
        }

        return $value;
    }

    private function optionalDate(?string $value): void
    {
        if ($value !== null && $value !== '') {
            $this->date($value);
        }
    }

    private function date(string $value): DateTimeImmutable
    {
        try {
            $date = new DateTimeImmutable($value . (strlen($value) === 10 ? 'T00:00:00Z' : ''));
        } catch (Throwable) {
            throw new Problem(422, 'Invalid date', 'Date must be an ISO-8601 value.');
        }
        if (strlen($value) === 10 && $date->format('Y-m-d') !== $value) {
            throw new Problem(422, 'Invalid date', 'Date must be an ISO-8601 calendar date.');
        }

        return $date;
    }

    private function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value) ?? $value;

        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
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
