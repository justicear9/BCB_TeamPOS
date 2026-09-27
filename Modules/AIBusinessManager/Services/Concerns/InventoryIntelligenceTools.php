<?php

namespace Modules\AIBusinessManager\Services\Concerns;

use App\Business;
use App\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

trait InventoryIntelligenceTools
{
    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected function slowMovingStock(array $args, int $businessId, User $user): array
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

        $maxSell = isset($args['max_sell_qty_in_range']) ? (float) $args['max_sell_qty_in_range'] : (float) config('aibusinessmanager.slow_moving_max_sell_qty', 3);
        $minOnHand = isset($args['min_on_hand']) ? (float) $args['min_on_hand'] : 0.01;

        $limit = isset($args['limit']) ? (int) $args['limit'] : 30;
        $limit = max(1, min((int) config('aibusinessmanager.tool_slow_stock_limit', 40), $limit));

        $qtySql = $this->qtySellingUomSql();

        $onHand = DB::table('variation_location_details as vld')
            ->join('products as p', 'p.id', '=', 'vld.product_id')
            ->join('variations as v', 'v.id', '=', 'vld.variation_id')
            ->where('p.business_id', $businessId)
            ->where('p.enable_stock', 1)
            ->where('p.is_inactive', 0)
            ->whereNull('v.deleted_at')
            ->when($location_ids !== null, fn ($q) => $q->whereIn('vld.location_id', $location_ids))
            ->selectRaw('v.id as variation_id')
            ->selectRaw('MAX(p.name) as product_name')
            ->selectRaw('MAX(v.name) as variation_name')
            ->selectRaw('MAX(v.sub_sku) as sub_sku')
            ->selectRaw('SUM(vld.qty_available) as qty_on_hand')
            ->groupBy('v.id')
            ->havingRaw('SUM(vld.qty_available) >= ?', [$minOnHand]);

        $sold = DB::table('transaction_sell_lines as tsl')
            ->join('transactions as t', 't.id', '=', 'tsl.transaction_id')
            ->leftJoin('units as sell_unit', 'sell_unit.id', '=', 'tsl.sub_unit_id')
            ->where('t.business_id', $businessId)
            ->where('t.type', 'sell')
            ->where('t.status', 'final')
            ->whereBetween('t.transaction_date', [$start, $end])
            ->whereNull('tsl.parent_sell_line_id')
            ->when($location_ids !== null, fn ($q) => $q->whereIn('t.location_id', $location_ids))
            ->groupBy('tsl.variation_id')
            ->selectRaw('tsl.variation_id as variation_id')
            ->selectRaw('SUM('.$qtySql.') as qty_sold_uom');

        $rows = DB::query()
            ->fromSub($onHand, 'oh')
            ->leftJoinSub($sold, 's', 's.variation_id', '=', 'oh.variation_id')
            ->whereRaw('COALESCE(s.qty_sold_uom, 0) <= ?', [$maxSell])
            ->orderByRaw('COALESCE(s.qty_sold_uom, 0) ASC')
            ->orderByDesc('oh.qty_on_hand')
            ->limit($limit)
            ->select('oh.*', DB::raw('COALESCE(s.qty_sold_uom, 0) as qty_sold_uom'))
            ->get();

        return [
            'ok' => true,
            'max_sell_qty_in_range' => $maxSell,
            'rows' => $rows->map(fn ($r) => [
                'variation_id' => (int) $r->variation_id,
                'product_name' => (string) $r->product_name,
                'variation_name' => (string) $r->variation_name,
                'sub_sku' => (string) ($r->sub_sku ?? ''),
                'qty_on_hand' => $this->roundQuantity((float) $r->qty_on_hand),
                'quantity_selling_uom_in_range' => $this->roundQuantity((float) $r->qty_sold_uom),
            ])->values()->all(),
            'caveat' => 'Slow movers use invoice-UoM sell quantities in the window vs current on-hand; tune max_sell_qty_in_range for your catalog.',
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected function productMarginSnapshot(array $args, int $businessId, User $user): array
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

        $limit = isset($args['limit']) ? (int) $args['limit'] : 25;
        $limit = max(1, min((int) config('aibusinessmanager.tool_margin_snapshot_limit', 35), $limit));

        $rows = DB::table('transaction_sell_lines as tsl')
            ->join('transactions as t', 't.id', '=', 'tsl.transaction_id')
            ->join('products as p', 'p.id', '=', 'tsl.product_id')
            ->join('variations as v', 'v.id', '=', 'tsl.variation_id')
            ->whereNull('tsl.parent_sell_line_id')
            ->where('t.business_id', $businessId)
            ->where('t.type', 'sell')
            ->where('t.status', 'final')
            ->whereBetween('t.transaction_date', [$start, $end])
            ->when($location_ids !== null, fn ($q) => $q->whereIn('t.location_id', $location_ids))
            ->groupBy('p.id', 'p.name')
            ->orderByDesc(DB::raw('SUM(tsl.quantity * COALESCE(tsl.unit_price_inc_tax, tsl.unit_price, 0))'))
            ->limit($limit)
            ->selectRaw('p.id as product_id')
            ->selectRaw('MAX(p.name) as product_name')
            ->selectRaw('SUM(tsl.quantity * COALESCE(tsl.unit_price_inc_tax, tsl.unit_price, 0)) as revenue')
            ->selectRaw('SUM(tsl.quantity * COALESCE(v.default_purchase_price, 0)) as est_cost_at_variation_purchase_price')
            ->get();

        return [
            'ok' => true,
            'currency_symbol' => $symbol,
            'rows' => $rows->map(function ($r) use ($precision) {
                $rev = (float) $r->revenue;
                $cost = (float) $r->est_cost_at_variation_purchase_price;
                $gp = $rev - $cost;
                $margin = $rev > 0.00001 ? ($gp / $rev) * 100 : 0.0;

                return [
                    'product_id' => (int) $r->product_id,
                    'product_name' => (string) $r->product_name,
                    'revenue' => round($rev, $precision),
                    'est_cost_at_variation_purchase_price' => round($cost, $precision),
                    'est_gross_profit' => round($gp, $precision),
                    'est_gross_margin_pct_of_revenue' => round($margin, $precision),
                ];
            })->values()->all(),
            'caveat' => 'Cost uses variation default_purchase_price × sell-line base quantity — not FIFO landed cost. Compare to Stock Report / P&L when precision matters.',
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected function lotSellTrace(array $args, int $businessId, User $user): array
    {
        if (! Schema::hasTable('transaction_sell_lines_purchase_lines')) {
            return ['ok' => false, 'error' => 'unsupported'];
        }

        $purchase_line_id = isset($args['purchase_line_id']) ? (int) $args['purchase_line_id'] : 0;
        $lot_number = isset($args['lot_number']) ? trim((string) $args['lot_number']) : '';

        if ($purchase_line_id <= 0 && $lot_number === '') {
            return ['ok' => false, 'error' => 'missing_purchase_line_id_or_lot_number'];
        }

        $plQuery = DB::table('purchase_lines as pl')
            ->join('transactions as pt', 'pt.id', '=', 'pl.transaction_id')
            ->where('pt.business_id', $businessId)
            ->where('pt.type', 'purchase')
            ->when($purchase_line_id > 0, fn ($q) => $q->where('pl.id', $purchase_line_id))
            ->when($purchase_line_id <= 0 && $lot_number !== '', fn ($q) => $q->where('pl.lot_number', $lot_number));

        $pl = $plQuery->select('pl.id', 'pl.lot_number', 'pl.quantity as purchase_qty', 'pl.transaction_id as purchase_transaction_id', 'pt.ref_no as purchase_ref')->first();

        if (! $pl) {
            return ['ok' => false, 'error' => 'purchase_line_not_found'];
        }

        $limit = isset($args['limit']) ? (int) $args['limit'] : 50;
        $limit = max(1, min(80, $limit));

        $rows = DB::table('transaction_sell_lines_purchase_lines as tspl')
            ->join('transaction_sell_lines as tsl', 'tsl.id', '=', 'tspl.sell_line_id')
            ->join('transactions as st', 'st.id', '=', 'tsl.transaction_id')
            ->join('products as p', 'p.id', '=', 'tsl.product_id')
            ->where('tspl.purchase_line_id', $pl->id)
            ->where('st.business_id', $businessId)
            ->orderByDesc('st.transaction_date')
            ->limit($limit)
            ->selectRaw('st.id as sell_transaction_id')
            ->selectRaw('st.invoice_no')
            ->selectRaw('st.ref_no as sell_ref')
            ->selectRaw('DATE(st.transaction_date) as sell_date')
            ->selectRaw('p.name as product_name')
            ->selectRaw('tspl.quantity as qty_allocated')
            ->get();

        return [
            'ok' => true,
            'purchase_line' => [
                'purchase_line_id' => (int) $pl->id,
                'lot_number' => $pl->lot_number !== null ? (string) $pl->lot_number : null,
                'purchase_transaction_id' => (int) $pl->purchase_transaction_id,
                'purchase_ref' => (string) ($pl->purchase_ref ?? ''),
            ],
            'sell_allocations' => $rows->map(fn ($r) => [
                'sell_transaction_id' => (int) $r->sell_transaction_id,
                'invoice_no' => $r->invoice_no !== null ? (string) $r->invoice_no : null,
                'sell_ref' => (string) ($r->sell_ref ?? ''),
                'sell_date' => (string) $r->sell_date,
                'product_name' => (string) $r->product_name,
                'qty_allocated' => $this->roundQuantity((float) $r->qty_allocated),
            ])->values()->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected function reorderCoverHint(array $args, int $businessId, User $user): array
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

        $product_id = isset($args['product_id']) ? (int) $args['product_id'] : 0;
        $name_query = isset($args['name_query']) ? trim((string) $args['name_query']) : '';

        if ($product_id <= 0 && $name_query === '') {
            return ['ok' => false, 'error' => 'missing_product_id_or_name_query'];
        }

        if ($product_id <= 0) {
            $like = '%'.addcslashes($name_query, '%_\\').'%';
            $match = DB::table('products as p')
                ->where('p.business_id', $businessId)
                ->where(function ($w) use ($like) {
                    $w->where('p.name', 'like', $like)->orWhere('p.sku', 'like', $like);
                })
                ->orderBy('p.name')
                ->value('p.id');
            if (! $match) {
                return ['ok' => false, 'error' => 'product_not_found'];
            }
            $product_id = (int) $match;
        }

        $pname = DB::table('products')->where('business_id', $businessId)->where('id', $product_id)->value('name');

        $qtySql = $this->qtySellingUomSql();
        $sold = (float) DB::table('transaction_sell_lines as tsl')
            ->join('transactions as t', 't.id', '=', 'tsl.transaction_id')
            ->leftJoin('units as sell_unit', 'sell_unit.id', '=', 'tsl.sub_unit_id')
            ->whereNull('tsl.parent_sell_line_id')
            ->where('t.business_id', $businessId)
            ->where('t.type', 'sell')
            ->where('t.status', 'final')
            ->whereBetween('t.transaction_date', [$start, $end])
            ->where('tsl.product_id', $product_id)
            ->when($location_ids !== null, fn ($q) => $q->whereIn('t.location_id', $location_ids))
            ->selectRaw('SUM('.$qtySql.') as q')
            ->value('q');

        $days = max(1, $start->copy()->startOfDay()->diffInDays($end->copy()->startOfDay()) + 1);
        $avg_daily = $sold / $days;

        $on_hand = (float) DB::table('variation_location_details as vld')
            ->join('variations as v', 'v.id', '=', 'vld.variation_id')
            ->where('v.product_id', $product_id)
            ->whereNull('v.deleted_at')
            ->when($location_ids !== null, fn ($q) => $q->whereIn('vld.location_id', $location_ids))
            ->sum('vld.qty_available');

        $cover = $avg_daily > 0.00001 ? $on_hand / $avg_daily : null;

        return [
            'ok' => true,
            'product_id' => $product_id,
            'product_name' => $pname !== null ? (string) $pname : '',
            'on_hand_qty_available' => $this->roundQuantity($on_hand),
            'quantity_selling_uom_sold_in_range' => $this->roundQuantity($sold),
            'range_days' => $days,
            'avg_daily_selling_uom' => $this->roundQuantity($avg_daily),
            'cover_days_at_avg_daily' => $cover !== null ? round($cover, 2) : null,
            'low_confidence' => $sold < (float) config('aibusinessmanager.reorder_cover_min_sold_for_confidence', 5),
            'caveat' => 'Heuristic only: uses average daily invoice UoM qty over the window and current qty_available; ignores lead times, MOQ, and seasonality.',
        ];
    }
}
