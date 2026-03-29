<?php

namespace Modules\InventoryReporting\Services;

use DB;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;

/**
 * Reporting queries aligned with core stock valuation (see TransactionUtil::getOpeningClosingStock).
 */
class InventoryReportQueryService
{
    /**
     * Lots still on hand: age since receipt, qty sold from lot, last sale date.
     *
     * @param  array<string, mixed>  $filters
     */
    public function paginatedInventoryAgeing(int $businessId, array $filters, int $perPage = 25): LengthAwarePaginator
    {
        return $this->inventoryAgeingBaseQuery($businessId, $filters)->paginate($perPage);
    }

    /**
     * Full result set for Excel export (same filters as paginated view).
     *
     * @param  array<string, mixed>  $filters
     */
    public function inventoryAgeingCollection(int $businessId, array $filters): Collection
    {
        return $this->inventoryAgeingBaseQuery($businessId, $filters)->get();
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    protected function inventoryAgeingBaseQuery(int $businessId, array $filters): Builder
    {
        $qtyExpr = 'PL.quantity - PL.quantity_sold - PL.quantity_adjusted - PL.quantity_returned - PL.mfg_quantity_used';

        $q = DB::table('purchase_lines as PL')
            ->join('transactions as T', 'PL.transaction_id', '=', 'T.id')
            ->leftJoin('business_locations as BL', 'T.location_id', '=', 'BL.id')
            ->join('products as P', 'PL.product_id', '=', 'P.id')
            ->join('variations as V', 'PL.variation_id', '=', 'V.id')
            ->where('T.business_id', $businessId)
            ->whereIn('T.type', ['purchase', 'opening_stock', 'purchase_transfer', 'production_purchase'])
            ->where('T.status', 'received')
            ->whereRaw("$qtyExpr > 0");

        if (! empty($filters['location_id'])) {
            $q->where('T.location_id', (int) $filters['location_id']);
        }

        if (! empty($filters['search'])) {
            $term = '%'.$filters['search'].'%';
            $q->where(function ($sub) use ($term) {
                $sub->where('P.name', 'like', $term)
                    ->orWhere('V.sub_sku', 'like', $term);
            });
        }

        if (! empty($filters['category_id'])) {
            $q->where('P.category_id', (int) $filters['category_id']);
        }

        if (! empty($filters['brand_id'])) {
            $q->where('P.brand_id', (int) $filters['brand_id']);
        }

        if (! empty($filters['received_from'])) {
            $q->whereDate('T.transaction_date', '>=', $filters['received_from']);
        }

        if (! empty($filters['received_to'])) {
            $q->whereDate('T.transaction_date', '<=', $filters['received_to']);
        }

        if (($filters['min_days'] ?? '') !== '' && is_numeric($filters['min_days'])) {
            $q->whereRaw('DATEDIFF(CURDATE(), DATE(T.transaction_date)) >= ?', [(int) $filters['min_days']]);
        }

        if (($filters['max_days'] ?? '') !== '' && is_numeric($filters['max_days'])) {
            $q->whereRaw('DATEDIFF(CURDATE(), DATE(T.transaction_date)) <= ?', [(int) $filters['max_days']]);
        }

        return $q->select([
            'PL.id as purchase_line_id',
            'PL.lot_number',
            'PL.exp_date',
            'P.name as product_name',
            'V.sub_sku',
            'T.transaction_date as date_received',
            'T.location_id',
            'BL.name as location_name',
            'PL.quantity_sold as qty_sold',
            DB::raw("$qtyExpr as qty_remaining"),
            DB::raw('DATEDIFF(CURDATE(), DATE(T.transaction_date)) as days_in_stock'),
            DB::raw('(
                SELECT MAX(sale.transaction_date)
                FROM transaction_sell_lines_purchase_lines tspl
                INNER JOIN transaction_sell_lines tsl ON tspl.sell_line_id = tsl.id
                INNER JOIN transactions sale ON tsl.transaction_id = sale.id
                WHERE tspl.purchase_line_id = PL.id
            ) as last_sale_date'),
        ])
            ->orderByDesc('T.transaction_date');
    }

    /**
     * Stock on hand as at date (per TransactionUtil::getOpeningClosingStock). Detailed = per lot / purchase line; combined = per variation.
     */
    public function stockAsAtDate(
        int $businessId,
        string $asAtDate,
        ?int $locationId,
        $permittedLocations,
        bool $detailed
    ): Collection {
        $soldUpTo = $this->soldQuantityUpToDateExpr('PL', $asAtDate);
        $qtyExpr = "GREATEST(0, PL.quantity - PL.quantity_returned - PL.quantity_adjusted - ($soldUpTo))";
        $unitCostExpr = '(PL.purchase_price + COALESCE(PL.item_tax, 0))';

        $q = DB::table('purchase_lines as PL')
            ->join('transactions as T', 'PL.transaction_id', '=', 'T.id')
            ->join('products as P', 'PL.product_id', '=', 'P.id')
            ->join('variations as V', 'PL.variation_id', '=', 'V.id')
            ->where('T.business_id', $businessId)
            ->whereIn('T.type', ['purchase', 'opening_stock', 'purchase_transfer', 'production_purchase'])
            ->where('T.status', 'received')
            ->whereRaw('DATE(T.transaction_date) <= ?', [$asAtDate]);

        if ($locationId) {
            $q->where('T.location_id', $locationId);
        } elseif ($permittedLocations !== 'all' && is_array($permittedLocations)) {
            $q->whereIn('T.location_id', $permittedLocations);
        }

        if ($detailed) {
            return $q->leftJoin('business_locations as BL', 'T.location_id', '=', 'BL.id')
                ->select([
                    'P.name as product_name',
                    'V.sub_sku',
                    'PL.id as purchase_line_id',
                    'PL.lot_number',
                    'PL.exp_date',
                    'T.location_id',
                    'BL.name as location_name',
                    'T.ref_no',
                    'T.transaction_date',
                    DB::raw("$qtyExpr as qty_on_hand"),
                    DB::raw("$unitCostExpr as unit_cost"),
                ])
                ->havingRaw('qty_on_hand > 0')
                ->orderBy('P.name')
                ->get();
        }

        return $q->select([
            'P.name as product_name',
            'V.sub_sku',
            'PL.variation_id',
            DB::raw("SUM($qtyExpr) as qty_on_hand"),
            DB::raw("CASE WHEN SUM($qtyExpr) > 0 THEN SUM($qtyExpr * $unitCostExpr) / SUM($qtyExpr) ELSE 0 END as unit_cost"),
        ])
            ->groupBy('PL.variation_id', 'P.name', 'V.sub_sku')
            ->havingRaw('qty_on_hand > 0')
            ->orderBy('P.name')
            ->get();
    }

    protected function soldQuantityUpToDateExpr(string $alias, string $asAtDate): string
    {
        $d = addslashes($asAtDate);

        return "(SELECT COALESCE(SUM(tspl.quantity - tspl.qty_returned), 0)
            FROM transaction_sell_lines_purchase_lines AS tspl
            INNER JOIN transaction_sell_lines AS tsl ON tspl.sell_line_id = tsl.id
            INNER JOIN transactions AS sale ON tsl.transaction_id = sale.id
            WHERE tspl.purchase_line_id = {$alias}.id
            AND DATE(sale.transaction_date) <= '{$d}')";
    }
}
