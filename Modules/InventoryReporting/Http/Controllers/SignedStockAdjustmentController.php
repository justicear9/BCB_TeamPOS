<?php

namespace Modules\InventoryReporting\Http\Controllers;

use App\BusinessLocation;
use App\Events\StockAdjustmentCreatedOrModified;
use App\Transaction;
use App\Utils\ModuleUtil;
use App\Utils\ProductUtil;
use App\Utils\TransactionUtil;
use DB;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Stock adjustment UI aligned with core stock adjustment (decrease only — positive quantities).
 */
class SignedStockAdjustmentController extends Controller
{
    public function __construct(
        protected ProductUtil $productUtil,
        protected TransactionUtil $transactionUtil
    ) {
    }

    public function create()
    {
        if (! auth()->user()->can('inventoryreporting.adjust_stock')) {
            abort(403, 'Unauthorized action.');
        }

        $moduleUtil = app(ModuleUtil::class);
        if (! $moduleUtil->isModuleInstalled('InventoryReporting')) {
            abort(404);
        }

        $business_id = (int) session()->get('user.business_id');
        if (! $moduleUtil->isSubscribed($business_id)) {
            return $moduleUtil->expiredResponse(action([self::class, 'create']));
        }

        $business_locations = BusinessLocation::forDropdown($business_id, false, false);

        return view('inventoryreporting::adjustment.create', compact('business_locations'));
    }

    /**
     * Same flow as StockAdjustmentController::store — positive quantities only (stock decrease).
     */
    public function store(Request $request)
    {
        if (! auth()->user()->can('inventoryreporting.adjust_stock')) {
            abort(403, 'Unauthorized action.');
        }

        $moduleUtil = app(ModuleUtil::class);
        $business_id = (int) $request->session()->get('user.business_id');
        if (! $moduleUtil->isSubscribed($business_id)) {
            return $moduleUtil->expiredResponse(action([self::class, 'create']));
        }

        try {
            DB::beginTransaction();

            $input_data = $request->only(['location_id', 'transaction_date', 'adjustment_type', 'additional_notes', 'total_amount_recovered', 'final_total', 'ref_no']);
            $user_id = (int) $request->session()->get('user.id');

            $input_data['type'] = 'stock_adjustment';
            $input_data['business_id'] = $business_id;
            $input_data['created_by'] = $user_id;
            $input_data['transaction_date'] = $this->productUtil->uf_date($input_data['transaction_date'], true);
            $input_data['total_amount_recovered'] = $this->productUtil->num_uf($input_data['total_amount_recovered']);

            $ref_count = $this->productUtil->setAndGetReferenceCount('stock_adjustment');
            if (empty($input_data['ref_no'])) {
                $input_data['ref_no'] = $this->productUtil->generateReferenceNumber('stock_adjustment', $ref_count);
            }

            $products = $request->input('products', []);

            if (empty($products)) {
                throw new \InvalidArgumentException(__('inventoryreporting::lang.adjustment_no_products'));
            }

            $product_data = [];
            foreach ($products as $product) {
                $qty = $this->productUtil->num_uf($product['quantity'] ?? 0);
                if ($qty <= 0) {
                    throw new \InvalidArgumentException(__('inventoryreporting::lang.adjustment_positive_qty_only'));
                }

                $adjustment_line = [
                    'product_id' => $product['product_id'],
                    'variation_id' => $product['variation_id'],
                    'quantity' => $qty,
                    'unit_price' => $this->productUtil->num_uf($product['unit_price']),
                ];
                if (! empty($product['lot_no_line_id'])) {
                    $adjustment_line['lot_no_line_id'] = $product['lot_no_line_id'];
                }
                $product_data[] = $adjustment_line;

                $this->productUtil->decreaseProductQuantity(
                    $product['product_id'],
                    $product['variation_id'],
                    $input_data['location_id'],
                    $qty
                );
            }

            $stock_adjustment = Transaction::create($input_data);
            $stock_adjustment->stock_adjustment_lines()->createMany($product_data);

            $business = [
                'id' => $business_id,
                'accounting_method' => $request->session()->get('business.accounting_method'),
                'location_id' => $input_data['location_id'],
            ];
            $this->transactionUtil->mapPurchaseSell($business, $stock_adjustment->stock_adjustment_lines, 'stock_adjustment');

            event(new StockAdjustmentCreatedOrModified($stock_adjustment, 'added'));

            $this->transactionUtil->activityLog($stock_adjustment, 'added', null, [], false);

            DB::commit();

            $output = ['success' => 1,
                'msg' => __('stock_adjustment.stock_adjustment_added_successfully'),
            ];
        } catch (\InvalidArgumentException $e) {
            DB::rollBack();

            return redirect()
                ->action([self::class, 'create'])
                ->withInput()
                ->with('status', ['success' => 0, 'msg' => $e->getMessage()]);
        } catch (\Exception $e) {
            DB::rollBack();

            \Log::emergency('InventoryReporting stock adjustment: '.$e->getFile().'Line:'.$e->getLine().'Message:'.$e->getMessage());
            $msg = trans('messages.something_went_wrong');

            if (get_class($e) == \App\Exceptions\PurchaseSellMismatch::class) {
                $msg = $e->getMessage();
            }

            return redirect()
                ->action([self::class, 'create'])
                ->withInput()
                ->with('status', ['success' => 0, 'msg' => $msg]);
        }

        return redirect()
            ->action([\App\Http\Controllers\StockAdjustmentController::class, 'show'], [$stock_adjustment->id])
            ->with('status', $output);
    }

    /**
     * AJAX product row — same partial as core stock adjustment.
     */
    public function getProductRow(Request $request)
    {
        if (! auth()->user()->can('inventoryreporting.adjust_stock')) {
            abort(403, 'Unauthorized action.');
        }

        if (! $request->ajax()) {
            abort(404);
        }

        $row_index = $request->input('row_index');
        $variation_id = $request->input('variation_id');
        $location_id = $request->input('location_id');
        $business_id = $request->session()->get('user.business_id');

        $product = $this->productUtil->getDetailsFromVariation($variation_id, $business_id, $location_id);
        $product->formatted_qty_available = $this->productUtil->num_f($product->qty_available);

        $lot_numbers = [];
        if ($request->session()->get('business.enable_lot_number') == 1 || $request->session()->get('business.enable_product_expiry') == 1) {
            $lot_number_obj = $this->transactionUtil->getLotNumbersFromVariation($variation_id, $business_id, $location_id, true);
            foreach ($lot_number_obj as $lot_number) {
                $lot_number->qty_formated = $this->productUtil->num_f($lot_number->qty_available);
                $lot_numbers[] = $lot_number;
            }
        }
        $product->lot_numbers = $lot_numbers;

        $sub_units = $this->productUtil->getSubUnits($business_id, $product->unit_id, false, $product->id);

        return view('stock_adjustment.partials.product_table_row')
            ->with(compact('product', 'row_index', 'sub_units'));
    }
}
