<?php

namespace Modules\AIBusinessManager\Services\Concerns;

use App\User;
use Illuminate\Support\Facades\DB;

/**
 * One parameterized read of finalized sales. The model picks dimensions.
 * Business and location scope are applied here, not by the model.
 */
trait SalesReportTool
{
    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected function salesReport(array $args, int $businessId, User $user): array
    {
        $groupBy = $this->salesReportGroupBy($args['group_by'] ?? null);
        if ($groupBy === []) {
            return [
                'ok' => false,
                'error' => 'group_by_required',
                'allowed' => ['month', 'day', 'weekday', 'location', 'product', 'category'],
            ];
        }

        $range = $this->parseDateRangeOrYearToDate($args, $businessId, $user);
        if (isset($range['ok']) && $range['ok'] === false) {
            return $range;
        }

        $start = $range['start'];
        $end = $range['end'];
        $locationIds = $range['location_ids'];
        if (isset($args['location_id']) && is_numeric($args['location_id'])) {
            $lid = (int) $args['location_id'];
            $permitted = $user->permitted_locations();
            if ($permitted === 'all' || (is_array($permitted) && in_array($lid, $permitted, true))) {
                $locationIds = [$lid];
            }
        }

        $precision = $range['precision'];
        $symbol = $range['symbol'];
        $nameQuery = isset($args['name_query']) ? trim((string) $args['name_query']) : '';
        $lineGrain = $nameQuery !== '' || count(array_intersect($groupBy, ['product', 'category'])) > 0;

        $limit = isset($args['limit']) ? (int) $args['limit'] : 300;
        $limit = max(1, min(400, $limit));

        $selects = [];
        $groups = [];
        foreach ($groupBy as $dim) {
            $expr = $this->salesReportExpr($dim);
            $selects[] = $expr.' as '.$dim;
            $groups[] = $expr;
        }

        if ($lineGrain) {
            $query = $this->sellLinesInRangeQuery($businessId, $locationIds, $start, $end)
                ->join('products as p', 'p.id', '=', 'tsl.product_id')
                ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
                ->leftJoin('business_locations as bl', 'bl.id', '=', 't.location_id')
                ->leftJoin('units as base_u', 'base_u.id', '=', 'p.unit_id')
                ->when($nameQuery !== '', fn ($q) => $q->where('p.name', 'like', '%'.$nameQuery.'%'));
            $query->selectRaw('SUM(tsl.quantity * COALESCE(tsl.unit_price_inc_tax, tsl.unit_price, 0)) as revenue');
            $query->selectRaw('SUM('.$this->qtySellingUomSql().') as quantity');
            $query->selectRaw('COUNT(DISTINCT t.id) as invoices');
            $basis = 'sell_line';
        } else {
            $query = DB::table('transactions as t')
                ->join('business_locations as bl', 'bl.id', '=', 't.location_id')
                ->where('t.business_id', $businessId)
                ->where('t.type', 'sell')
                ->where('t.status', 'final')
                ->whereBetween('t.transaction_date', [$start, $end])
                ->when($locationIds !== null, fn ($q) => $q->whereIn('t.location_id', $locationIds));
            $query->selectRaw('SUM(t.final_total) as revenue');
            $query->selectRaw('COUNT(*) as invoices');
            $basis = 'invoice_final_total';
        }

        foreach ($selects as $select) {
            $query->selectRaw($select);
        }
        foreach ($groups as $group) {
            $query->groupByRaw($group);
        }

        $timeOrder = array_values(array_intersect($groupBy, ['day', 'month', 'weekday']));
        if ($timeOrder !== []) {
            $query->orderBy($timeOrder[0]);
        } else {
            $query->orderByDesc('revenue');
        }

        $fetched = $query->limit($limit + 1)->get();
        $truncated = $fetched->count() > $limit;
        $fetched = $fetched->take($limit);

        $rows = $fetched->map(function ($row) use ($groupBy, $precision, $lineGrain) {
            $out = [];
            foreach ($groupBy as $dim) {
                $out[$dim] = (string) ($row->{$dim} ?? '');
            }
            $out['revenue'] = round((float) $row->revenue, $precision);
            $out['invoices'] = (int) $row->invoices;
            if ($lineGrain) {
                $out['quantity'] = $this->roundQuantity((float) $row->quantity);
            }

            return $out;
        })->values()->all();

        $verbatim = count($groupBy) === 2
            ? $this->salesReportPivot($groupBy, $rows, $precision, $symbol)
            : $this->salesReportFlat($groupBy, $rows, $precision, $symbol, $lineGrain);

        return [
            'ok' => true,
            'basis' => $basis,
            'currency_symbol' => $symbol,
            'start' => $start->toDateString(),
            'end' => $end->toDateString(),
            'group_by' => $groupBy,
            'truncated' => $truncated,
            'note' => $basis === 'invoice_final_total'
                ? 'revenue is invoice final_total. Do not redraw the table or quote amounts; the app attaches verbatim_block.'
                : 'revenue is sell-line value, so it can differ from invoice totals when an invoice has a discount. Do not redraw the table or quote amounts; the app attaches verbatim_block.',
            'rows' => $rows,
            'verbatim_block' => $verbatim,
        ];
    }

    /**
     * @return list<string>
     */
    protected function salesReportGroupBy(mixed $raw): array
    {
        if (is_string($raw)) {
            $raw = array_map('trim', explode(',', $raw));
        }
        if (! is_array($raw)) {
            return [];
        }

        $allowed = ['month', 'day', 'weekday', 'location', 'product', 'category'];
        $groupBy = [];
        foreach ($raw as $item) {
            $item = strtolower(trim((string) $item));
            if (in_array($item, $allowed, true) && ! in_array($item, $groupBy, true)) {
                $groupBy[] = $item;
            }
        }

        return array_slice($groupBy, 0, 3);
    }

    protected function salesReportExpr(string $dim): string
    {
        return match ($dim) {
            'month' => "DATE_FORMAT(t.transaction_date, '%Y-%m')",
            'day' => 'DATE(t.transaction_date)',
            'weekday' => 'DAYNAME(t.transaction_date)',
            'location' => "COALESCE(bl.name, CONCAT('Location #', t.location_id))",
            'product' => 'p.name',
            'category' => "COALESCE(NULLIF(c.name, ''), 'Uncategorized')",
            default => "''",
        };
    }

    /**
     * @param  list<string>  $groupBy
     * @param  list<array<string, mixed>>  $rows
     */
    protected function salesReportFlat(array $groupBy, array $rows, int $precision, string $symbol, bool $lineGrain): string
    {
        $header = array_merge($groupBy, $lineGrain ? ['revenue', 'quantity', 'invoices'] : ['revenue', 'invoices']);
        $body = [];
        $total = 0.0;
        foreach ($rows as $row) {
            $cells = [];
            foreach ($groupBy as $dim) {
                $cells[] = (string) $row[$dim];
            }
            $cells[] = $this->moneyLabel((float) $row['revenue'], $precision, $symbol);
            if ($lineGrain) {
                $cells[] = (string) $row['quantity'];
            }
            $cells[] = (string) $row['invoices'];
            $body[] = $cells;
            $total += (float) $row['revenue'];
        }
        $totalCells = array_fill(0, count($groupBy), '');
        $totalCells[0] = '**Total**';
        $totalCells[] = '**'.$this->moneyLabel($total, $precision, $symbol).'**';
        if ($lineGrain) {
            $totalCells[] = '';
        }
        $totalCells[] = '';
        $body[] = $totalCells;

        return $this->markdownTable($header, $body);
    }

    /**
     * @param  list<string>  $groupBy
     * @param  list<array<string, mixed>>  $rows
     */
    protected function salesReportPivot(array $groupBy, array $rows, int $precision, string $symbol): string
    {
        $rowDim = $groupBy[0];
        $colDim = $groupBy[1];
        $columns = [];
        $grid = [];
        foreach ($rows as $row) {
            $r = (string) $row[$rowDim];
            $c = (string) $row[$colDim];
            $columns[$c] = true;
            $grid[$r][$c] = (float) $row['revenue'];
        }

        $colKeys = array_keys($columns);
        if (in_array($colDim, ['month', 'day', 'weekday'], true)) {
            if ($colDim === 'weekday') {
                $order = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
                usort($colKeys, fn ($a, $b) => (array_search($a, $order, true) ?: 0) <=> (array_search($b, $order, true) ?: 0));
            } else {
                sort($colKeys);
            }
        }

        if (count($colKeys) > 14 || count($grid) > 40) {
            return $this->salesReportFlat($groupBy, $rows, $precision, $symbol, isset($rows[0]['quantity']));
        }

        $rowTotals = [];
        foreach ($grid as $r => $cells) {
            $rowTotals[$r] = array_sum($cells);
        }
        arsort($rowTotals);

        $header = array_merge([ucfirst($rowDim)], $this->salesReportLabels($colDim, $colKeys), ['Total']);
        $body = [];
        $colTotals = array_fill_keys($colKeys, 0.0);
        foreach (array_keys($rowTotals) as $r) {
            $cells = [$r];
            foreach ($colKeys as $c) {
                $amount = (float) ($grid[$r][$c] ?? 0);
                $colTotals[$c] += $amount;
                $cells[] = $this->moneyLabel($amount, $precision, $symbol);
            }
            $cells[] = $this->moneyLabel($rowTotals[$r], $precision, $symbol);
            $body[] = $cells;
        }
        $totalCells = ['**Total**'];
        $grand = 0.0;
        foreach ($colKeys as $c) {
            $grand += $colTotals[$c];
            $totalCells[] = '**'.$this->moneyLabel($colTotals[$c], $precision, $symbol).'**';
        }
        $totalCells[] = '**'.$this->moneyLabel($grand, $precision, $symbol).'**';
        $body[] = $totalCells;

        $table = $this->markdownTable($header, $body);
        $chart = $this->salesReportChart($rowDim, $colDim, array_keys($rowTotals), $colKeys, $grid, $symbol);
        if ($chart !== null) {
            $table .= "\n\n".$chart;
        }

        return $table;
    }

    /**
     * @param  list<string>  $keys
     * @return list<string>
     */
    protected function salesReportLabels(string $dim, array $keys): array
    {
        if ($dim !== 'month') {
            return $keys;
        }

        return array_map(function (string $key) {
            $month = substr($key, 5, 2);

            return ['01' => 'Jan', '02' => 'Feb', '03' => 'Mar', '04' => 'Apr', '05' => 'May', '06' => 'Jun', '07' => 'Jul', '08' => 'Aug', '09' => 'Sep', '10' => 'Oct', '11' => 'Nov', '12' => 'Dec'][$month] ?? $key;
        }, $keys);
    }

    /**
     * @param  list<string>  $rowKeys
     * @param  list<string>  $colKeys
     * @param  array<string, array<string, float>>  $grid
     */
    protected function salesReportChart(string $rowDim, string $colDim, array $rowKeys, array $colKeys, array $grid, string $symbol): ?string
    {
        $monthsOnX = $colDim === 'month' && count($colKeys) <= 12 && count($rowKeys) <= 8;
        $monthsAsRows = $rowDim === 'month' && count($rowKeys) <= 12 && count($colKeys) <= 8;
        if (! $monthsOnX && ! $monthsAsRows) {
            return null;
        }

        if ($monthsOnX) {
            $labels = $this->salesReportLabels('month', $colKeys);
            $datasets = [];
            foreach (array_slice($rowKeys, 0, 8) as $row) {
                $data = [];
                foreach ($colKeys as $col) {
                    $data[] = round((float) ($grid[$row][$col] ?? 0), 2);
                }
                $datasets[] = ['label' => $row, 'data' => $data];
            }
        } else {
            $labels = $this->salesReportLabels('month', $rowKeys);
            $datasets = [];
            foreach (array_slice($colKeys, 0, 8) as $col) {
                $data = [];
                foreach ($rowKeys as $row) {
                    $data[] = round((float) ($grid[$row][$col] ?? 0), 2);
                }
                $datasets[] = ['label' => $col, 'data' => $data];
            }
        }

        return $this->chartFence([
            'type' => 'bar',
            'eli_height' => 340,
            'eli_currency' => $symbol,
            'eli_style' => 'editorial',
            'data' => [
                'labels' => $labels,
                'datasets' => $datasets,
            ],
        ]);
    }
}
