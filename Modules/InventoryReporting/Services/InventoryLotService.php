<?php

namespace Modules\InventoryReporting\Services;

use App\PurchaseLine;
use DB;
use Illuminate\Validation\ValidationException;

class InventoryLotService
{
    /**
     * Update lot number and expiry only for quantity still on hand (does not affect historical sales).
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function updateLotForPurchaseLine(
        int $businessId,
        int $purchaseLineId,
        ?string $lotNumber,
        ?string $expDateYmd,
        int $locationId
    ): void {
        $pl = PurchaseLine::query()
            ->join('transactions as T', 'purchase_lines.transaction_id', '=', 'T.id')
            ->where('T.business_id', $businessId)
            ->where('T.location_id', $locationId)
            ->where('purchase_lines.id', $purchaseLineId)
            ->select('purchase_lines.*')
            ->first();

        if (! $pl) {
            throw ValidationException::withMessages(['purchase_line' => __('inventoryreporting::lang.lot_not_found')]);
        }

        $used = (float) $pl->quantity_sold + (float) $pl->quantity_adjusted
            + (float) $pl->quantity_returned + (float) $pl->mfg_quantity_used;
        $remaining = (float) $pl->quantity - $used;

        if ($remaining <= 0) {
            throw ValidationException::withMessages(['purchase_line' => __('inventoryreporting::lang.lot_no_remaining')]);
        }

        PurchaseLine::where('id', $purchaseLineId)->update([
            'lot_number' => $lotNumber,
            'exp_date' => $expDateYmd,
        ]);
    }

    /**
     * Datatable query: purchase lines with remaining qty at location.
     */
    public function queryLotsForLocation(int $businessId, int $locationId)
    {
        $qtyExpr = 'PL.quantity - PL.quantity_sold - PL.quantity_adjusted - PL.quantity_returned - PL.mfg_quantity_used';

        return PurchaseLine::query()
            ->from('purchase_lines as PL')
            ->join('transactions as T', 'PL.transaction_id', '=', 'T.id')
            ->join('products as P', 'PL.product_id', '=', 'P.id')
            ->join('variations as V', 'PL.variation_id', '=', 'V.id')
            ->where('T.business_id', $businessId)
            ->where('T.location_id', $locationId)
            ->whereIn('T.type', ['purchase', 'opening_stock', 'purchase_transfer', 'production_purchase'])
            ->where('T.status', 'received')
            ->whereRaw("$qtyExpr > 0")
            ->select([
                'PL.id',
                'PL.lot_number',
                'PL.exp_date',
                'PL.quantity',
                'PL.quantity_sold',
                'PL.quantity_adjusted',
                'PL.quantity_returned',
                'PL.mfg_quantity_used',
                DB::raw("$qtyExpr as qty_remaining"),
                'P.name as product_name',
                'V.sub_sku',
                'T.transaction_date as transaction_date',
                'T.ref_no',
            ]);
    }
}
