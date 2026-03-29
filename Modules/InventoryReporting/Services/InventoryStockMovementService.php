<?php

namespace Modules\InventoryReporting\Services;

use App\Events\StockAdjustmentCreatedOrModified;
use App\Product;
use App\PurchaseLine;
use App\Transaction;
use App\Utils\ProductUtil;
use App\Utils\TransactionUtil;
use App\Utils\Util;
use DB;
use Illuminate\Support\Facades\Log;

/**
 * Stock reset and signed adjustments using core Ultimate POS primitives (transactions + mapPurchaseSell).
 */
class InventoryStockMovementService
{
    public function __construct(
        protected ProductUtil $productUtil,
        protected TransactionUtil $transactionUtil,
        protected Util $util
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

    /**
     * Negative qty = increase stock via opening_stock; positive qty = decrease via stock_adjustment.
     *
     * @return array{success: bool, msg: string, transaction_id?: int}
     */
    public function signedAdjustment(
        int $businessId,
        int $locationId,
        int $userId,
        string $transactionDate,
        string $accountingMethod,
        int $productId,
        int $variationId,
        float $signedQty,
        float $unitPrice,
        ?int $lotNoLineId,
        string $note
    ): array {
        if (abs($signedQty) < 0.0001) {
            return ['success' => false, 'msg' => __('messages.something_went_wrong')];
        }

        try {
            return DB::transaction(function () use (
                $businessId,
                $locationId,
                $userId,
                $transactionDate,
                $accountingMethod,
                $productId,
                $variationId,
                $signedQty,
                $unitPrice,
                $lotNoLineId,
                $note
            ) {
                if ($signedQty > 0) {
                    return $this->createDecreaseAdjustment(
                        $businessId,
                        $locationId,
                        $userId,
                        $transactionDate,
                        $accountingMethod,
                        $productId,
                        $variationId,
                        $signedQty,
                        $unitPrice,
                        $lotNoLineId,
                        $note
                    );
                }

                $qty = abs($signedQty);

                return $this->createIncreaseOpeningStock(
                    $businessId,
                    $locationId,
                    $userId,
                    $transactionDate,
                    $productId,
                    $variationId,
                    $qty,
                    $unitPrice,
                    $note
                );
            });
        } catch (\Throwable $e) {
            Log::error('InventoryReporting adjustment: '.$e->getMessage(), ['trace' => $e->getTraceAsString()]);

            return ['success' => false, 'msg' => $e->getMessage()];
        }
    }

    /**
     * @return array{success: bool, msg: string, transaction_id?: int}
     */
    protected function createDecreaseAdjustment(
        int $businessId,
        int $locationId,
        int $userId,
        string $transactionDate,
        string $accountingMethod,
        int $productId,
        int $variationId,
        float $quantity,
        float $unitPrice,
        ?int $lotNoLineId,
        string $note
    ): array {
        $refCount = $this->productUtil->setAndGetReferenceCount('stock_adjustment');
        $refNo = $this->productUtil->generateReferenceNumber('stock_adjustment', $refCount);

        $line = [
            'product_id' => $productId,
            'variation_id' => $variationId,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
        ];
        if ($lotNoLineId) {
            $line['lot_no_line_id'] = $lotNoLineId;
        }

        $this->productUtil->decreaseProductQuantity($productId, $variationId, $locationId, $quantity);

        $finalTotal = $quantity * $unitPrice;

        $stockAdjustment = Transaction::create([
            'type' => 'stock_adjustment',
            'business_id' => $businessId,
            'created_by' => $userId,
            'location_id' => $locationId,
            'transaction_date' => $transactionDate,
            'adjustment_type' => 'normal',
            'final_total' => $finalTotal,
            'total_amount_recovered' => 0,
            'ref_no' => $refNo,
            'additional_notes' => $note,
        ]);
        $stockAdjustment->stock_adjustment_lines()->create($line);

        $business = [
            'id' => $businessId,
            'accounting_method' => $accountingMethod,
            'location_id' => $locationId,
        ];
        $this->transactionUtil->mapPurchaseSell($business, $stockAdjustment->stock_adjustment_lines, 'stock_adjustment');

        event(new StockAdjustmentCreatedOrModified($stockAdjustment, 'added'));

        return [
            'success' => true,
            'msg' => __('inventoryreporting::lang.adjustment_success'),
            'transaction_id' => (int) $stockAdjustment->id,
        ];
    }

    /**
     * @return array{success: bool, msg: string, transaction_id?: int}
     */
    protected function createIncreaseOpeningStock(
        int $businessId,
        int $locationId,
        int $userId,
        string $transactionDate,
        int $productId,
        int $variationId,
        float $quantity,
        float $unitPrice,
        string $note
    ): array {
        $product = Product::with(['product_tax'])->findOrFail($productId);
        $taxPercent = $product->product_tax && ! empty($product->product_tax->amount) ? $product->product_tax->amount : 0;
        $taxId = $product->product_tax && ! empty($product->product_tax->id) ? $product->product_tax->id : null;
        $itemTax = $this->productUtil->calc_percentage($unitPrice, $taxPercent);
        $purchasePriceIncTax = $unitPrice + $itemTax;
        $total = $purchasePriceIncTax * $quantity;

        $purchaseLine = new PurchaseLine();
        $purchaseLine->product_id = $productId;
        $purchaseLine->variation_id = $variationId;
        $purchaseLine->item_tax = $itemTax;
        $purchaseLine->tax_id = $taxId;
        $purchaseLine->quantity = $quantity;
        $purchaseLine->pp_without_discount = $unitPrice;
        $purchaseLine->purchase_price = $unitPrice;
        $purchaseLine->purchase_price_inc_tax = $purchasePriceIncTax;

        $this->productUtil->updateProductQuantity(
            $locationId,
            $productId,
            $variationId,
            $this->productUtil->num_f($quantity)
        );

        $transaction = Transaction::create([
            'type' => 'opening_stock',
            'opening_stock_product_id' => $productId,
            'status' => 'received',
            'business_id' => $businessId,
            'transaction_date' => $transactionDate,
            'total_before_tax' => $total,
            'location_id' => $locationId,
            'final_total' => $total,
            'payment_status' => 'paid',
            'created_by' => $userId,
            'additional_notes' => $note,
        ]);
        $transaction->purchase_lines()->save($purchaseLine);

        app(InventoryAccountingService::class)->postStockIncrease($transaction, $userId);

        return [
            'success' => true,
            'msg' => __('inventoryreporting::lang.adjustment_success'),
            'transaction_id' => (int) $transaction->id,
        ];
    }
}
