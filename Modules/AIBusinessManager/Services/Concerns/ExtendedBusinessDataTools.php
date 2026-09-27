<?php

namespace Modules\AIBusinessManager\Services\Concerns;

use App\User;
use Illuminate\Support\Facades\DB;

trait ExtendedBusinessDataTools
{
    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected function sellReturnAggregate(array $args, int $businessId, User $user): array
    {
        $granularity = (string) ($args['granularity'] ?? 'total');
        if (! in_array($granularity, ['total', 'month', 'year'], true)) {
            return ['ok' => false, 'error' => 'invalid_granularity'];
        }

        $range = $this->parseDateRange($args, $businessId, $user);
        if (isset($range['ok']) && $range['ok'] === false) {
            return $range;
        }

        $base = DB::table('transactions')
            ->where('business_id', $businessId)
            ->where('type', 'sell_return')
            ->where('status', 'final')
            ->whereBetween('transaction_date', [$range['start'], $range['end']])
            ->when($range['location_ids'] !== null, fn ($q) => $q->whereIn('location_id', $range['location_ids']));

        return $this->aggregateFinalTotalsByGranularity(
            $base,
            $granularity,
            $range['precision'],
            $range['symbol'],
            $range['code'],
            'sell_return_final',
            'return_total',
            'returns'
        );
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected function purchaseReturnAggregate(array $args, int $businessId, User $user): array
    {
        $granularity = (string) ($args['granularity'] ?? 'total');
        if (! in_array($granularity, ['total', 'month', 'year'], true)) {
            return ['ok' => false, 'error' => 'invalid_granularity'];
        }

        $range = $this->parseDateRange($args, $businessId, $user);
        if (isset($range['ok']) && $range['ok'] === false) {
            return $range;
        }

        $base = DB::table('transactions')
            ->where('business_id', $businessId)
            ->where('type', 'purchase_return')
            ->where('status', 'final')
            ->whereBetween('transaction_date', [$range['start'], $range['end']])
            ->when($range['location_ids'] !== null, fn ($q) => $q->whereIn('location_id', $range['location_ids']));

        return $this->aggregateFinalTotalsByGranularity(
            $base,
            $granularity,
            $range['precision'],
            $range['symbol'],
            $range['code'],
            'purchase_return_final',
            'return_total',
            'returns'
        );
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected function openingStockAggregate(array $args, int $businessId, User $user): array
    {
        $granularity = (string) ($args['granularity'] ?? 'total');
        if (! in_array($granularity, ['total', 'month', 'year'], true)) {
            return ['ok' => false, 'error' => 'invalid_granularity'];
        }

        $range = $this->parseDateRange($args, $businessId, $user);
        if (isset($range['ok']) && $range['ok'] === false) {
            return $range;
        }
        $start = $range['start'];
        $end = $range['end'];
        $location_ids = $range['location_ids'];
        $precision = $range['precision'];
        $symbol = $range['symbol'];
        $code = $range['code'];

        $base = DB::table('transactions as t')
            ->join('purchase_lines as pl', 'pl.transaction_id', '=', 't.id')
            ->where('t.business_id', $businessId)
            ->where('t.type', 'opening_stock')
            ->where('t.status', 'received')
            ->whereBetween('t.transaction_date', [$start, $end])
            ->when($location_ids !== null, fn ($q) => $q->whereIn('t.location_id', $location_ids));

        if ($granularity === 'total') {
            $row = (clone $base)
                ->selectRaw('SUM(t.final_total) as amt')
                ->selectRaw('COUNT(DISTINCT t.id) as cnt')
                ->selectRaw('SUM(pl.quantity) as qty_opening')
                ->first();

            return [
                'ok' => true,
                'granularity' => 'total',
                'basis' => 'opening_stock_received',
                'currency_code' => $code,
                'currency_symbol' => $symbol,
                'rows' => [[
                    'value_total' => round((float) ($row->amt ?? 0), $precision),
                    'entries' => (int) ($row->cnt ?? 0),
                    'quantity_opening' => $this->roundQuantity((float) ($row->qty_opening ?? 0)),
                ]],
            ];
        }

        if ($granularity === 'month') {
            $rows = (clone $base)
                ->selectRaw("DATE_FORMAT(t.transaction_date, '%Y-%m') as period")
                ->selectRaw('SUM(t.final_total) as amt')
                ->selectRaw('COUNT(DISTINCT t.id) as cnt')
                ->selectRaw('SUM(pl.quantity) as qty_opening')
                ->groupBy(DB::raw("DATE_FORMAT(t.transaction_date, '%Y-%m')"))
                ->orderBy('period')
                ->limit(60)
                ->get();

            return [
                'ok' => true,
                'granularity' => 'month',
                'basis' => 'opening_stock_received',
                'currency_code' => $code,
                'currency_symbol' => $symbol,
                'rows' => $rows->map(fn ($r) => [
                    'period' => (string) $r->period,
                    'value_total' => round((float) $r->amt, $precision),
                    'entries' => (int) $r->cnt,
                    'quantity_opening' => $this->roundQuantity((float) ($r->qty_opening ?? 0)),
                ])->values()->all(),
            ];
        }

        $rows = (clone $base)
            ->selectRaw('YEAR(t.transaction_date) as period')
            ->selectRaw('SUM(t.final_total) as amt')
            ->selectRaw('COUNT(DISTINCT t.id) as cnt')
            ->selectRaw('SUM(pl.quantity) as qty_opening')
            ->groupBy(DB::raw('YEAR(t.transaction_date)'))
            ->orderBy('period')
            ->limit(40)
            ->get();

        return [
            'ok' => true,
            'granularity' => 'year',
            'basis' => 'opening_stock_received',
            'currency_code' => $code,
            'currency_symbol' => $symbol,
            'rows' => $rows->map(fn ($r) => [
                'period' => (string) $r->period,
                'value_total' => round((float) $r->amt, $precision),
                'entries' => (int) $r->cnt,
                'quantity_opening' => $this->roundQuantity((float) ($r->qty_opening ?? 0)),
            ])->values()->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected function salePaymentMix(array $args, int $businessId, User $user): array
    {
        $range = $this->parseDateRange($args, $businessId, $user);
        if (isset($range['ok']) && $range['ok'] === false) {
            return $range;
        }
        $start = $range['start'];
        $end = $range['end'];
        $location_ids = $range['location_ids'];
        $precision = $range['precision'];
        $symbol = $range['symbol'];
        $code = $range['code'];

        $rows = DB::table('transaction_payments as tp')
            ->join('transactions as t', 't.id', '=', 'tp.transaction_id')
            ->where('t.business_id', $businessId)
            ->where('t.type', 'sell')
            ->where('t.status', 'final')
            ->whereNotNull('tp.transaction_id')
            ->whereRaw('COALESCE(tp.paid_on, tp.created_at) BETWEEN ? AND ?', [$start, $end])
            ->when($location_ids !== null, fn ($q) => $q->whereIn('t.location_id', $location_ids))
            ->groupBy(DB::raw("COALESCE(tp.method, 'unknown')"))
            ->orderByDesc(DB::raw('SUM(tp.amount)'))
            ->selectRaw("COALESCE(tp.method, 'unknown') as method")
            ->selectRaw('SUM(tp.amount) as amount')
            ->selectRaw('COUNT(*) as payment_rows')
            ->get();

        return [
            'ok' => true,
            'basis' => 'invoice_linked_sale_payments',
            'currency_code' => $code,
            'currency_symbol' => $symbol,
            'caveat' => 'Excludes standalone contact payments (transaction_payments with no transaction_id). Uses payment date COALESCE(paid_on, created_at).',
            'rows' => $rows->map(fn ($r) => [
                'method' => (string) $r->method,
                'amount' => round((float) $r->amount, $precision),
                'payment_rows' => (int) $r->payment_rows,
            ])->values()->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected function stockAdjustmentAggregate(array $args, int $businessId, User $user): array
    {
        $granularity = (string) ($args['granularity'] ?? 'total');
        if (! in_array($granularity, ['total', 'month', 'year'], true)) {
            return ['ok' => false, 'error' => 'invalid_granularity'];
        }

        $range = $this->parseDateRange($args, $businessId, $user);
        if (isset($range['ok']) && $range['ok'] === false) {
            return $range;
        }
        $start = $range['start'];
        $end = $range['end'];
        $location_ids = $range['location_ids'];

        $dirCase = "COALESCE(sal.adjustment_direction, 'decrease')";
        $qtyInc = "SUM(CASE WHEN {$dirCase} = 'increase' THEN sal.quantity ELSE 0 END)";
        $qtyDec = "SUM(CASE WHEN {$dirCase} = 'decrease' THEN sal.quantity ELSE 0 END)";

        $base = DB::table('stock_adjustment_lines as sal')
            ->join('transactions as t', 't.id', '=', 'sal.transaction_id')
            ->where('t.business_id', $businessId)
            ->where('t.type', 'stock_adjustment')
            ->where('t.status', 'received')
            ->whereBetween('t.transaction_date', [$start, $end])
            ->when($location_ids !== null, fn ($q) => $q->whereIn('t.location_id', $location_ids));

        if ($granularity === 'total') {
            $row = (clone $base)
                ->selectRaw("{$qtyInc} as qty_increase")
                ->selectRaw("{$qtyDec} as qty_decrease")
                ->selectRaw('COUNT(DISTINCT t.id) as adjustments')
                ->first();

            return [
                'ok' => true,
                'granularity' => 'total',
                'basis' => 'stock_adjustment_received',
                'note' => 'Quantities are stock_adjustment_lines.quantity (base units). Null adjustment_direction counts as decrease.',
                'rows' => [[
                    'qty_increase' => $this->roundQuantity((float) ($row->qty_increase ?? 0)),
                    'qty_decrease' => $this->roundQuantity((float) ($row->qty_decrease ?? 0)),
                    'adjustments' => (int) ($row->adjustments ?? 0),
                ]],
            ];
        }

        if ($granularity === 'month') {
            $rows = (clone $base)
                ->selectRaw("DATE_FORMAT(t.transaction_date, '%Y-%m') as period")
                ->selectRaw("{$qtyInc} as qty_increase")
                ->selectRaw("{$qtyDec} as qty_decrease")
                ->selectRaw('COUNT(DISTINCT t.id) as adjustments')
                ->groupBy(DB::raw("DATE_FORMAT(t.transaction_date, '%Y-%m')"))
                ->orderBy('period')
                ->limit(60)
                ->get();

            return [
                'ok' => true,
                'granularity' => 'month',
                'basis' => 'stock_adjustment_received',
                'note' => 'Quantities are stock_adjustment_lines.quantity (base units).',
                'rows' => $rows->map(fn ($r) => [
                    'period' => (string) $r->period,
                    'qty_increase' => $this->roundQuantity((float) ($r->qty_increase ?? 0)),
                    'qty_decrease' => $this->roundQuantity((float) ($r->qty_decrease ?? 0)),
                    'adjustments' => (int) ($r->adjustments ?? 0),
                ])->values()->all(),
            ];
        }

        $rows = (clone $base)
            ->selectRaw('YEAR(t.transaction_date) as period')
            ->selectRaw("{$qtyInc} as qty_increase")
            ->selectRaw("{$qtyDec} as qty_decrease")
            ->selectRaw('COUNT(DISTINCT t.id) as adjustments')
            ->groupBy(DB::raw('YEAR(t.transaction_date)'))
            ->orderBy('period')
            ->limit(40)
            ->get();

        return [
            'ok' => true,
            'granularity' => 'year',
            'basis' => 'stock_adjustment_received',
            'note' => 'Quantities are stock_adjustment_lines.quantity (base units).',
            'rows' => $rows->map(fn ($r) => [
                'period' => (string) $r->period,
                'qty_increase' => $this->roundQuantity((float) ($r->qty_increase ?? 0)),
                'qty_decrease' => $this->roundQuantity((float) ($r->qty_decrease ?? 0)),
                'adjustments' => (int) ($r->adjustments ?? 0),
            ])->values()->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected function stockTransferSummary(array $args, int $businessId, User $user): array
    {
        $range = $this->parseDateRange($args, $businessId, $user);
        if (isset($range['ok']) && $range['ok'] === false) {
            return $range;
        }
        $start = $range['start'];
        $end = $range['end'];
        $location_ids = $range['location_ids'];

        $routeLimit = isset($args['route_limit']) ? (int) $args['route_limit'] : 10;
        $routeLimit = max(1, min(25, $routeLimit));

        $core = DB::table('transactions as sell_t')
            ->join('transactions as purchase_t', 'sell_t.id', '=', 'purchase_t.transfer_parent_id')
            ->join('transaction_sell_lines as tsl', 'tsl.transaction_id', '=', 'sell_t.id')
            ->where('sell_t.business_id', $businessId)
            ->where('sell_t.type', 'sell_transfer')
            ->where('sell_t.status', 'final')
            ->where('purchase_t.type', 'purchase_transfer')
            ->where('purchase_t.status', 'final')
            ->whereBetween('sell_t.transaction_date', [$start, $end])
            ->when($location_ids !== null, function ($q) use ($location_ids) {
                $q->where(function ($q2) use ($location_ids) {
                    $q2->whereIn('sell_t.location_id', $location_ids)
                        ->orWhereIn('purchase_t.location_id', $location_ids);
                });
            });

        $totals = (clone $core)
            ->selectRaw('COUNT(DISTINCT sell_t.id) as transfers')
            ->selectRaw('SUM(tsl.quantity) as qty_base')
            ->first();

        $routes = (clone $core)
            ->join('business_locations as bl_from', 'bl_from.id', '=', 'sell_t.location_id')
            ->join('business_locations as bl_to', 'bl_to.id', '=', 'purchase_t.location_id')
            ->groupBy('sell_t.location_id', 'purchase_t.location_id', 'bl_from.name', 'bl_to.name')
            ->orderByDesc(DB::raw('SUM(tsl.quantity)'))
            ->limit($routeLimit)
            ->selectRaw('bl_from.name as from_location')
            ->selectRaw('bl_to.name as to_location')
            ->selectRaw('COUNT(DISTINCT sell_t.id) as transfers')
            ->selectRaw('SUM(tsl.quantity) as qty_base')
            ->get();

        return [
            'ok' => true,
            'basis' => 'sell_transfer_with_paired_purchase_transfer_final',
            'note' => 'qty_base sums transaction_sell_lines.quantity on the outgoing sell_transfer (stock units, not selling-UoM converted).',
            'summary' => [
                'transfers' => (int) ($totals->transfers ?? 0),
                'qty_base' => $this->roundQuantity((float) ($totals->qty_base ?? 0)),
            ],
            'top_routes' => $routes->map(fn ($r) => [
                'from_location' => (string) $r->from_location,
                'to_location' => (string) $r->to_location,
                'transfers' => (int) $r->transfers,
                'qty_base' => $this->roundQuantity((float) ($r->qty_base ?? 0)),
            ])->values()->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected function salesOrderPipeline(array $args, int $businessId, User $user): array
    {
        $range = $this->parseDateRange($args, $businessId, $user);
        if (isset($range['ok']) && $range['ok'] === false) {
            return $range;
        }
        $precision = $range['precision'];
        $symbol = $range['symbol'];
        $code = $range['code'];

        $rows = DB::table('transactions as t')
            ->where('t.business_id', $businessId)
            ->where('t.type', 'sales_order')
            ->whereBetween('t.transaction_date', [$range['start'], $range['end']])
            ->when($range['location_ids'] !== null, fn ($q) => $q->whereIn('t.location_id', $range['location_ids']))
            ->groupBy('t.status')
            ->orderByDesc(DB::raw('SUM(t.final_total)'))
            ->selectRaw('t.status as status')
            ->selectRaw('COUNT(*) as orders')
            ->selectRaw('SUM(t.final_total) as value_total')
            ->get();

        return [
            'ok' => true,
            'basis' => 'sales_orders_by_status',
            'currency_code' => $code,
            'currency_symbol' => $symbol,
            'rows' => $rows->map(fn ($r) => [
                'status' => (string) $r->status,
                'orders' => (int) $r->orders,
                'value_total' => round((float) ($r->value_total ?? 0), $precision),
            ])->values()->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected function purchaseOrderPipeline(array $args, int $businessId, User $user): array
    {
        $range = $this->parseDateRange($args, $businessId, $user);
        if (isset($range['ok']) && $range['ok'] === false) {
            return $range;
        }
        $precision = $range['precision'];
        $symbol = $range['symbol'];
        $code = $range['code'];

        $rows = DB::table('transactions as t')
            ->where('t.business_id', $businessId)
            ->where('t.type', 'purchase_order')
            ->whereBetween('t.transaction_date', [$range['start'], $range['end']])
            ->when($range['location_ids'] !== null, fn ($q) => $q->whereIn('t.location_id', $range['location_ids']))
            ->groupBy('t.status')
            ->orderByDesc(DB::raw('SUM(t.final_total)'))
            ->selectRaw('t.status as status')
            ->selectRaw('COUNT(*) as orders')
            ->selectRaw('SUM(t.final_total) as value_total')
            ->get();

        return [
            'ok' => true,
            'basis' => 'purchase_orders_by_status',
            'currency_code' => $code,
            'currency_symbol' => $symbol,
            'rows' => $rows->map(fn ($r) => [
                'status' => (string) $r->status,
                'orders' => (int) $r->orders,
                'value_total' => round((float) ($r->value_total ?? 0), $precision),
            ])->values()->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected function payrollAggregate(array $args, int $businessId, User $user): array
    {
        $granularity = (string) ($args['granularity'] ?? 'total');
        if (! in_array($granularity, ['total', 'month', 'year'], true)) {
            return ['ok' => false, 'error' => 'invalid_granularity'];
        }

        $range = $this->parseDateRange($args, $businessId, $user);
        if (isset($range['ok']) && $range['ok'] === false) {
            return $range;
        }

        $base = DB::table('transactions')
            ->where('business_id', $businessId)
            ->where('type', 'payroll')
            ->where('status', 'final')
            ->whereBetween('transaction_date', [$range['start'], $range['end']])
            ->when($range['location_ids'] !== null, fn ($q) => $q->whereIn('location_id', $range['location_ids']));

        return $this->aggregateFinalTotalsByGranularity(
            $base,
            $granularity,
            $range['precision'],
            $range['symbol'],
            $range['code'],
            'payroll_final',
            'payroll_total',
            'runs'
        );
    }
}
