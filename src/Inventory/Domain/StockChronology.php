<?php

declare(strict_types=1);

namespace Providentia\Inventory\Domain;

use Brick\Math\BigDecimal;
use DateTimeImmutable;
use DateTimeZone;

/** Projects facts without rewriting the immutable movement ledger or measured counts. */
final class StockChronology
{
    /**
     * @param list<array<string, mixed>> $counts Accepted observations in a compatible whole-home scope.
     * @param list<array<string, mixed>> $movements Net logical movements; derived count deltas are excluded.
     * @param array<string, mixed>|null $baseline
     * @return array<string, mixed>
     */
    public function project(array $counts, array $movements, ?string $unit = null, ?array $baseline = null): array
    {
        $limitations = [];
        $counts = array_values(array_filter($counts, static function (array $count) use ($unit): bool {
            return $unit === null || ($count['unit'] ?? null) === $unit;
        }));
        usort($counts, fn (array $left, array $right): int =>
            [$this->at($left['occurredAt']), (string) $left['id']]
            <=> [$this->at($right['occurredAt']), (string) $right['id']]);
        $checkpoint = $counts === [] ? null : $counts[array_key_last($counts)];
        $checkpointAt = $checkpoint === null ? null : $this->at($checkpoint['occurredAt']);
        $quantity = BigDecimal::of($checkpoint === null
            ? (string) ($baseline['quantity'] ?? '0') : (string) $checkpoint['quantity']);
        $pending = BigDecimal::of('0');
        $uncertain = $checkpoint === null && $baseline !== null;
        if ($checkpoint === null) {
            $limitations[] = $baseline === null
                ? 'No accepted complete reliable whole-home count establishes the remaining stock.'
                : 'A migrated stock projection is preserved until the household reviews '
                    . 'a complete reliable observation.';
            if (($baseline['historical'] ?? false) === true) {
                $limitations[] = 'Historical stock preserves unassessed legacy count reconciliation; '
                    . 'it is not reliable physical-count or consumption evidence.';
            }
        } else {
            foreach ($counts as $count) {
                if (
                    $this->at($count['occurredAt']) == $checkpointAt
                    && BigDecimal::of((string) $count['quantity'])->compareTo($quantity) !== 0
                ) {
                    $uncertain = true;
                    $limitations[] = 'Conflicting counts have the same observation time and require review.';
                }
            }
        }
        foreach ($movements as $movement) {
            if (($movement['movementType'] ?? '') === 'count-reconciliation') {
                continue;
            }
            $delta = BigDecimal::of((string) ($movement['quantityDelta'] ?? $movement['quantity']));
            if ($delta->isZero()) {
                continue;
            }
            $occurredAt = $this->at($movement['occurredAt']);
            $unitMismatch = $unit !== null && ($movement['unit'] ?? null) !== $unit;
            if (
                $checkpointAt === null && $baseline !== null
                && ((($movement['dateOnly'] ?? false)
                    && $occurredAt->format('Y-m-d') <= (string) $baseline['calendarDay'])
                    || (!($movement['dateOnly'] ?? false)
                        && $occurredAt <= $this->at($baseline['recorded_at'])))
            ) {
                $uncertain = true;
                if ($unitMismatch) {
                    $limitations[] = 'A pending movement has an incompatible or unknown stock unit '
                        . 'and requires review.';
                } else {
                    $pending = $pending->plus($delta);
                }
                $limitations[] = 'A late fact predates an unreviewed migrated balance '
                    . 'and awaits physical-count reconciliation.';
                continue;
            }
            if ($checkpointAt !== null && (bool) ($movement['dateOnly'] ?? false)) {
                $day = $occurredAt->format('Y-m-d');
                $countDay = (string) ($checkpoint['calendarDay'] ?? $checkpointAt->format('Y-m-d'));
                if ($day === $countDay) {
                    $uncertain = true;
                    if ($unitMismatch) {
                        $limitations[] = 'A pending movement has an incompatible or unknown stock unit '
                        . 'and requires review.';
                    } else {
                        $pending = $pending->plus($delta);
                    }
                    $limitations[] = 'A date-only purchase falls on the count day; '
                        . 'its before/after order requires review.';
                    continue;
                }
                if ($day < $countDay) {
                    continue;
                }
            } elseif ($checkpointAt !== null && $occurredAt <= $checkpointAt) {
                continue;
            }
            if ($unitMismatch) {
                $uncertain = true;
                $limitations[] = 'A movement has an incompatible or unknown stock unit and requires review.';
                continue;
            }
            $quantity = $quantity->plus($delta);
        }

        return [
            'quantity' => $quantity->strippedOfTrailingZeros()->__toString(),
            'uncertain' => $uncertain,
            'pendingQuantity' => $pending->strippedOfTrailingZeros()->__toString(),
            'limitations' => array_values(array_unique($limitations)),
            'checkpointId' => $checkpoint['id'] ?? null,
            'checkpointAt' => $checkpointAt?->format('Y-m-d\TH:i:s\Z'),
            'unit' => $unit,
        ];
    }

    private function at(mixed $value): DateTimeImmutable
    {
        $at = $value instanceof DateTimeImmutable
            ? $value : new DateTimeImmutable((string) $value, new DateTimeZone('UTC'));
        return $at->setTimezone(new DateTimeZone('UTC'));
    }
}
