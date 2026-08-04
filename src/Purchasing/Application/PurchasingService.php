<?php

declare(strict_types=1);

namespace Providentia\Purchasing\Application;

use DateTimeImmutable;
use DomainException;
use InvalidArgumentException;
use Providentia\Home\Application\HomeAuthorization;
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
        private readonly HomeAuthorization $authorization,
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
            return ['id' => (string) $existing['id']];
        }
        $id = $this->identifier($requestedId);
        $at = $this->clock->now();
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
        });
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
            $lines = $this->purchases->receiptLines($homeId, $receiptId);
            if ($lines === []) {
                throw new Problem(422, 'Empty receipt', 'At least one receipt line is required.');
            }
            $movements = 0;
            foreach ($lines as $line) {
                if ((string) $line['approvalStatus'] !== 'approved' || $line['homeProductId'] === null) {
                    throw new Problem(
                        422,
                        'Receipt review incomplete',
                        'Every receipt line must be explicitly matched and approved before commit.',
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
