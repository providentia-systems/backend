<?php

declare(strict_types=1);

namespace Providentia\Purchasing\Application;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use DateTimeImmutable;
use DateTimeZone;
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
    public function receiptLineCorrections(
        AuthenticatedIdentity $identity,
        string $homeId,
        string $receiptId,
    ): array {
        $this->receipt($identity, $homeId, $receiptId);
        return $this->purchases->receiptLineCorrections($homeId, $receiptId);
    }

    /**
     * Complete one omitted or unapplied line without reopening or replaying a receipt.
     *
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    public function correctFinishedLine(
        AuthenticatedIdentity $identity,
        string $homeId,
        string $receiptId,
        string $lineId,
        int $expectedReceiptRevision,
        array $fields,
    ): array {
        $this->authorization->requirePermission($identity, $homeId, HomePermission::PURCHASES_WRITE);
        $lineId = $this->identifier($lineId);
        $fields = $this->finishedCorrectionFields($fields);
        if ($expectedReceiptRevision < 1) {
            throw new Problem(422, 'Invalid revision', 'Provide a positive expectedReceiptRevision.');
        }
        // Fixed field ordering and normalized decimals bind the command identity
        // to its actor and intent; replay never reads a subsequently edited line.
        $requestHash = hash('sha256', json_encode([
            'homeId' => $homeId, 'receiptId' => $receiptId, 'lineId' => $lineId,
            'expectedReceiptRevision' => $expectedReceiptRevision,
            'actorUserId' => $identity->userId, 'fields' => $fields,
        ], JSON_THROW_ON_ERROR));
        try {
            return $this->transactions->transactional(function () use (
                $identity,
                $homeId,
                $receiptId,
                $lineId,
                $expectedReceiptRevision,
                $fields,
                $requestHash,
            ): array {
                // The store locks the aggregate header on transactional reads.
                $receipt = $this->purchases->receipt($homeId, $receiptId);
                if ($receipt === null) {
                    throw new Problem(404, 'Not found', 'The requested resource is unavailable.');
                }
                $correctionId = (string) $fields['correctionId'];
                $previous = $this->purchases->receiptLineCorrection($homeId, $receiptId, $correctionId);
                if ($previous !== null) {
                    if (!hash_equals((string) $previous['requestHash'], $requestHash)) {
                        throw new Problem(
                            409,
                            'Correction identifier conflict',
                            'This identifier belongs to another command. Keep the original operation intact.',
                        );
                    }
                    return [...$previous['nextLine'], 'correctionId' => $correctionId,
                        'receiptRevision' => (int) $previous['receiptRevision']];
                }
                if ($receipt['status'] !== 'committed') {
                    throw new Problem(
                        409,
                        'Receipt correction unavailable',
                        'Use ordinary line editing for drafts. Cancelled receipts cannot receive new purchases.',
                    );
                }
                if ((int) $receipt['revision'] !== $expectedReceiptRevision) {
                    throw new Problem(409, 'Revision conflict', 'Reload this finished receipt before correcting it.');
                }
                $original = $this->purchases->receiptLine($homeId, $receiptId, $lineId);
                $lineRevision = $fields['expectedLineRevision'];
                if (
                    ($original === null && $lineRevision !== null)
                    || ($original !== null && ($lineRevision === null
                        || (int) $original['revision'] !== $lineRevision))
                ) {
                    throw new Problem(409, 'Revision conflict', 'Reload the pending line before correcting it.');
                }
                if (
                    $original !== null && (($original['stockQuantity'] ?? null) !== null
                    || $original['approvalStatus'] === 'removed')
                ) {
                    throw new Problem(
                        409,
                        'Purchase already recorded',
                        'Use the existing purchase-line correction or removal action for an applied line.',
                    );
                }
                $this->purchaseDay($homeId, (string) $receipt['purchaseDate']);
                $homeProductId = (string) $fields['homeProductId'];
                $packId = $fields['packId'] === null ? null : (string) $fields['packId'];
                $product = $this->purchases->purchaseProduct($homeId, $homeProductId, $packId);
                if ($product === null) {
                    throw new Problem(404, 'Not found', 'The selected home product is unavailable.');
                }
                $factor = $this->purchaseFactor(
                    $product,
                    $packId,
                    (string) $fields['unit'],
                    (string) $fields['stockQuantityPerPack'],
                );
                $stockQuantity = $this->decimalProduct((string) $fields['quantity'], $factor);
                if (DecimalQuantity::quantity($stockQuantity)->isZero()) {
                    throw new Problem(422, 'Invalid pack', 'The purchase must have a positive stock measure.');
                }
                $packText = $fields['originalPackText'] ?? ($product['packText'] ?? null);
                $at = $this->clock->now();
                if (
                    !$this->purchases->completeReceiptLine(
                        $homeId,
                        $receiptId,
                        $lineId,
                        $expectedReceiptRevision,
                        $lineRevision === null ? null : (int) $lineRevision,
                        [
                        'raw_description' => $fields['rawDescription'], 'quantity' => $fields['quantity'],
                        'original_pack_text' => $packText, 'unit_price' => $fields['unitPrice'],
                        'line_total' => $fields['lineTotal'], 'home_product_id' => $homeProductId,
                        'selected_pack_id' => $packId, 'approval_status' => 'approved',
                        'stock_quantity' => $stockQuantity, 'stock_quantity_per_pack' => $factor,
                        'stock_unit' => $fields['unit'],
                        ],
                        $at,
                    )
                ) {
                    throw new Problem(409, 'Revision conflict', 'Reload the receipt and pending line before retrying.');
                }
                $this->inventory->recordApprovedInbound(
                    $identity->userId,
                    $homeId,
                    $homeProductId,
                    $stockQuantity,
                    'receipt-line',
                    $lineId,
                    (string) $fields['reason'],
                    $this->date((string) $receipt['purchaseDate']),
                );
                $line = $this->purchases->receiptLine($homeId, $receiptId, $lineId)
                    ?? throw new \RuntimeException('The completed receipt line is unavailable.');
                $this->observePrice($homeId, $receipt, $line);
                $this->purchases->recordReceiptLineCorrection(
                    $correctionId,
                    $homeId,
                    $receiptId,
                    $lineId,
                    $requestHash,
                    (string) $fields['reason'],
                    $expectedReceiptRevision + 1,
                    $original,
                    $line,
                    $identity->userId,
                    $at,
                );
                $this->publishLine($identity, $homeId, $receiptId, $line);
                $audit = $this->purchases->receiptLineCorrection($homeId, $receiptId, $correctionId)
                    ?? throw new \RuntimeException('The receipt-line correction audit is unavailable.');
                unset($audit['id'], $audit['requestHash']);
                $this->changes?->put(
                    $homeId,
                    $identity->userId,
                    'purchasing-receipt-line-correction',
                    $correctionId,
                    1,
                    $audit,
                    $at,
                );
                return [...$line, 'correctionId' => $correctionId, 'receiptRevision' => $expectedReceiptRevision + 1];
            });
        } catch (DomainException $error) {
            throw new Problem(409, 'Receipt-line correction conflict', $error->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    private function finishedCorrectionFields(array $fields): array
    {
        $keys = ['correctionId', 'expectedLineRevision', 'reason', 'homeProductId', 'packId',
            'rawDescription', 'quantity', 'originalPackText', 'unitPrice', 'lineTotal', 'unit', 'stockQuantityPerPack'];
        if (array_diff(array_keys($fields), $keys) !== [] || array_diff($keys, array_keys($fields)) !== []) {
            throw new Problem(422, 'Invalid correction', 'Provide all receipt-line correction fields only.');
        }
        foreach ($keys as $key) {
            if ($key === 'expectedLineRevision') {
                if ($fields[$key] !== null && (!is_int($fields[$key]) || $fields[$key] < 1)) {
                    throw new Problem(422, 'Invalid revision', 'expectedLineRevision must be positive or null.');
                }
                continue;
            }
            if (
                $fields[$key] === null
                && in_array($key, ['packId', 'originalPackText', 'unitPrice', 'lineTotal'], true)
            ) {
                continue;
            }
            if (!is_string($fields[$key])) {
                throw new Problem(422, 'Invalid correction', $key . ' must be text.');
            }
        }
        $fields = array_replace(array_fill_keys($keys, null), $fields);
        foreach (['correctionId', 'homeProductId', 'packId'] as $key) {
            if ($fields[$key] !== null) {
                $fields[$key] = $this->identifier((string) $fields[$key]);
            }
        }
        foreach (['reason', 'rawDescription', 'originalPackText'] as $key) {
            if ($fields[$key] !== null) {
                $fields[$key] = trim((string) $fields[$key]);
            }
        }
        if (
            $fields['reason'] === '' || mb_strlen((string) $fields['reason']) > 500
            || $fields['rawDescription'] === '' || mb_strlen((string) $fields['rawDescription']) > 500
            || ($fields['originalPackText'] !== null && mb_strlen((string) $fields['originalPackText']) > 191)
        ) {
            throw new Problem(422, 'Invalid correction', 'Provide a reason, description and valid pack text.');
        }
        if (!in_array($fields['unit'], ['units', 'g', 'kg', 'ml', 'l'], true)) {
            throw new Problem(422, 'Invalid unit', 'Choose units, g, kg, ml or l.');
        }
        $fields['quantity'] = $this->quantity((string) $fields['quantity']);
        $fields['stockQuantityPerPack'] = $this->quantity((string) $fields['stockQuantityPerPack']);
        if (DecimalQuantity::quantity((string) $fields['quantity'])->isZero()) {
            throw new Problem(422, 'Invalid quantity', 'Purchased quantity must be positive.');
        }
        foreach (['unitPrice', 'lineTotal'] as $key) {
            $fields[$key] = $this->decimalMoney($this->money(
                $fields[$key] === null ? null : (string) $fields[$key],
                true,
            ));
        }
        return $fields;
    }

    /** @return list<array<string, mixed>> */
    public function receiptDateCorrections(
        AuthenticatedIdentity $identity,
        string $homeId,
        string $receiptId,
    ): array {
        $this->receipt($identity, $homeId, $receiptId);
        return $this->purchases->receiptDateCorrections($homeId, $receiptId);
    }

    /** @return array{id: string, revision: int, correctionId: string} */
    public function correctReceiptDate(
        AuthenticatedIdentity $identity,
        string $homeId,
        string $receiptId,
        string $purchaseDate,
        string $reason,
        int $expectedRevision,
    ): array {
        $this->authorization->requirePermission($identity, $homeId, HomePermission::PURCHASES_WRITE);
        $this->purchaseDay($homeId, $purchaseDate);
        $reason = trim($reason);
        if ($expectedRevision < 1 || $reason === '' || mb_strlen($reason) > 500) {
            throw new Problem(
                422,
                'Invalid date correction',
                'Provide a positive revision and a reason of 1–500 characters.',
            );
        }
        try {
            return $this->transactions->transactional(function () use (
                $identity,
                $homeId,
                $receiptId,
                $purchaseDate,
                $reason,
                $expectedRevision,
            ): array {
                $receipt = $this->purchases->receipt($homeId, $receiptId);
                if ($receipt === null) {
                    throw new Problem(404, 'Not found', 'The requested resource is unavailable.');
                }
                if ((int) $receipt['revision'] !== $expectedRevision) {
                    throw new Problem(
                        409,
                        'Revision conflict',
                        'Reload and review this receipt before correcting its date.',
                    );
                }
                if (!in_array($receipt['status'], ['draft', 'committed'], true)) {
                    throw new Problem(409, 'Receipt immutable', 'Only an active receipt can have its date corrected.');
                }
                if ($receipt['purchaseDate'] === $purchaseDate) {
                    throw new Problem(422, 'Unchanged purchase date', 'Choose a different purchase day.');
                }
                $correctionId = $this->ids->generate();
                $at = $this->clock->now();
                if (
                    !$this->purchases->correctReceiptDate(
                        $correctionId,
                        $homeId,
                        $receiptId,
                        (string) $receipt['purchaseDate'],
                        $purchaseDate,
                        $reason,
                        $expectedRevision,
                        $identity->userId,
                        $at,
                    )
                ) {
                    throw new Problem(
                        409,
                        'Revision conflict',
                        'Reload and review this receipt before correcting its date.',
                    );
                }
                $updated = [...$receipt, 'purchaseDate' => $purchaseDate];
                foreach ($this->purchases->receiptLines($homeId, $receiptId) as $line) {
                    if (($line['stockQuantity'] ?? null) === null || $line['approvalStatus'] === 'removed') {
                        continue;
                    }
                    $this->inventory->correctPurchaseDate(
                        $identity->userId,
                        $homeId,
                        (string) $line['id'],
                        $this->date($purchaseDate),
                        $this->ids->generate(),
                        $reason,
                    );
                    $this->observePrice($homeId, $updated, $line);
                }
                $this->publishDraftReceipt($identity, $homeId, $receiptId);
                $this->changes?->put(
                    $homeId,
                    $identity->userId,
                    'purchasing-receipt-date-correction',
                    $correctionId,
                    1,
                    [
                    'receiptId' => $receiptId,
                    'originalPurchaseDate' => (string) $receipt['purchaseDate'],
                    'purchaseDate' => $purchaseDate,
                    'reason' => $reason,
                    'receiptRevision' => $expectedRevision + 1,
                    'actorUserId' => $identity->userId,
                    'correctedAt' => $at->format(DATE_ATOM),
                    ],
                    $at,
                );
                return ['id' => $receiptId, 'revision' => $expectedRevision + 1, 'correctionId' => $correctionId];
            });
        } catch (DomainException $error) {
            throw new Problem(409, 'Receipt date correction conflict', $error->getMessage());
        }
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

    /** @return array<string, mixed> */
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
        $this->purchaseDay($homeId, $purchaseDate);
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
            return $this->transactions->transactional(function () use (
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
            ): array {
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
                        'lineCorrectionState' => 'draft',
                        'source' => 'manual',
                        'sourceReference' => $sourceReference,
                        'notes' => $notes,
                    ],
                    $at,
                );

                return $this->persistedReceipt($homeId, $id);
            });
        } catch (DomainException $error) {
            throw new Problem(422, 'Invalid receipt', $error->getMessage());
        }
    }

    /** @return array<string, mixed> */
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
        return $this->transactions->transactional(function () use (
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
        ): array {
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
                    'lineCorrectionState' => $receipt['lineCorrectionState'] ?? 'draft',
                    'source' => (string) ($receipt['source'] ?? 'manual'),
                    'sourceReference' => $receipt['sourceReference'] ?? null,
                    'notes' => (string) ($receipt['notes'] ?? ''),
                ],
                $this->clock->now(),
            );
            $line = $this->purchases->receiptLine($homeId, $receiptId, $id);
            if ($line === null) {
                throw new \RuntimeException('The created receipt line is unavailable.');
            }

            return $line;
        });
    }

    /** @return array<string, mixed> */
    public function purchaseLine(
        AuthenticatedIdentity $identity,
        string $homeId,
        string $receiptId,
        int $expectedReceiptRevision,
        string $homeProductId,
        ?string $packId,
        string $rawDescription,
        string $quantity,
        ?string $originalPackText,
        ?string $unitPrice,
        ?string $lineTotal,
        string $expectedUnit,
        string $expectedStockQuantityPerPack,
        ?string $requestedId = null,
    ): array {
        $this->authorization->requirePermission($identity, $homeId, HomePermission::PURCHASES_WRITE);
        $homeProductId = $this->identifier($homeProductId);
        $packId = $packId === null ? null : $this->identifier($packId);
        if (!in_array($expectedUnit, ['units', 'g', 'kg', 'ml', 'l'], true)) {
            throw new Problem(422, 'Invalid unit', 'Choose units, g, kg, ml or l.');
        }
        $expectedStockQuantityPerPack = $this->quantity($expectedStockQuantityPerPack);
        $id = $this->identifier($requestedId);
        $quantity = $this->quantity($quantity);
        if (DecimalQuantity::quantity($quantity)->isZero()) {
            throw new Problem(422, 'Invalid quantity', 'Purchased quantity must be positive.');
        }
        $rawDescription = trim($rawDescription);
        if ($rawDescription === '' || mb_strlen($rawDescription) > 500) {
            throw new Problem(422, 'Invalid receipt line', 'Description must contain 1 to 500 characters.');
        }
        $unitPrice = $this->money($unitPrice, true);
        $lineTotal = $this->money($lineTotal, true);
        if ($unitPrice === null && $lineTotal === null) {
            throw new Problem(422, 'Invalid receipt line', 'A unit price or line total is required.');
        }
        if ($lineTotal === null) {
            $lineTotal = $this->money((string) BigDecimal::of($quantity)
                ->multipliedBy((string) $unitPrice)->toScale(2, RoundingMode::HALF_UP), false);
        }
        $originalPackText = $originalPackText === null ? null : trim($originalPackText);
        if ($originalPackText !== null && mb_strlen($originalPackText) > 191) {
            throw new Problem(422, 'Invalid receipt line', 'Pack text exceeds 191 characters.');
        }
        try {
            return $this->transactions->transactional(function () use (
                $identity,
                $homeId,
                $receiptId,
                $expectedReceiptRevision,
                $homeProductId,
                $packId,
                $rawDescription,
                $quantity,
                $originalPackText,
                $unitPrice,
                $lineTotal,
                $expectedUnit,
                $expectedStockQuantityPerPack,
                $id,
            ): array {
                $existing = $this->purchases->receiptLine($homeId, $receiptId, $id);
                if ($existing !== null) {
                    if (
                        $existing['homeProductId'] !== $homeProductId || $existing['packId'] !== $packId
                        || $existing['quantity'] !== $quantity || $existing['rawDescription'] !== $rawDescription
                        || ($existing['stockQuantity'] ?? null) === null
                        || $this->decimalMoney($existing['unitPrice']) !== $this->decimalMoney($unitPrice)
                        || $this->decimalMoney($existing['lineTotal']) !== $this->decimalMoney($lineTotal)
                        || $existing['stockQuantityPerPack'] !== $expectedStockQuantityPerPack
                        || $existing['stockUnit'] !== $expectedUnit
                        || ($originalPackText !== null && $existing['originalPackText'] !== $originalPackText)
                    ) {
                        throw new Problem(
                            409,
                            'Purchase identifier conflict',
                            'This identifier belongs to another purchase.',
                        );
                    }
                    $receipt = $this->purchases->receipt($homeId, $receiptId);
                    return [...$existing, 'receiptRevision' => (int) ($receipt['revision'] ?? 0)];
                }
                $receipt = $this->requireDraft($homeId, $receiptId);
                $this->purchaseDay($homeId, (string) $receipt['purchaseDate']);
                $product = $this->purchases->purchaseProduct($homeId, $homeProductId, $packId);
                if ($product === null) {
                    throw new Problem(404, 'Not found', 'The selected home product is unavailable.');
                }
                $factor = $this->purchaseFactor($product, $packId, $expectedUnit, $expectedStockQuantityPerPack);
                $stockQuantity = $this->decimalProduct($quantity, $factor);
                if (DecimalQuantity::quantity($stockQuantity)->isZero()) {
                    throw new Problem(422, 'Invalid pack', 'The pack must have a positive stock measure.');
                }
                $packText = $originalPackText ?? ($product['packText'] ?? null);
                $at = $this->clock->now();
                if (
                    !$this->purchases->addReceiptLine(
                        $id,
                        $homeId,
                        $receiptId,
                        $expectedReceiptRevision,
                        count($this->purchases->receiptLines($homeId, $receiptId)) + 1,
                        $rawDescription,
                        $quantity,
                        $packText === null ? null : (string) $packText,
                        $unitPrice,
                        $lineTotal,
                        $at,
                        [
                        'home_product_id' => $homeProductId,
                        'selected_pack_id' => $packId,
                        'approval_status' => 'approved',
                        'stock_quantity' => $stockQuantity,
                        'stock_quantity_per_pack' => $factor,
                        'stock_unit' => $expectedUnit,
                        ],
                    )
                ) {
                    throw new Problem(409, 'Revision conflict', 'Refresh this receipt before adding the purchase.');
                }
                $this->inventory->recordApprovedInbound(
                    $identity->userId,
                    $homeId,
                    $homeProductId,
                    $stockQuantity,
                    'receipt-line',
                    $id,
                    'Manually confirmed purchase',
                    $this->date((string) $receipt['purchaseDate']),
                );
                $line = $this->purchases->receiptLine($homeId, $receiptId, $id);
                if ($line === null) {
                    throw new \RuntimeException('The purchased line is unavailable.');
                }
                $this->observePrice($homeId, $receipt, $line);
                $this->publishLine($identity, $homeId, $receiptId, $line);
                return [...$line, 'receiptRevision' => $expectedReceiptRevision + 1];
            });
        } catch (DomainException $error) {
            throw new Problem(422, 'Invalid purchase', $error->getMessage());
        }
    }

    /** @param array<string, mixed> $product */
    private function purchaseFactor(
        array $product,
        ?string $packId,
        string $expectedUnit,
        string $expectedFactor,
    ): string {
        if ($product['unit'] !== $expectedUnit) {
            throw new Problem(409, 'Stock unit changed', 'Refresh the product before adding this purchase.');
        }
        $factor = '1';
        if ($packId !== null && $product['unit'] === 'units' && $product['homePackId'] !== $packId) {
            $baseAmount = $product['homeBaseAmount'];
            $dimension = $product['homeDimension'];
            if ($product['homePackId'] === null && $product['dimension'] === 'count') {
                $baseAmount = '1';
                $dimension = 'count';
            }
            if (
                $baseAmount === null || $dimension !== $product['dimension']
                || $product['normalizedBaseAmount'] === null
            ) {
                throw new Problem(422, 'Incompatible pack', 'Select a stock measurement or a compatible home pack.');
            }
            $factor = $this->decimalRatio((string) $product['normalizedBaseAmount'], (string) $baseAmount);
        } elseif ($packId !== null && $product['unit'] !== 'units') {
            $dimension = in_array($product['unit'], ['g', 'kg'], true) ? 'mass' : 'volume';
            if ($product['dimension'] !== $dimension || $product['normalizedBaseAmount'] === null) {
                throw new Problem(422, 'Incompatible pack', 'This pack cannot be converted to the stock unit.');
            }
            $factor = $this->decimalProduct(
                (string) $product['normalizedBaseAmount'],
                in_array($product['unit'], ['kg', 'l'], true) ? '0.001' : '1',
            );
        }
        if ($factor !== $expectedFactor) {
            throw new Problem(409, 'Pack measure changed', 'Refresh the pack size before adding this purchase.');
        }
        return $factor;
    }

    private function decimalMoney(?string $value): ?string
    {
        return $value === null ? null : (string) BigDecimal::of($value)->strippedOfTrailingZeros();
    }

    private function decimalRatio(string $quantity, string $base): string
    {
        try {
            return $this->quantity((string) BigDecimal::of($quantity)->dividedBy($base, 8, RoundingMode::UNNECESSARY));
        } catch (MathException) {
            throw new Problem(
                422,
                'Invalid quantity',
                'Pack conversion must be positive and exact within eight decimal places.',
            );
        }
    }

    private function decimalProduct(string $quantity, string $factor): string
    {
        try {
            $product = BigDecimal::of($quantity)->multipliedBy($factor)->toScale(8, RoundingMode::UNNECESSARY);
            return $this->quantity((string) $product);
        } catch (MathException $error) {
            throw new Problem(422, 'Invalid quantity', 'Pack conversion must be exact within eight decimal places.');
        }
    }

    /** @param array<string, mixed> $line */
    private function publishLine(
        AuthenticatedIdentity $identity,
        string $homeId,
        string $receiptId,
        array $line,
        bool $publishReceipt = true,
    ): void {
        $id = (string) $line['id'];
        $revision = (int) $line['revision'];
        unset($line['id'], $line['revision']);
        $this->changes?->put(
            $homeId,
            $identity->userId,
            'purchasing-receipt-line',
            $id,
            $revision,
            $line,
            $this->clock->now(),
        );
        if ($publishReceipt) {
            $this->publishDraftReceipt($identity, $homeId, $receiptId);
        }
    }

    /**
     * @param array<string, mixed> $receipt
     * @param array<string, mixed> $line
     */
    private function observePrice(string $homeId, array $receipt, array $line): void
    {
        $lineTotal = $line['lineTotal'];
        if ($lineTotal === null && $line['unitPrice'] !== null) {
            try {
                $lineTotal = (string) BigDecimal::of((string) $line['quantity'])
                    ->multipliedBy((string) $line['unitPrice'])->toScale(2, RoundingMode::HALF_UP);
                $this->money($lineTotal, false);
            } catch (MathException) {
                throw new Problem(422, 'Invalid amount', 'The calculated line total exceeds the supported range.');
            }
        }
        if ($lineTotal !== null) {
            $this->purchases->recordPriceObservation(
                $this->ids->generate(),
                $homeId,
                (string) $line['id'],
                $line['packId'] === null ? null : (string) $line['packId'],
                $receipt['storeId'] === null ? null : (string) $receipt['storeId'],
                (string) $receipt['currency'],
                (string) $line['quantity'],
                $line['unitPrice'] === null ? null : (string) $line['unitPrice'],
                (string) $lineTotal,
                $this->date((string) $receipt['purchaseDate']),
                $this->clock->now(),
            );
        }
    }

    public function approveLine(
        AuthenticatedIdentity $identity,
        string $homeId,
        string $receiptId,
        string $lineId,
        string $homeProductId,
        int $expectedRevision,
        ?string $packId = null,
        ?string $expectedUnit = null,
        ?string $expectedFactor = null,
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
            $packId,
            $expectedUnit,
            $expectedFactor,
        ): void {
            $pack = [];
            if ($expectedUnit !== null || $expectedFactor !== null || $packId !== null) {
                if ($expectedUnit === null || $expectedFactor === null) {
                    throw new Problem(
                        422,
                        'Invalid pack review',
                        'Pack review requires a stock unit and exact factor.',
                    );
                }
                $homeProductId = $this->identifier($homeProductId);
                $packId = $packId === null ? null : $this->identifier($packId);
                try {
                    $product = $this->purchases->purchaseProduct($homeId, $homeProductId, $packId);
                } catch (DomainException $error) {
                    throw new Problem(422, 'Invalid pack review', $error->getMessage());
                }
                if ($product === null) {
                    throw new Problem(404, 'Not found', 'The selected home product is unavailable.');
                }
                $factor = $this->purchaseFactor($product, $packId, $expectedUnit, $this->quantity($expectedFactor));
                $pack = [
                    'selected_pack_id' => $packId,
                    'stock_quantity_per_pack' => $factor,
                    'stock_unit' => $expectedUnit,
                ];
            }
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
                    $pack,
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
                    'lineCorrectionState' => $receipt['lineCorrectionState'] ?? 'draft',
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
        if (($line['stockQuantity'] ?? null) !== null) {
            throw new Problem(409, 'Purchase already recorded', 'Correct or remove this purchase instead.');
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
                    'lineCorrectionState' => $updatedReceipt['lineCorrectionState'] ?? 'draft',
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
        $date = $this->purchaseDay($homeId, $fields['purchaseDate'])->format('Y-m-d');
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
                $receipt = $this->purchases->receipt($homeId, $receiptId);
                $lines = $this->purchases->receiptLines($homeId, $receiptId);
                $applied = array_filter(
                    $lines,
                    static fn (array $line): bool => ($line['stockQuantity'] ?? null) !== null,
                );
                if ($receipt !== null && ($applied !== [] || $receipt['status'] === 'committed')) {
                    if (($fields['status'] ?? '') === 'cancelled') {
                        if (
                            (int) $receipt['revision'] !== $expectedRevision
                            || !in_array($receipt['status'], ['draft', 'committed'], true)
                        ) {
                            throw new Problem(409, 'Revision conflict', 'Refresh this receipt before cancelling it.');
                        }
                        foreach ($applied as $line) {
                            if ($line['approvalStatus'] === 'removed') {
                                continue;
                            }
                            $this->changeDraftLine(
                                $identity,
                                $homeId,
                                $receiptId,
                                (string) $line['id'],
                                ['approval_status' => 'removed'],
                                (int) $line['revision'],
                                false,
                            );
                        }
                        if (
                            !$this->purchases->updateDraftReceipt(
                                $homeId,
                                $receiptId,
                                ['status' => 'cancelled'],
                                $expectedRevision,
                                $this->clock->now(),
                            )
                        ) {
                            throw new Problem(409, 'Revision conflict', 'Refresh this receipt before cancelling it.');
                        }
                        $this->publishDraftReceipt($identity, $homeId, $receiptId);
                        return ['id' => $receiptId, 'revision' => $expectedRevision + 1];
                    }
                    if (
                        $fields['purchase_date'] !== $receipt['purchaseDate']
                        || $fields['currency'] !== $receipt['currency']
                    ) {
                        throw new Problem(
                            409,
                            'Purchase already recorded',
                            'Use an audited date correction to change the purchase day. Currency remains fixed.',
                        );
                    }
                    if (
                        $expectedRevision < 1 || !$this->purchases->updateReceiptMetadata(
                            $homeId,
                            $receiptId,
                            $fields,
                            $expectedRevision,
                            $this->clock->now(),
                        )
                    ) {
                        throw new Problem(409, 'Revision conflict', 'Refresh this receipt before changing its store.');
                    }
                    if ($fields['store_id'] !== $receipt['storeId']) {
                        $updatedReceipt = $this->purchases->receipt($homeId, $receiptId);
                        if ($updatedReceipt === null) {
                            throw new \RuntimeException('The corrected receipt is unavailable.');
                        }
                        foreach ($applied as $line) {
                            if ($line['approvalStatus'] !== 'removed') {
                                $this->observePrice($homeId, $updatedReceipt, $line);
                            }
                        }
                    }
                    $this->publishDraftReceipt($identity, $homeId, $receiptId);
                    return ['id' => $receiptId, 'revision' => $expectedRevision + 1];
                }
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
        bool $bumpReceipt = true,
    ): array {
        return $this->transactions->transactional(function () use (
            $identity,
            $homeId,
            $receiptId,
            $lineId,
            $fields,
            $expectedRevision,
            $bumpReceipt,
        ): array {
            $receipt = $this->purchases->receipt($homeId, $receiptId);
            $line = $this->purchases->receiptLine($homeId, $receiptId, $lineId);
            if (($line['stockQuantity'] ?? null) !== null) {
                if ((int) $line['revision'] !== $expectedRevision) {
                    throw new Problem(409, 'Revision conflict', 'Refresh this purchase before correcting it.');
                }
                if ($receipt === null || !in_array($receipt['status'], ['draft', 'committed'], true)) {
                    throw new Problem(409, 'Receipt immutable', 'This receipt cannot be corrected.');
                }
                $removing = ($fields['approval_status'] ?? '') === 'removed';
                if (!$removing && ($line['stockUnit'] ?? null) !== null) {
                    $product = $this->purchases->purchaseProduct($homeId, (string) $line['homeProductId'], null);
                    if ($product === null || $product['unit'] !== $line['stockUnit']) {
                        throw new Problem(
                            409,
                            'Stock unit changed',
                            'Restore the original stock unit before correcting this purchase.',
                        );
                    }
                }
                $stockQuantity = $removing ? '0' : $this->decimalProduct(
                    (string) $fields['quantity'],
                    (string) $line['stockQuantityPerPack'],
                );
                $delta = DecimalQuantity::quantity($stockQuantity)
                    ->subtract(DecimalQuantity::quantity((string) $line['stockQuantity']))->toString();
                $fields['approval_status'] = $removing ? 'removed' : 'approved';
                $fields['stock_quantity'] = $stockQuantity;
                if (
                    $expectedRevision < 1 || !$this->purchases->applyReceiptLine(
                        $homeId,
                        $receiptId,
                        $lineId,
                        $fields,
                        $expectedRevision,
                        $this->clock->now(),
                        $bumpReceipt,
                    )
                ) {
                    throw new Problem(409, 'Revision conflict', 'Refresh this purchased line before correcting it.');
                }
                if ($delta !== '0') {
                    $this->inventory->recordPurchaseCorrection(
                        $identity->userId,
                        $homeId,
                        (string) $line['homeProductId'],
                        $delta,
                        $lineId . ':' . ($expectedRevision + 1),
                        $removing ? 'Removed purchase line' : 'Corrected purchase line',
                        $this->date((string) $receipt['purchaseDate']),
                    );
                }
                $updated = $this->purchases->receiptLine($homeId, $receiptId, $lineId);
                if ($updated === null) {
                    throw new \RuntimeException('The corrected purchase line is unavailable.');
                }
                if (!$removing) {
                    $this->observePrice($homeId, $receipt, $updated);
                }
                $this->publishLine($identity, $homeId, $receiptId, $updated, $bumpReceipt);
                return ['id' => $lineId, 'revision' => $expectedRevision + 1];
            }
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
                'lineCorrectionState',
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

    /** @return array<string, mixed> */
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
                $receipt['lines'] = $this->purchases->receiptLines($homeId, $receiptId);

                return [...$receipt, 'receiptId' => $receiptId, 'movements' => 0];
            }
            if ((string) $receipt['status'] !== 'draft' || (int) $receipt['revision'] !== $expectedRevision) {
                throw new Problem(409, 'Revision conflict', 'The receipt changed on another device.');
            }
            $this->purchaseDay($homeId, (string) $receipt['purchaseDate']);
            $lines = array_values(array_filter(
                $this->purchases->receiptLines($homeId, $receiptId),
                static fn (array $line): bool => $line['approvalStatus'] !== 'removed',
            ));
            if ($lines === []) {
                throw new Problem(422, 'Empty receipt', 'At least one receipt line is required.');
            }
            $movements = 0;
            foreach ($lines as $line) {
                if (($line['stockQuantity'] ?? null) !== null) {
                    continue;
                }
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
                if (($line['stockUnit'] ?? null) !== null) {
                    $product = $this->purchases->purchaseProduct($homeId, (string) $line['homeProductId'], null);
                    if ($product === null || $product['unit'] !== $line['stockUnit']) {
                        throw new Problem(
                            409,
                            'Stock unit changed',
                            'Review this receipt line again before finishing.',
                        );
                    }
                }
                $factor = (string) ($line['stockQuantityPerPack'] ?? '1');
                $stockQuantity = $this->decimalProduct((string) $line['quantity'], $factor);
                $this->inventory->recordApprovedInbound(
                    $identity->userId,
                    $homeId,
                    (string) $line['homeProductId'],
                    $stockQuantity,
                    'receipt-line',
                    (string) $line['id'],
                    'Approved receipt line',
                    $this->date((string) $receipt['purchaseDate']),
                );
                $movements++;
                if (array_key_exists('stockQuantity', $line)) {
                    if (
                        !$this->purchases->applyReceiptLine(
                            $homeId,
                            $receiptId,
                            (string) $line['id'],
                            [
                            'stock_quantity' => $stockQuantity,
                            'stock_quantity_per_pack' => $factor,
                            'selected_pack_id' => $line['packId'] ?? null,
                            ],
                            (int) $line['revision'],
                            $this->clock->now(),
                            false,
                        )
                    ) {
                        throw new Problem(409, 'Revision conflict', 'The receipt line changed during commit.');
                    }
                    $line = $this->purchases->receiptLine($homeId, $receiptId, (string) $line['id'])
                        ?? throw new \RuntimeException('The committed receipt line is unavailable.');
                    $this->changes?->put(
                        $homeId,
                        $identity->userId,
                        'purchasing-receipt-line',
                        (string) $line['id'],
                        (int) $line['revision'],
                        array_diff_key($line, ['id' => true, 'revision' => true]),
                        $this->clock->now(),
                    );
                }
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

            $receipt = $this->persistedReceipt($homeId, $receiptId);
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
                    'status' => 'committed',
                    'lineCorrectionState' => 'available',
                    'source' => (string) ($receipt['source'] ?? 'manual'),
                    'sourceReference' => $receipt['sourceReference'] ?? null,
                    'notes' => (string) ($receipt['notes'] ?? ''),
                ],
                $this->clock->now(),
            );

            return [...$receipt, 'receiptId' => $receiptId, 'movements' => $movements];
        });
    }

    /** @return array<string, mixed> */
    public function summary(AuthenticatedIdentity $identity, string $homeId, int $recentDays = 90): array
    {
        $this->authorization->requirePermission($identity, $homeId, HomePermission::PURCHASES_READ);

        return $this->purchases->summary($homeId, min(365, max(1, $recentDays)));
    }

    /** @return array<string, mixed> */
    private function persistedReceipt(string $homeId, string $receiptId): array
    {
        $receipt = $this->purchases->receipt($homeId, $receiptId);
        if ($receipt === null) {
            throw new \RuntimeException('The persisted receipt is unavailable.');
        }
        $receipt['lines'] = $this->purchases->receiptLines($homeId, $receiptId);

        return $receipt;
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
        // A purchase day is a calendar value, never an instant in the device timezone.
        if (
            preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $parts) !== 1
            || !checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])
        ) {
            throw new Problem(422, 'Invalid date', 'Purchase day must be a valid YYYY-MM-DD calendar date.');
        }
        return new DateTimeImmutable($value . 'T00:00:00Z');
    }

    private function purchaseDay(string $homeId, string $value): DateTimeImmutable
    {
        $day = $this->date($value);
        $timezone = new DateTimeZone($this->purchases->homeTimezone($homeId) ?? 'UTC');
        if ($value > $this->clock->now()->setTimezone($timezone)->format('Y-m-d')) {
            throw new Problem(
                422,
                'Future purchase date',
                'The purchase day cannot be after today in the home timezone.',
            );
        }
        return $day;
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
