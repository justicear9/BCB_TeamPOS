<?php

namespace Modules\InventoryReporting\Services;

use App\Events\StockAdjustmentCreatedOrModified;
use App\PurchaseLine;
use App\Transaction;
use App\Utils\ProductUtil;
use App\Utils\TransactionUtil;
use DB;
use Illuminate\Support\Facades\Log;

/**
 * Stock reset using core Ultimate POS primitives (stock_adjustment transaction + mapPurchaseSell).
 */
class InventoryStockMovementService
{
    public function __construct(
        protected ProductUtil $productUtil,
        protected TransactionUtil $transactionUtil,
    ) {}

    /**
     * Zero all on-hand stock at a location using one stock_adjustment transaction (batched lines).
     *
     * @return array{success: bool, msg: string, transaction_id?: int}
     */
    public function stockResetForLocation(
        int $businessId,
        int $locationId,
        int $userId,
        string $transactionDate,
        string $accountingMethod,
        bool $lotOrExpiryEnabled
    ): array {
        $lines = $this->buildStockResetLines($businessId, $locationId, $lotOrExpiryEnabled);
        if ($lines === []) {
            return ['success' => true, 'msg' => __('inventoryreporting::lang.stock_reset_nothing_to_do')];
        }

        try {
            return DB::transaction(function () use ($businessId, $locationId, $userId, $transactionDate, $accountingMethod, $lines) {
                $refCount = $this->productUtil->setAndGetReferenceCount('stock_adjustment');
                $refNo = $this->productUtil->generateReferenceNumber('stock_adjustment', $refCount);

                $productData = [];
                $finalTotal = 0;

                foreach ($lines as $line) {
                    $qty = (float) $line['quantity'];
                    $unitPrice = (float) $line['unit_price'];
                    $finalTotal += $qty * $unitPrice;

                    $row = [
                        'product_id' => $line['product_id'],
                        'variation_id' => $line['variation_id'],
                        'quantity' => $qty,
                        'unit_price' => $unitPrice,
                    ];
                    if (! empty($line['lot_no_line_id'])) {
                        $row['lot_no_line_id'] = $line['lot_no_line_id'];
                    }
                    $productData[] = $row;

                    $this->productUtil->decreaseProductQuantity(
                        $line['product_id'],
                        $line['variation_id'],
                        $locationId,
                        $qty
                    );
                }

                $inputData = [
                    'type' => 'stock_adjustment',
                    'business_id' => $businessId,
                    'created_by' => $userId,
                    'location_id' => $locationId,
                    'transaction_date' => $transactionDate,
                    'adjustment_type' => 'abnormal',
                    'final_total' => $finalTotal,
                    'total_amount_recovered' => 0,
                    'ref_no' => $refNo,
                    'additional_notes' => __('inventoryreporting::lang.stock_reset_note'),
                ];

                $stockAdjustment = Transaction::create($inputData);
                $stockAdjustment->stock_adjustment_lines()->createMany($productData);

                $business = [
                    'id' => $businessId,
                    'accounting_method' => $accountingMethod,
                    'location_id' => $locationId,
                ];
                $this->transactionUtil->mapPurchaseSell($business, $stockAdjustment->stock_adjustment_lines, 'stock_adjustment');

                event(new StockAdjustmentCreatedOrModified($stockAdjustment, 'added'));

                return [
                    'success' => true,
                    'msg' => __('inventoryreporting::lang.stock_reset_success'),
                    'transaction_id' => (int) $stockAdjustment->id,
                ];
            });
        } catch (\Throwable $e) {
            Log::error('InventoryReporting stock reset: '.$e->getMessage(), ['trace' => $e->getTraceAsString()]);

            return ['success' => false, 'msg' => $e->getMessage()];
        }
    }

    /**
     * @return list<array{product_id: int, variation_id: int, quantity: float, unit_price: float, lot_no_line_id?: int}>
     */
    protected function buildStockResetLines(int $businessId, int $locationId, bool $lotOrExpiryEnabled): array
    {
        $lines = [];

        if ($lotOrExpiryEnabled) {
            $qtyExpr = 'PL.quantity - PL.quantity_sold - PL.quantity_adjusted - PL.quantity_returned - PL.mfg_quantity_used';

            $rows = PurchaseLine::query()
                ->from('purchase_lines as PL')
                ->join('transactions as T', 'PL.transaction_id', '=', 'T.id')
                ->where('T.business_id', $businessId)
                ->where('T.location_id', $locationId)
                ->whereIn('T.type', ['purchase', 'opening_stock', 'purchase_transfer', 'production_purchase'])
                ->where('T.status', 'received')
                ->whereRaw("$qtyExpr > 0")
                ->join('products as P', 'PL.product_id', '=', 'P.id')
                ->where('P.enable_stock', 1)
                ->select([
                    'PL.id as purchase_line_id',
                    'PL.product_id',
                    'PL.variation_id',
                    'PL.purchase_price_inc_tax',
                    DB::raw("$qtyExpr as qty_remaining"),
                ])
                ->orderBy('PL.id')
                ->get();

            foreach ($rows as $r) {
                $lines[] = [
                    'product_id' => (int) $r->product_id,
                    'variation_id' => (int) $r->variation_id,
                    'quantity' => (float) $r->qty_remaining,
                    'unit_price' => (float) $r->purchase_price_inc_tax,
                    'lot_no_line_id' => (int) $r->purchase_line_id,
                ];
            }

            return $lines;
        }

        $vldRows = DB::table('variation_location_details as vld')
            ->join('products as p', 'p.id', '=', 'vld.product_id')
            ->where('vld.location_id', $locationId)
            ->where('p.business_id', $businessId)
            ->where('p.enable_stock', 1)
            ->where('vld.qty_available', '>', 0)
            ->select('vld.product_id', 'vld.variation_id', 'vld.qty_available')
            ->get();

        foreach ($vldRows as $row) {
            $unitPrice = $this->resolveUnitPrice((int) $row->variation_id);
            $lines[] = [
                'product_id' => (int) $row->product_id,
                'variation_id' => (int) $row->variation_id,
                'quantity' => (float) $row->qty_available,
                'unit_price' => $unitPrice,
            ];
        }

        return $lines;
    }

    protected function resolveUnitPrice(int $variationId): float
    {
        $last = DB::table('purchase_lines')
            ->where('variation_id', $variationId)
            ->orderByDesc('id')
            ->value('purchase_price_inc_tax');

        if ($last !== null) {
            return (float) $last;
        }

        $d = DB::table('variations')->where('id', $variationId)->value('default_purchase_price');

        return (float) ($d ?? 0);
    }
}
