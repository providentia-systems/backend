<?php

declare(strict_types=1);

namespace Providentia\Shopping\Domain;

use DateTimeImmutable;
use Throwable;

final class PackOptimizer
{
    /**
     * @param list<array<string, mixed>> $options
     * @return list<array<string, mixed>>
     */
    public function rank(
        string $requiredQuantity,
        string $currentPackBase,
        ?string $preferredPackId,
        array $options,
        DateTimeImmutable $asOf,
    ): array {
        $required = FixedDecimal::from($requiredQuantity);
        $currentBase = FixedDecimal::from($currentPackBase);
        if (! $currentBase->isPositive()) {
            return [];
        }
        $ranked = [];
        foreach ($options as $option) {
            try {
                $packBase = FixedDecimal::from((string) $option['packBase']);
                $receiptQuantity = FixedDecimal::from((string) $option['priceQuantity']);
                $lineTotal = FixedDecimal::from((string) $option['lineTotal']);
                if (! $packBase->isPositive() || ! $receiptQuantity->isPositive()) {
                    continue;
                }
                $candidateHomeUnits = $packBase->divide($currentBase);
                if (! $candidateHomeUnits->isPositive()) {
                    continue;
                }
                $packCount = $required->ceilRatio($candidateHomeUnits);
                $pricePerPack = $lineTotal->divide($receiptQuantity);
                $effectiveTotal = $pricePerPack->multiplyByInt($packCount);
                $suppliedHomeUnits = $candidateHomeUnits->multiplyByInt($packCount);
                $observedAt = new DateTimeImmutable((string) $option['observedAt']);
                $ageDays = max(0, intdiv($asOf->getTimestamp() - $observedAt->getTimestamp(), 86400));
                $ranked[] = [
                    'packId' => (string) $option['packId'],
                    'storeId' => $option['storeId'],
                    'currency' => (string) $option['currency'],
                    'packCount' => $packCount,
                    'effectiveTotal' => $effectiveTotal->toString(),
                    'excessQuantity' => $suppliedHomeUnits->subtract($required)->maxZero()->toString(),
                    'priceObservedAt' => $observedAt,
                    'priceAgeDays' => $ageDays,
                    'priceConfidence' => $ageDays <= 30 ? 'high' : ($ageDays <= 90 ? 'medium' : 'low'),
                    'selected' => false,
                    'reason' => $ageDays > 90 ? 'stale-price' : 'eligible',
                ];
            } catch (Throwable) {
                continue;
            }
        }
        usort($ranked, static function (array $left, array $right): int {
            $currency = ((string) $left['currency']) <=> ((string) $right['currency']);
            if ($currency !== 0) {
                return $currency;
            }

            return FixedDecimal::from((string) $left['effectiveTotal'])
                ->compare(FixedDecimal::from((string) $right['effectiveTotal']));
        });
        $currencies = array_values(array_unique(array_column($ranked, 'currency')));
        if (count($currencies) === 1 && $ranked !== []) {
            $selected = 0;
            if ($preferredPackId !== null) {
                foreach ($ranked as $index => $option) {
                    if ((string) $option['packId'] === $preferredPackId) {
                        $selected = $index;
                        break;
                    }
                }
            }
            $ranked[$selected]['selected'] = true;
            $ranked[$selected]['reason'] = (string) $ranked[$selected]['packId'] === $preferredPackId
                ? 'preferred-pack'
                : 'lowest-observed-cost';
        } elseif (count($currencies) > 1) {
            foreach ($ranked as &$option) {
                $option['reason'] = 'currency-isolated-comparison';
            }
            unset($option);
        }

        return $ranked;
    }
}
