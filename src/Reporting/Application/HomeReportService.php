<?php

declare(strict_types=1);

namespace Providentia\Reporting\Application;

use DateTimeImmutable;
use DateTimeZone;
use Providentia\Home\Application\HomeAuthorization;
use Providentia\Home\Application\HomeAuditRecorder;
use Providentia\Home\Application\HomePermission;
use Providentia\Identity\Application\AuthenticatedIdentity;
use Providentia\Inventory\Application\InventoryAnalyticsReader;
use Providentia\Purchasing\Application\PurchaseAnalyticsReader;
use Providentia\SharedKernel\Application\Clock;
use Providentia\SharedKernel\Application\Problem;
use Providentia\SharedKernel\Application\UuidGenerator;
use Providentia\Shopping\Application\ShoppingIntelligenceReader;
use Providentia\Shopping\Domain\FixedDecimal;

final class HomeReportService
{
    public function __construct(
        private readonly HomeAuthorization $authorization,
        private readonly InventoryAnalyticsReader $inventory,
        private readonly PurchaseAnalyticsReader $purchases,
        private readonly ShoppingIntelligenceReader $intelligence,
        private readonly HomeAuditRecorder $audit,
        private readonly UuidGenerator $ids,
        private readonly Clock $clock,
    ) {
    }

    /** @return array<string, mixed> */
    public function report(
        AuthenticatedIdentity $identity,
        string $homeId,
        string $type,
        ?string $from,
        ?string $through,
    ): array {
        $this->authorization->requirePermission($identity, $homeId, HomePermission::REPORTS_READ);
        $asOf = $this->clock->now();

        $report = match ($type) {
            'inventory' => [
                'type' => 'inventory',
                'asOf' => $asOf->format(DATE_ATOM),
                'quantitySemantics' => 'factual-ledger-balance',
                'data' => $this->instantRows($this->inventory->inventoryReport($homeId), ['balanceUpdatedAt']),
            ],
            'purchases' => $this->purchaseReport($homeId, $from, $through, $asOf),
            'consumption' => [
                'type' => 'consumption',
                'asOf' => $asOf->format(DATE_ATOM),
                'quantitySemantics' => 'estimated-from-complete-reliable-count-intervals',
                'data' => $this->instantRows(
                    $this->intelligence->latestEstimates($homeId),
                    ['asOf', 'evidenceFrom', 'evidenceTo', 'nextExpectedShoppingAt'],
                ),
            ],
            'suggestions' => [
                'type' => 'suggestions',
                'asOf' => $asOf->format(DATE_ATOM),
                'quantitySemantics' => 'forecast-not-ledger-fact',
                'data' => $this->instantRows(
                    $this->intelligence->latestSuggestions($homeId, $asOf),
                    ['asOf', 'expiresAt'],
                ),
                'priceComparisons' => $this->intelligence->latestPriceComparisons($homeId),
            ],
            default => throw new Problem(422, 'Invalid report', 'The report type is not supported.'),
        };
        $this->audit->recordAudit(
            $this->ids->generate(),
            $identity->userId,
            'report.generated',
            'home_report',
            $type,
            $homeId,
            json_encode([
                'type' => $type,
                'from' => $from,
                'through' => $through,
            ], JSON_THROW_ON_ERROR),
            $asOf,
        );

        return $report;
    }

    /** @return array<string, mixed> */
    private function purchaseReport(
        string $homeId,
        ?string $from,
        ?string $through,
        DateTimeImmutable $asOf,
    ): array {
        $toDate = $through === null
            ? $asOf->setTime(0, 0)
            : $this->parseDate($through);
        $fromDate = $from === null
            ? $asOf->modify('-365 days')->setTime(0, 0)
            : $this->parseDate($from);
        if (
            ! $fromDate instanceof DateTimeImmutable
            || ! $toDate instanceof DateTimeImmutable
            || $fromDate > $toDate
            || $toDate > $asOf->modify('+1 day')
            || $fromDate < $toDate->modify('-2 years')
        ) {
            throw new Problem(422, 'Invalid report range', 'Choose a valid range of at most two years.');
        }
        /** @var array<string, array<string, mixed>> $groups */
        $groups = [];
        foreach ($this->purchases->purchaseFacts($homeId, $fromDate, $toDate) as $fact) {
            $month = substr((string) $fact['purchaseDate'], 0, 7);
            $currency = (string) $fact['currency'];
            $storeId = $fact['storeId'] === null ? '' : (string) $fact['storeId'];
            $key = $month . '|' . $currency . '|' . $storeId;
            $groups[$key] ??= [
                'month' => $month,
                'currency' => $currency,
                'storeId' => $fact['storeId'],
                'storeName' => $fact['storeName'],
                'receiptCount' => 0,
                'total' => FixedDecimal::zero(),
            ];
            $groups[$key]['receiptCount']++;
            if ($fact['totalAmount'] !== null) {
                /** @var FixedDecimal $currentTotal */
                $currentTotal = $groups[$key]['total'];
                $groups[$key]['total'] = $currentTotal->add(
                    FixedDecimal::from((string) $fact['totalAmount']),
                );
            }
        }
        $data = [];
        foreach ($groups as $group) {
            /** @var FixedDecimal $total */
            $total = $group['total'];
            $group['total'] = $total->toString();
            $data[] = $group;
        }

        return [
            'type' => 'purchases',
            'asOf' => $asOf->format(DATE_ATOM),
            'from' => $fromDate->format('Y-m-d'),
            'through' => $toDate->format('Y-m-d'),
            'currencyPolicy' => 'totals-are-never-combined-across-currencies',
            'data' => $data,
        ];
    }

    /**
     * Normalize only confirmed instant columns. Purchase/price calendar dates
     * are intentionally not passed through this compatibility boundary.
     *
     * @param list<array<string, mixed>> $rows
     * @param list<string> $fields
     * @return list<array<string, mixed>>
     */
    private function instantRows(array $rows, array $fields): array
    {
        $utc = new DateTimeZone('UTC');
        foreach ($rows as &$row) {
            foreach ($fields as $field) {
                if (! isset($row[$field])) {
                    continue;
                }
                $value = $row[$field];
                if (! is_string($value)) {
                    throw new \UnexpectedValueException('Invalid report instant.');
                }
                if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(?:\.\d{1,6})?$/D', $value) === 1) {
                    $format = str_contains($value, '.') ? '!Y-m-d H:i:s.u' : '!Y-m-d H:i:s';
                    $date = DateTimeImmutable::createFromFormat($format, $value, $utc);
                } elseif (
                    preg_match(
                        '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/D',
                        $value,
                    ) === 1
                ) {
                    $format = str_contains($value, '.') ? '!Y-m-d\TH:i:s.uP' : '!Y-m-d\TH:i:sP';
                    $date = DateTimeImmutable::createFromFormat($format, $value, $utc);
                } else {
                    throw new \UnexpectedValueException('Invalid report instant.');
                }
                $errors = DateTimeImmutable::getLastErrors();
                if (
                    $date === false
                    || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
                ) {
                    throw new \UnexpectedValueException('Invalid report instant.');
                }
                $date = $date->setTimezone($utc);
                $row[$field] = $date->format($date->format('u') === '000000' ? DATE_ATOM : 'Y-m-d\TH:i:s.uP');
            }
        }
        unset($row);

        return $rows;
    }

    private function parseDate(string $value): ?DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = DateTimeImmutable::getLastErrors();
        if (
            $date === false
            || (
                is_array($errors)
                && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)
            )
            || $date->format('Y-m-d') !== $value
        ) {
            return null;
        }

        return $date;
    }
}
