<?php

namespace Modules\AIBusinessManager\Services\Concerns;

use App\Business;
use App\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Location × month and product × location sales grids.
 */
trait LocationSalesMatrixTools
{
    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected function salesByLocationMonth(array $args, int $businessId, User $user): array
    {
        $range = $this->parseDateRangeOrYearToDate($args, $businessId, $user);
        if (isset($range['ok']) && $range['ok'] === false) {
            return $range;
        }

        /** @var Carbon $start */
        $start = $range['start'];
        /** @var Carbon $end */
        $end = $range['end'];
        $locationIds = $range['location_ids'];
        $precision = $range['precision'];
        $symbol = $range['symbol'];

        $months = $this->monthColumns($start, $end);
        if (count($months) > 36) {
            return ['ok' => false, 'error' => 'date_span_exceeds_limit', 'max_months' => 36];
        }

        $monthKeys = array_column($months, 'key');
        $locations = $this->visibleLocations($businessId, $locationIds);

        $sales = DB::table('transactions as t')
            ->where('t.business_id', $businessId)
            ->where('t.type', 'sell')
            ->where('t.status', 'final')
            ->whereBetween('t.transaction_date', [$start, $end])
            ->when($locationIds !== null, fn ($q) => $q->whereIn('t.location_id', $locationIds))
            ->groupBy('t.location_id', DB::raw("DATE_FORMAT(t.transaction_date, '%Y-%m')"))
            ->selectRaw('t.location_id')
            ->selectRaw("DATE_FORMAT(t.transaction_date, '%Y-%m') as month_key")
            ->selectRaw('SUM(t.final_total) as revenue')
            ->selectRaw('COUNT(*) as invoices')
            ->get();

        $byLocation = [];
        foreach ($sales as $row) {
            $id = (int) $row->location_id;
            $byLocation[$id][(string) $row->month_key] = [
                'revenue' => (float) $row->revenue,
                'invoices' => (int) $row->invoices,
            ];
        }

        $known = [];
        foreach ($locations as $location) {
            $known[(int) $location->id] = (string) $location->name;
        }
        foreach (array_keys($byLocation) as $id) {
            if (! isset($known[$id])) {
                $known[$id] = 'Location #'.$id;
            }
        }

        $rows = [];
        $totalsByMonth = array_fill_keys($monthKeys, 0.0);
        $grand = 0.0;
        foreach ($known as $id => $name) {
            $revenueByMonth = [];
            $total = 0.0;
            $invoices = 0;
            foreach ($monthKeys as $key) {
                $cell = $byLocation[$id][$key] ?? ['revenue' => 0.0, 'invoices' => 0];
                $amount = round((float) $cell['revenue'], $precision);
                $revenueByMonth[$key] = $amount;
                $totalsByMonth[$key] = round($totalsByMonth[$key] + $amount, $precision);
                $total += $amount;
                $invoices += (int) $cell['invoices'];
            }
            $total = round($total, $precision);
            $grand += $total;
            $rows[] = [
                'location_name' => $name,
                'revenue_by_month' => $revenueByMonth,
                'total_revenue' => $total,
                'invoices' => $invoices,
            ];
        }

        usort($rows, fn ($a, $b) => $b['total_revenue'] <=> $a['total_revenue']);

        return [
            'ok' => true,
            'currency_symbol' => $symbol,
            'start' => $start->toDateString(),
            'end' => $end->toDateString(),
            'months' => $months,
            'period_note' => $this->partialMonthNote($start, $end),
            'note' => 'Invoice revenue (final_total) by location and calendar month. Present rows as a table: locations down the side, months across in the given order, then a Total column. Keep months that are 0. totals_by_month is the bottom row. Do not reorder months.',
            'rows' => $rows,
            'totals_by_month' => $totalsByMonth,
            'grand_total' => round($grand, $precision),
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected function salesByProductLocation(array $args, int $businessId, User $user): array
    {
        $range = $this->parseDateRangeOrYearToDate($args, $businessId, $user);
        if (isset($range['ok']) && $range['ok'] === false) {
            return $range;
        }

        /** @var Carbon $start */
        $start = $range['start'];
        /** @var Carbon $end */
        $end = $range['end'];
        $locationIds = $range['location_ids'];
        $precision = $range['precision'];
        $symbol = $range['symbol'];
        $byMonth = filter_var($args['by_month'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $limit = isset($args['limit']) ? (int) $args['limit'] : ($byMonth ? 15 : 25);
        $limit = max(1, min($byMonth ? 20 : 40, $limit));

        $nameQuery = isset($args['name_query']) ? trim((string) $args['name_query']) : '';
        $locations = $this->visibleLocations($businessId, $locationIds);
        $locationNames = [];
        foreach ($locations as $location) {
            $locationNames[(int) $location->id] = (string) $location->name;
        }

        $months = $byMonth ? $this->monthColumns($start, $end) : [];
        if ($byMonth && count($months) > 36) {
            return ['ok' => false, 'error' => 'date_span_exceeds_limit', 'max_months' => 36];
        }
        $monthKeys = array_column($months, 'key');

        $qtySql = $this->qtySellingUomSql();
        $unitExpr = 'COALESCE(NULLIF(TRIM(sell_unit.short_name), ""), NULLIF(TRIM(base_u.short_name), ""), "unit")';
        $lineValue = 'tsl.quantity * COALESCE(tsl.unit_price_inc_tax, tsl.unit_price, 0)';

        $base = $this->sellLinesInRangeQuery($businessId, $locationIds, $start, $end)
            ->join('products as p', 'p.id', '=', 'tsl.product_id')
            ->leftJoin('units as base_u', 'base_u.id', '=', 'p.unit_id')
            ->when($nameQuery !== '', fn ($q) => $q->where('p.name', 'like', '%'.$nameQuery.'%'));

        $productCount = (int) (clone $base)->selectRaw('COUNT(DISTINCT p.id) as c')->value('c');

        $topIds = (clone $base)
            ->groupBy('p.id')
            ->orderByRaw('SUM('.$lineValue.') DESC')
            ->limit($limit)
            ->pluck('p.id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($topIds === []) {
            return [
                'ok' => true,
                'currency_symbol' => $symbol,
                'start' => $start->toDateString(),
                'end' => $end->toDateString(),
                'locations' => array_values($locationNames),
                'months' => $months,
                'rows' => [],
                'note' => 'No finalized product sales in this range.',
            ];
        }

        $detail = (clone $base)
            ->whereIn('p.id', $topIds)
            ->groupBy('p.id', 'p.name', 't.location_id');
        if ($byMonth) {
            $detail->groupBy(DB::raw("DATE_FORMAT(t.transaction_date, '%Y-%m')"));
            $detail->selectRaw("DATE_FORMAT(t.transaction_date, '%Y-%m') as month_key");
        }
        $detailRows = $detail
            ->selectRaw('p.id as product_id')
            ->selectRaw('p.name as product_name')
            ->selectRaw('t.location_id')
            ->selectRaw('SUM('.$lineValue.') as revenue')
            ->selectRaw('SUM('.$qtySql.') as quantity')
            ->selectRaw('SUBSTRING_INDEX(GROUP_CONCAT(DISTINCT '.$unitExpr.' ORDER BY '.$unitExpr.' SEPARATOR "|"), "|", 1) as unit')
            ->get();

        $products = [];
        foreach ($detailRows as $row) {
            $pid = (int) $row->product_id;
            $lid = (int) $row->location_id;
            if (! isset($locationNames[$lid])) {
                $locationNames[$lid] = 'Location #'.$lid;
            }
            if (! isset($products[$pid])) {
                $products[$pid] = [
                    'product_name' => (string) $row->product_name,
                    'unit' => (string) ($row->unit ?: 'unit'),
                    'cells' => [],
                ];
            }
            $cell = [
                'revenue' => round((float) $row->revenue, $precision),
                'quantity' => $this->roundQuantity((float) $row->quantity),
            ];
            if ($byMonth) {
                $products[$pid]['cells'][$lid][(string) $row->month_key] = $cell;
            } else {
                $products[$pid]['cells'][$lid] = $cell;
            }
        }

        $locationOrder = $this->locationNamesByRevenue($locationNames, $products, $byMonth);
        $rows = [];
        $totalsByLocation = array_fill_keys($locationOrder, 0.0);
        foreach ($products as $product) {
            if ($byMonth) {
                $locationRows = [];
                $productTotal = 0.0;
                $productQty = 0.0;
                foreach ($locationOrder as $name) {
                    $lid = array_search($name, $locationNames, true);
                    $revenueByMonth = [];
                    $quantityByMonth = [];
                    $locTotal = 0.0;
                    $locQty = 0.0;
                    foreach ($monthKeys as $key) {
                        $cell = ($lid !== false ? ($product['cells'][$lid][$key] ?? null) : null) ?? ['revenue' => 0.0, 'quantity' => 0.0];
                        $revenueByMonth[$key] = $cell['revenue'];
                        $quantityByMonth[$key] = $cell['quantity'];
                        $locTotal += $cell['revenue'];
                        $locQty += $cell['quantity'];
                    }
                    $locTotal = round($locTotal, $precision);
                    $productTotal += $locTotal;
                    $productQty += $locQty;
                    $totalsByLocation[$name] = round(($totalsByLocation[$name] ?? 0) + $locTotal, $precision);
                    $locationRows[] = [
                        'location_name' => $name,
                        'revenue_by_month' => $revenueByMonth,
                        'quantity_by_month' => $quantityByMonth,
                        'total_revenue' => $locTotal,
                        'total_quantity' => $this->roundQuantity($locQty),
                    ];
                }
                $rows[] = [
                    'product_name' => $product['product_name'],
                    'unit' => $product['unit'],
                    'locations' => $locationRows,
                    'total_revenue' => round($productTotal, $precision),
                    'total_quantity' => $this->roundQuantity($productQty),
                ];
            } else {
                $revenueByLocation = [];
                $quantityByLocation = [];
                $productTotal = 0.0;
                $productQty = 0.0;
                foreach ($locationOrder as $name) {
                    $lid = array_search($name, $locationNames, true);
                    $cell = $product['cells'][$lid] ?? ['revenue' => 0.0, 'quantity' => 0.0];
                    $revenueByLocation[$name] = $cell['revenue'];
                    $quantityByLocation[$name] = $cell['quantity'];
                    $productTotal += $cell['revenue'];
                    $productQty += $cell['quantity'];
                    $totalsByLocation[$name] = round($totalsByLocation[$name] + $cell['revenue'], $precision);
                }
                $rows[] = [
                    'product_name' => $product['product_name'],
                    'unit' => $product['unit'],
                    'revenue_by_location' => $revenueByLocation,
                    'quantity_by_location' => $quantityByLocation,
                    'total_revenue' => round($productTotal, $precision),
                    'total_quantity' => $this->roundQuantity($productQty),
                ];
            }
        }

        usort($rows, fn ($a, $b) => $b['total_revenue'] <=> $a['total_revenue']);

        return [
            'ok' => true,
            'currency_symbol' => $symbol,
            'start' => $start->toDateString(),
            'end' => $end->toDateString(),
            'by_month' => $byMonth,
            'months' => $months,
            'locations' => $locationOrder,
            'period_note' => $this->partialMonthNote($start, $end),
            'truncated' => $productCount > count($rows),
            'products_in_range' => $productCount,
            'note' => 'Revenue is sell-line value (quantity × unit price including tax), so it can differ from invoice final_total when an invoice has a discount. Present a table with products as rows and locations as columns, using revenue_by_location, plus a Total column. Quote quantity_by_location with the unit when they ask how many. Do not add different units together. When by_month is true, each product has a locations list with revenue_by_month.',
            'rows' => $rows,
            'totals_by_location' => $totalsByLocation,
            'grand_total' => round(array_sum($totalsByLocation), $precision),
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected function parseDateRangeOrYearToDate(array $args, int $businessId, User $user): array
    {
        $missingStart = ! isset($args['start_date']) || ! is_string($args['start_date']) || $args['start_date'] === '';
        $missingEnd = ! isset($args['end_date']) || ! is_string($args['end_date']) || $args['end_date'] === '';
        if ($missingStart || $missingEnd) {
            $business = Business::find($businessId);
            if (! $business) {
                return ['ok' => false, 'error' => 'business_not_found'];
            }
            $tz = $business->time_zone ?: (string) config('app.timezone');
            $today = Carbon::now($tz);
            if ($missingStart) {
                $args['start_date'] = $today->copy()->startOfYear()->toDateString();
            }
            if ($missingEnd) {
                $args['end_date'] = $today->toDateString();
            }
        }

        return $this->parseDateRange($args, $businessId, $user);
    }

    /**
     * @return list<array{key: string, label: string}>
     */
    protected function monthColumns(Carbon $start, Carbon $end): array
    {
        $columns = [];
        $cursor = $start->copy()->startOfMonth();
        $last = $end->copy()->startOfMonth();
        $spansYears = $start->year !== $end->year;
        while ($cursor->lte($last)) {
            $columns[] = [
                'key' => $cursor->format('Y-m'),
                'label' => $spansYears ? $cursor->format('M Y') : $cursor->format('M'),
            ];
            $cursor->addMonth();
        }

        return $columns;
    }

    protected function partialMonthNote(Carbon $start, Carbon $end): string
    {
        $parts = [];
        if ($start->day !== 1) {
            $parts[] = 'The first month starts on '.$start->toDateString().', not the 1st.';
        }
        if (! $end->isSameDay($end->copy()->endOfMonth())) {
            $parts[] = $end->format('M Y').' runs through '.$end->toDateString().', not the full month.';
        }

        return $parts === []
            ? 'Every listed month is a full calendar month inside the date range.'
            : implode(' ', $parts);
    }

    /**
     * @param  ?array<int>  $locationIds
     * @return \Illuminate\Support\Collection<int, object>
     */
    protected function visibleLocations(int $businessId, ?array $locationIds)
    {
        return DB::table('business_locations')
            ->where('business_id', $businessId)
            ->when($locationIds !== null, fn ($q) => $q->whereIn('id', $locationIds))
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    /**
     * @param  array<int, string>  $locationNames
     * @param  array<int, array{cells: array<int, mixed>}>  $products
     * @return list<string>
     */
    protected function locationNamesByRevenue(array $locationNames, array $products, bool $byMonth): array
    {
        $totals = [];
        foreach ($locationNames as $id => $name) {
            $totals[$name] = 0.0;
            foreach ($products as $product) {
                if ($byMonth) {
                    foreach ($product['cells'][$id] ?? [] as $cell) {
                        $totals[$name] += (float) ($cell['revenue'] ?? 0);
                    }
                } else {
                    $totals[$name] += (float) ($product['cells'][$id]['revenue'] ?? 0);
                }
            }
        }
        uksort($totals, fn ($a, $b) => $totals[$b] <=> $totals[$a]);

        return array_keys($totals);
    }
}
