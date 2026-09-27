<?php

namespace Modules\AIBusinessManager\Services\Concerns;

use App\Business;
use App\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

trait TransactionAndAnalyticsTools
{
    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected function transactionDetail(array $args, int $businessId, User $user): array
    {
        $tid = isset($args['transaction_id']) ? (int) $args['transaction_id'] : 0;
        $invoice_no = isset($args['invoice_no']) ? trim((string) $args['invoice_no']) : '';
        $type_hint = strtolower((string) ($args['type'] ?? ''));

        $permitted = $user->permitted_locations();
        $location_ids = null;
        if ($permitted !== 'all') {
            $location_ids = is_array($permitted) ? $permitted : [];
        }

        $q = DB::table('transactions as t')
            ->where('t.business_id', $businessId)
            ->when($location_ids !== null, fn ($qq) => $qq->whereIn('t.location_id', $location_ids));

        if ($tid > 0) {
            $q->where('t.id', $tid);
        } elseif ($invoice_no !== '') {
            $q->where(function ($w) use ($invoice_no) {
                $w->where('t.invoice_no', $invoice_no)->orWhere('t.ref_no', $invoice_no);
            });
            if (in_array($type_hint, ['sell', 'purchase', 'expense', 'sell_return', 'purchase_return', 'opening_stock', 'stock_adjustment', 'payroll'], true)) {
                $q->where('t.type', $type_hint);
            }
        } else {
            return ['ok' => false, 'error' => 'missing_transaction_id_or_invoice_no'];
        }

        $trow = $q->select(
            't.id',
            't.type',
            't.status',
            't.payment_status',
            't.transaction_date',
            't.contact_id',
            't.location_id',
            't.invoice_no',
            't.ref_no',
            't.final_total',
            't.total_before_tax',
            't.tax_amount',
            't.discount_amount',
            't.shipping_charges',
            't.created_by'
        )->first();

        if (! $trow) {
            return ['ok' => false, 'error' => 'transaction_not_found'];
        }

        $b = Business::with('currency')->find($businessId);
        $precision = (int) ($b->currency_precision ?? 2);
        $symbol = $b && $b->currency ? $b->currency->symbol : '';

        $contact_name = null;
        if (! empty($trow->contact_id)) {
            $contact_name = DB::table('contacts')
                ->where('id', $trow->contact_id)
                ->where('business_id', $businessId)
                ->selectRaw('COALESCE(NULLIF(TRIM(name), ""), NULLIF(TRIM(supplier_business_name), ""), CONCAT("Contact #", id)) as n')
                ->value('n');
        }

        $location_name = null;
        if (! empty($trow->location_id)) {
            $location_name = DB::table('business_locations')
                ->where('id', $trow->location_id)
                ->value('name');
        }

        $creator = null;
        if (! empty($trow->created_by)) {
            $creator = DB::table('users')->where('id', $trow->created_by)->value('username')
                ?? DB::table('users')->where('id', $trow->created_by)->value('first_name');
        }

        $payments = DB::table('transaction_payments')
            ->where('transaction_id', $trow->id)
            ->orderBy('paid_on')
            ->limit(100)
            ->select('id', 'amount', 'method', 'paid_on', 'is_return')
            ->get()
            ->map(fn ($p) => [
                'id' => (int) $p->id,
                'amount' => round((float) $p->amount, $precision),
                'method' => (string) ($p->method ?? ''),
                'paid_on' => $p->paid_on !== null ? (string) $p->paid_on : null,
                'is_return' => (bool) $p->is_return,
            ])->values()->all();

        $lines = [];
        if ($trow->type === 'sell') {
            $lineLimit = (int) config('aibusinessmanager.tool_transaction_line_limit', 80);
            $qtySql = $this->qtySellingUomSql();
            $lines = DB::table('transaction_sell_lines as tsl')
                ->join('products as p', 'p.id', '=', 'tsl.product_id')
                ->leftJoin('variations as v', 'v.id', '=', 'tsl.variation_id')
                ->leftJoin('units as sell_unit', 'sell_unit.id', '=', 'tsl.sub_unit_id')
                ->where('tsl.transaction_id', $trow->id)
                ->orderBy('tsl.id')
                ->limit($lineLimit)
                ->selectRaw('tsl.id as line_id')
                ->selectRaw('p.name as product_name')
                ->selectRaw('v.name as variation_name')
                ->selectRaw('tsl.quantity')
                ->selectRaw($qtySql.' as quantity_selling_uom')
                ->selectRaw('tsl.unit_price')
                ->selectRaw('tsl.unit_price_inc_tax')
                ->selectRaw('tsl.line_discount_type')
                ->selectRaw('tsl.line_discount_amount')
                ->selectRaw('(tsl.quantity * COALESCE(tsl.unit_price_inc_tax, tsl.unit_price, 0)) as line_total')
                ->get()
                ->map(function ($r) use ($precision) {
                    return [
                        'line_id' => (int) $r->line_id,
                        'product_name' => (string) $r->product_name,
                        'variation_name' => (string) ($r->variation_name ?? ''),
                        'quantity' => $this->roundQuantity((float) $r->quantity),
                        'quantity_selling_uom' => $this->roundQuantity((float) $r->quantity_selling_uom),
                        'unit_price' => round((float) $r->unit_price, $precision),
                        'unit_price_inc_tax' => $r->unit_price_inc_tax !== null ? round((float) $r->unit_price_inc_tax, $precision) : null,
                        'line_discount_type' => $r->line_discount_type,
                        'line_discount_amount' => round((float) ($r->line_discount_amount ?? 0), $precision),
                        'line_total' => round((float) $r->line_total, $precision),
                    ];
                })->values()->all();
        } elseif ($trow->type === 'purchase') {
            $lineLimit = (int) config('aibusinessmanager.tool_transaction_line_limit', 80);
            $lines = DB::table('purchase_lines as pl')
                ->join('products as p', 'p.id', '=', 'pl.product_id')
                ->leftJoin('variations as v', 'v.id', '=', 'pl.variation_id')
                ->where('pl.transaction_id', $trow->id)
                ->orderBy('pl.id')
                ->limit($lineLimit)
                ->selectRaw('pl.id as line_id')
                ->selectRaw('p.name as product_name')
                ->selectRaw('v.name as variation_name')
                ->selectRaw('pl.quantity')
                ->selectRaw('pl.purchase_price')
                ->selectRaw('(pl.quantity * COALESCE(pl.purchase_price, 0)) as line_total')
                ->get()
                ->map(function ($r) use ($precision) {
                    return [
                        'line_id' => (int) $r->line_id,
                        'product_name' => (string) $r->product_name,
                        'variation_name' => (string) ($r->variation_name ?? ''),
                        'quantity' => $this->roundQuantity((float) $r->quantity),
                        'purchase_price' => round((float) $r->purchase_price, $precision),
                        'line_total' => round((float) $r->line_total, $precision),
                    ];
                })->values()->all();
        }

        return [
            'ok' => true,
            'transaction' => [
                'id' => (int) $trow->id,
                'type' => (string) $trow->type,
                'status' => (string) $trow->status,
                'payment_status' => (string) ($trow->payment_status ?? ''),
                'transaction_date' => (string) $trow->transaction_date,
                'contact_id' => $trow->contact_id !== null ? (int) $trow->contact_id : null,
                'contact_name' => $contact_name !== null ? (string) $contact_name : null,
                'location_id' => $trow->location_id !== null ? (int) $trow->location_id : null,
                'location_name' => $location_name !== null ? (string) $location_name : null,
                'invoice_no' => $trow->invoice_no !== null ? (string) $trow->invoice_no : null,
                'ref_no' => $trow->ref_no !== null ? (string) $trow->ref_no : null,
                'final_total' => round((float) $trow->final_total, $precision),
                'total_before_tax' => round((float) ($trow->total_before_tax ?? 0), $precision),
                'tax_amount' => round((float) ($trow->tax_amount ?? 0), $precision),
                'discount_amount' => round((float) ($trow->discount_amount ?? 0), $precision),
                'shipping_charges' => round((float) ($trow->shipping_charges ?? 0), $precision),
                'created_by_user' => $creator !== null ? (string) $creator : null,
            ],
            'currency_symbol' => $symbol,
            'payments' => $payments,
            'lines' => $lines,
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected function salesByHourWeekday(array $args, int $businessId, User $user): array
    {
        $range = $this->parseDateRange($args, $businessId, $user);
        if (isset($range['ok']) && $range['ok'] === false) {
            return $range;
        }
        $start = $range['start'];
        $end = $range['end'];
        $location_ids = $range['location_ids'];
        if (isset($args['location_id']) && is_numeric($args['location_id'])) {
            $lid = (int) $args['location_id'];
            $permitted = $user->permitted_locations();
            if ($permitted === 'all' || (is_array($permitted) && in_array($lid, $permitted, true))) {
                $location_ids = [$lid];
            }
        }

        $precision = $range['precision'];
        $symbol = $range['symbol'];

        $rows = DB::table('transactions as t')
            ->where('t.business_id', $businessId)
            ->where('t.type', 'sell')
            ->where('t.status', 'final')
            ->whereBetween('t.transaction_date', [$start, $end])
            ->when($location_ids !== null, fn ($q) => $q->whereIn('t.location_id', $location_ids))
            ->groupBy(DB::raw('HOUR(t.transaction_date)'), DB::raw('DAYOFWEEK(t.transaction_date)'), DB::raw('DAYNAME(t.transaction_date)'))
            ->orderBy(DB::raw('DAYOFWEEK(t.transaction_date)'))
            ->orderBy(DB::raw('HOUR(t.transaction_date)'))
            ->selectRaw('HOUR(t.transaction_date) as hour_of_day')
            ->selectRaw('DAYOFWEEK(t.transaction_date) as day_of_week')
            ->selectRaw('DAYNAME(t.transaction_date) as day_name')
            ->selectRaw('COUNT(*) as invoices')
            ->selectRaw('SUM(t.final_total) as revenue')
            ->limit(200)
            ->get();

        $ranking = $this->salesByWeekday($args, $businessId, $user);

        return [
            'ok' => true,
            'currency_symbol' => $symbol,
            'note' => 'Hour rows show when sales happen. For which weekday earns the most, use weekday_ranking (already sorted by revenue). Do not add the hour rows up yourself or rename the days.',
            'weekday_ranking' => ($ranking['ok'] ?? false) ? $ranking['rows'] : [],
            'rows' => $rows->map(fn ($r) => [
                'hour_of_day' => (int) $r->hour_of_day,
                'day_of_week' => (int) $r->day_of_week,
                'day_name' => (string) $r->day_name,
                'invoices' => (int) $r->invoices,
                'revenue' => round((float) $r->revenue, $precision),
            ])->values()->all(),
        ];
    }

    /**
     * Final sells ranked by weekday revenue. Day names come from MySQL so the model cannot relabel them.
     *
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected function salesByWeekday(array $args, int $businessId, User $user): array
    {
        $range = $this->parseDateRange($args, $businessId, $user);
        if (isset($range['ok']) && $range['ok'] === false) {
            return $range;
        }
        $start = $range['start'];
        $end = $range['end'];
        $location_ids = $range['location_ids'];
        if (isset($args['location_id']) && is_numeric($args['location_id'])) {
            $lid = (int) $args['location_id'];
            $permitted = $user->permitted_locations();
            if ($permitted === 'all' || (is_array($permitted) && in_array($lid, $permitted, true))) {
                $location_ids = [$lid];
            }
        }

        $precision = $range['precision'];
        $symbol = $range['symbol'];

        $totals = DB::table('transactions as t')
            ->where('t.business_id', $businessId)
            ->where('t.type', 'sell')
            ->where('t.status', 'final')
            ->whereBetween('t.transaction_date', [$start, $end])
            ->when($location_ids !== null, fn ($q) => $q->whereIn('t.location_id', $location_ids))
            ->groupBy(DB::raw('DAYOFWEEK(t.transaction_date)'), DB::raw('DAYNAME(t.transaction_date)'))
            ->orderByDesc(DB::raw('SUM(t.final_total)'))
            ->selectRaw('DAYOFWEEK(t.transaction_date) as day_of_week')
            ->selectRaw('DAYNAME(t.transaction_date) as day_name')
            ->selectRaw('COUNT(*) as invoices')
            ->selectRaw('SUM(t.final_total) as revenue')
            ->get();

        $qtyByDay = $this->sellLinesInRangeQuery($businessId, $location_ids, $start, $end)
            ->groupBy(DB::raw('DAYOFWEEK(t.transaction_date)'))
            ->selectRaw('DAYOFWEEK(t.transaction_date) as day_of_week')
            ->selectRaw('SUM('.$this->qtySellingUomSql().') as quantity_selling_uom')
            ->pluck('quantity_selling_uom', 'day_of_week');

        $peakHours = DB::table('transactions as t')
            ->where('t.business_id', $businessId)
            ->where('t.type', 'sell')
            ->where('t.status', 'final')
            ->whereBetween('t.transaction_date', [$start, $end])
            ->when($location_ids !== null, fn ($q) => $q->whereIn('t.location_id', $location_ids))
            ->groupBy(DB::raw('DAYOFWEEK(t.transaction_date)'), DB::raw('HOUR(t.transaction_date)'))
            ->selectRaw('DAYOFWEEK(t.transaction_date) as day_of_week')
            ->selectRaw('HOUR(t.transaction_date) as hour_of_day')
            ->selectRaw('SUM(t.final_total) as revenue')
            ->get()
            ->groupBy('day_of_week')
            ->map(function ($hours) {
                $best = $hours->sortByDesc(fn ($h) => (float) $h->revenue)->first();

                return $best ? (int) $best->hour_of_day : null;
            });

        $totalRevenue = (float) $totals->sum(fn ($r) => (float) $r->revenue);
        $totalInvoices = (int) $totals->sum(fn ($r) => (int) $r->invoices);

        $rows = [];
        $rank = 1;
        foreach ($totals as $r) {
            $revenue = round((float) $r->revenue, $precision);
            $rows[] = [
                'rank' => $rank,
                'day_name' => (string) $r->day_name,
                'day_of_week' => (int) $r->day_of_week,
                'revenue' => $revenue,
                'invoices' => (int) $r->invoices,
                'quantity_selling_uom' => $this->roundQuantity((float) ($qtyByDay[(int) $r->day_of_week] ?? 0)),
                'share_of_revenue' => $totalRevenue > 0 ? round($revenue / $totalRevenue, 4) : 0.0,
                'busiest_hour' => $peakHours[(int) $r->day_of_week] ?? null,
            ];
            $rank++;
        }

        return [
            'ok' => true,
            'currency_symbol' => $symbol,
            'start' => $start->toDateTimeString(),
            'end' => $end->toDateTimeString(),
            'total_revenue' => round($totalRevenue, $precision),
            'total_invoices' => $totalInvoices,
            'note' => 'rows are already ranked by revenue, highest first. day_name is from the database. Present this order. busiest_hour is the clock hour with the most revenue on that weekday (0–23) and must not change the rank.',
            'rows' => $rows,
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected function basketMetrics(array $args, int $businessId, User $user): array
    {
        $range = $this->parseDateRange($args, $businessId, $user);
        if (isset($range['ok']) && $range['ok'] === false) {
            return $range;
        }
        $start = $range['start'];
        $end = $range['end'];
        $location_ids = $range['location_ids'];
        if (isset($args['location_id']) && is_numeric($args['location_id'])) {
            $lid = (int) $args['location_id'];
            $permitted = $user->permitted_locations();
            if ($permitted === 'all' || (is_array($permitted) && in_array($lid, $permitted, true))) {
                $location_ids = [$lid];
            }
        }

        $precision = $range['precision'];
        $symbol = $range['symbol'];

        $granularity = (string) ($args['granularity'] ?? 'range');
        if (! in_array($granularity, ['range', 'day'], true)) {
            $granularity = 'range';
        }

        if ($granularity === 'day') {
            $rows = DB::table('transactions as t')
                ->where('t.business_id', $businessId)
                ->where('t.type', 'sell')
                ->where('t.status', 'final')
                ->whereBetween('t.transaction_date', [$start, $end])
                ->when($location_ids !== null, fn ($q) => $q->whereIn('t.location_id', $location_ids))
                ->groupBy(DB::raw('DATE(t.transaction_date)'))
                ->orderBy(DB::raw('DATE(t.transaction_date)'))
                ->limit(400)
                ->selectRaw('DATE(t.transaction_date) as day')
                ->selectRaw('COUNT(*) as invoices')
                ->selectRaw('SUM(t.final_total) as revenue')
                ->get();

            return [
                'ok' => true,
                'granularity' => 'day',
                'currency_symbol' => $symbol,
                'rows' => $rows->map(function ($r) use ($precision) {
                    $inv = (int) $r->invoices;

                    return [
                        'day' => (string) $r->day,
                        'invoices' => $inv,
                        'revenue' => round((float) $r->revenue, $precision),
                        'avg_ticket' => $inv > 0 ? round((float) $r->revenue / $inv, $precision) : 0.0,
                    ];
                })->values()->all(),
            ];
        }

        $agg = DB::table('transactions as t')
            ->where('t.business_id', $businessId)
            ->where('t.type', 'sell')
            ->where('t.status', 'final')
            ->whereBetween('t.transaction_date', [$start, $end])
            ->when($location_ids !== null, fn ($q) => $q->whereIn('t.location_id', $location_ids))
            ->selectRaw('COUNT(*) as invoices')
            ->selectRaw('SUM(t.final_total) as revenue')
            ->first();

        $lineAgg = DB::table('transaction_sell_lines as tsl')
            ->join('transactions as t', 't.id', '=', 'tsl.transaction_id')
            ->where('t.business_id', $businessId)
            ->where('t.type', 'sell')
            ->where('t.status', 'final')
            ->whereBetween('t.transaction_date', [$start, $end])
            ->whereNull('tsl.parent_sell_line_id')
            ->when($location_ids !== null, fn ($q) => $q->whereIn('t.location_id', $location_ids))
            ->selectRaw('COUNT(*) as main_lines')
            ->first();

        $inv = (int) ($agg->invoices ?? 0);
        $rev = (float) ($agg->revenue ?? 0);
        $lines = (int) ($lineAgg->main_lines ?? 0);

        return [
            'ok' => true,
            'granularity' => 'range',
            'currency_symbol' => $symbol,
            'invoices' => $inv,
            'revenue' => round($rev, $precision),
            'avg_ticket' => $inv > 0 ? round($rev / $inv, $precision) : 0.0,
            'main_sell_lines' => $lines,
            'avg_lines_per_invoice' => $inv > 0 ? round($lines / $inv, 4) : 0.0,
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected function salesByCashier(array $args, int $businessId, User $user): array
    {
        if (config('aibusinessmanager.sales_by_cashier_requires_permission', true)
            && ! $user->can('sell.view')
            && ! $user->can('direct_sell.access')
            && ! $user->can('direct_sell.view')
            && ! $user->can('view_own_sell_only')
            && ! $user->can('sell.create')) {
            return ['ok' => false, 'error' => 'permission_denied'];
        }

        $range = $this->parseDateRange($args, $businessId, $user);
        if (isset($range['ok']) && $range['ok'] === false) {
            return $range;
        }
        $start = $range['start'];
        $end = $range['end'];
        $location_ids = $range['location_ids'];
        if (isset($args['location_id']) && is_numeric($args['location_id'])) {
            $lid = (int) $args['location_id'];
            $permitted = $user->permitted_locations();
            if ($permitted === 'all' || (is_array($permitted) && in_array($lid, $permitted, true))) {
                $location_ids = [$lid];
            }
        }

        $precision = $range['precision'];
        $symbol = $range['symbol'];

        $limit = isset($args['limit']) ? (int) $args['limit'] : 20;
        $limit = max(1, min((int) config('aibusinessmanager.tool_cashier_limit', 30), $limit));

        $q = DB::table('transactions as t')
            ->join('users as u', 'u.id', '=', 't.created_by')
            ->where('t.business_id', $businessId)
            ->where('t.type', 'sell')
            ->where('t.status', 'final')
            ->whereBetween('t.transaction_date', [$start, $end])
            ->when($location_ids !== null, fn ($qq) => $qq->whereIn('t.location_id', $location_ids));

        if ($user->can('view_own_sell_only') && ! $user->can('sell.view')) {
            $q->where('t.created_by', $user->id);
        }

        $rows = $q->groupBy('t.created_by', 'u.username', 'u.first_name', 'u.last_name')
            ->orderByDesc(DB::raw('SUM(t.final_total)'))
            ->limit($limit)
            ->selectRaw('t.created_by as user_id')
            ->selectRaw('COALESCE(NULLIF(TRIM(u.username), ""), TRIM(CONCAT(COALESCE(u.first_name,"")," ",COALESCE(u.last_name,""))), CONCAT("User #", u.id)) as user_label')
            ->selectRaw('COUNT(*) as invoices')
            ->selectRaw('SUM(t.final_total) as revenue')
            ->get();

        return [
            'ok' => true,
            'currency_symbol' => $symbol,
            'rows' => $rows->map(fn ($r) => [
                'user_id' => (int) $r->user_id,
                'user_label' => (string) $r->user_label,
                'invoices' => (int) $r->invoices,
                'revenue' => round((float) $r->revenue, $precision),
            ])->values()->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected function discountSummary(array $args, int $businessId, User $user): array
    {
        if (! Schema::hasColumn('transaction_sell_lines', 'line_discount_amount')) {
            return ['ok' => false, 'error' => 'unsupported', 'note' => 'Line discount columns not present.'];
        }

        $range = $this->parseDateRange($args, $businessId, $user);
        if (isset($range['ok']) && $range['ok'] === false) {
            return $range;
        }
        $start = $range['start'];
        $end = $range['end'];
        $location_ids = $range['location_ids'];
        if (isset($args['location_id']) && is_numeric($args['location_id'])) {
            $lid = (int) $args['location_id'];
            $permitted = $user->permitted_locations();
            if ($permitted === 'all' || (is_array($permitted) && in_array($lid, $permitted, true))) {
                $location_ids = [$lid];
            }
        }

        $precision = $range['precision'];
        $symbol = $range['symbol'];

        $lineDisc = DB::table('transaction_sell_lines as tsl')
            ->join('transactions as t', 't.id', '=', 'tsl.transaction_id')
            ->where('t.business_id', $businessId)
            ->where('t.type', 'sell')
            ->where('t.status', 'final')
            ->whereBetween('t.transaction_date', [$start, $end])
            ->whereNull('tsl.parent_sell_line_id')
            ->when($location_ids !== null, fn ($q) => $q->whereIn('t.location_id', $location_ids))
            ->selectRaw('SUM(COALESCE(tsl.line_discount_amount, 0)) as line_discount_amount_sum')
            ->selectRaw('COUNT(CASE WHEN COALESCE(tsl.line_discount_amount, 0) > 0 THEN 1 END) as lines_with_line_discount')
            ->first();

        $txnDisc = DB::table('transactions as t')
            ->where('t.business_id', $businessId)
            ->where('t.type', 'sell')
            ->where('t.status', 'final')
            ->whereBetween('t.transaction_date', [$start, $end])
            ->when($location_ids !== null, fn ($q) => $q->whereIn('t.location_id', $location_ids))
            ->selectRaw('SUM(COALESCE(t.discount_amount, 0)) as transaction_discount_sum')
            ->first();

        return [
            'ok' => true,
            'currency_symbol' => $symbol,
            'line_discount_amount_sum' => round((float) ($lineDisc->line_discount_amount_sum ?? 0), $precision),
            'lines_with_explicit_line_discount' => (int) ($lineDisc->lines_with_line_discount ?? 0),
            'transaction_level_discount_sum' => round((float) ($txnDisc->transaction_discount_sum ?? 0), $precision),
            'caveat' => 'Sums raw line_discount_amount and transaction discount_amount fields; reconcile with transaction_detail for a specific invoice if needed.',
        ];
    }
}
