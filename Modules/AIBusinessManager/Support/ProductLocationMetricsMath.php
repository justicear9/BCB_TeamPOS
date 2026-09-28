<?php

namespace Modules\AIBusinessManager\Support;

use Carbon\Carbon;

/**
 * Pure helpers for product × location averages (no DB).
 */
final class ProductLocationMetricsMath
{
    public static function daysInRangeInclusive(Carbon $start, Carbon $end): int
    {
        return $start->copy()->startOfDay()->diffInDays($end->copy()->startOfDay()) + 1;
    }

    /**
     * @return float|null Null when divisor is zero.
     */
    public static function averageQuantity(float $total, int $daysInRange, int $sellingDays, string $avgBasis): ?float
    {
        $divisor = $avgBasis === 'selling_day' ? $sellingDays : $daysInRange;
        if ($divisor <= 0) {
            return null;
        }

        return round($total / $divisor, 4);
    }

    /**
     * Percent change from prior to current. Null when prior is zero.
     */
    public static function qtyDeltaPct(float $current, float $prior): ?float
    {
        if (abs($prior) < 0.0000001) {
            return null;
        }

        return round((($current - $prior) / $prior) * 100, 2);
    }

    /**
     * @param  list<string>  $units
     */
    public static function unitsAreCompatible(array $units): bool
    {
        $normalized = [];
        foreach ($units as $unit) {
            $u = strtolower(trim((string) $unit));
            if ($u === '') {
                $u = 'unit';
            }
            $normalized[$u] = true;
        }

        return count($normalized) <= 1;
    }

    /**
     * Prior window of the same length ending the day before $start.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public static function priorPeriod(Carbon $start, Carbon $end): array
    {
        $days = self::daysInRangeInclusive($start, $end);
        $priorEnd = $start->copy()->subDay()->endOfDay();
        $priorStart = $priorEnd->copy()->startOfDay()->subDays($days - 1)->startOfDay();

        return [$priorStart, $priorEnd];
    }
}
