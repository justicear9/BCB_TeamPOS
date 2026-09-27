<?php

namespace Modules\AIBusinessManager\Services\Concerns;

use Illuminate\Support\Facades\DB;

trait AggregatesTransactions
{
    /**
     * Sum(final_total) + count rows by granularity on a transactions query builder.
     *
     * @param  \Illuminate\Database\Query\Builder  $base
     * @return array<string, mixed>
     */
    protected function aggregateFinalTotalsByGranularity(
        $base,
        string $granularity,
        int $precision,
        string $symbol,
        string $code,
        string $basis,
        string $amountKey = 'total',
        string $countKey = 'transactions'
    ): array {
        if ($granularity === 'total') {
            $row = (clone $base)
                ->selectRaw('SUM(final_total) as amt')
                ->selectRaw('COUNT(*) as cnt')
                ->first();

            return [
                'ok' => true,
                'granularity' => 'total',
                'basis' => $basis,
                'currency_code' => $code,
                'currency_symbol' => $symbol,
                'rows' => [[
                    $amountKey => round((float) ($row->amt ?? 0), $precision),
                    $countKey => (int) ($row->cnt ?? 0),
                ]],
            ];
        }

        if ($granularity === 'month') {
            $rows = (clone $base)
                ->selectRaw("DATE_FORMAT(transaction_date, '%Y-%m') as period")
                ->selectRaw('SUM(final_total) as amt')
                ->selectRaw('COUNT(*) as cnt')
                ->groupBy(DB::raw("DATE_FORMAT(transaction_date, '%Y-%m')"))
                ->orderBy('period')
                ->limit(60)
                ->get();

            return [
                'ok' => true,
                'granularity' => 'month',
                'basis' => $basis,
                'currency_code' => $code,
                'currency_symbol' => $symbol,
                'rows' => $rows->map(fn ($r) => [
                    'period' => (string) $r->period,
                    $amountKey => round((float) $r->amt, $precision),
                    $countKey => (int) $r->cnt,
                ])->values()->all(),
            ];
        }

        $rows = (clone $base)
            ->selectRaw('YEAR(transaction_date) as period')
            ->selectRaw('SUM(final_total) as amt')
            ->selectRaw('COUNT(*) as cnt')
            ->groupBy(DB::raw('YEAR(transaction_date)'))
            ->orderBy('period')
            ->limit(40)
            ->get();

        return [
            'ok' => true,
            'granularity' => 'year',
            'basis' => $basis,
            'currency_code' => $code,
            'currency_symbol' => $symbol,
            'rows' => $rows->map(fn ($r) => [
                'period' => (string) $r->period,
                $amountKey => round((float) $r->amt, $precision),
                $countKey => (int) $r->cnt,
            ])->values()->all(),
        ];
    }
}
