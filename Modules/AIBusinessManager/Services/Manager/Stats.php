<?php

namespace Modules\AIBusinessManager\Services\Manager;

class Stats
{
    /**
     * @param  list<float|int>  $values
     */
    public static function quantile(array $values, float $q): float
    {
        if ($values === []) {
            return 0.0;
        }
        $values = array_values(array_map('floatval', $values));
        sort($values);
        $pos = ($q * (count($values) - 1));
        $lower = (int) floor($pos);
        $upper = (int) ceil($pos);
        if ($lower === $upper) {
            return $values[$lower];
        }

        return $values[$lower] + ($values[$upper] - $values[$lower]) * ($pos - $lower);
    }

    /**
     * @param  list<float|int>  $values
     */
    public static function mean(array $values): float
    {
        return $values === [] ? 0.0 : array_sum($values) / count($values);
    }

    /**
     * Weighted absolute percentage error: sum |actual - forecast| / sum actual.
     *
     * @param  list<array{0: float, 1: float}>  $pairs  [actual, forecast]
     */
    public static function wape(array $pairs): ?float
    {
        $err = 0.0;
        $act = 0.0;
        foreach ($pairs as [$actual, $forecast]) {
            $err += abs($actual - $forecast);
            $act += $actual;
        }

        return $act > 0 ? $err / $act : null;
    }

    /**
     * Forecast bias: sum (forecast - actual) / sum actual. Positive means over-forecasting.
     *
     * @param  list<array{0: float, 1: float}>  $pairs
     */
    public static function bias(array $pairs): ?float
    {
        $diff = 0.0;
        $act = 0.0;
        foreach ($pairs as [$actual, $forecast]) {
            $diff += $forecast - $actual;
            $act += $actual;
        }

        return $act > 0 ? $diff / $act : null;
    }

    public static function pct(?float $ratio, int $decimals = 1): ?float
    {
        return $ratio === null ? null : round($ratio * 100, $decimals);
    }
}
