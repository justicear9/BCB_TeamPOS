<?php

namespace Modules\InventoryReporting\Http\Controllers;

use App\BusinessLocation;
use App\Utils\ModuleUtil;
use App\Utils\ProductUtil;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\InventoryReporting\Services\InventoryStockMovementService;

class StockResetController extends Controller
{
    public function create()
    {
        if (! auth()->user()->can('inventoryreporting.stock_reset')) {
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
        $lot_n_exp = session()->get('business.enable_lot_number') == 1 || session()->get('business.enable_product_expiry') == 1;

        return view('inventoryreporting::stock_reset.create', compact('business_locations', 'lot_n_exp'));
    }

    public function store(Request $request, InventoryStockMovementService $stock, ProductUtil $productUtil)
    {
        if (! auth()->user()->can('inventoryreporting.stock_reset')) {
            abort(403, 'Unauthorized action.');
        }

        $moduleUtil = app(ModuleUtil::class);
        $business_id = (int) session()->get('user.business_id');
        if (! $moduleUtil->isSubscribed($business_id)) {
            return $moduleUtil->expiredResponse(action([self::class, 'create']));
        }

        $request->validate([
            'location_id' => 'required|integer',
            'transaction_date' => 'required',
        ]);

        $location_id = (int) $request->input('location_id');
        $transaction_date = $productUtil->uf_date($request->input('transaction_date'), true);

        $accounting_method = session('business.accounting_method', 'fifo');
        $lot_n_exp = session()->get('business.enable_lot_number') == 1 || session()->get('business.enable_product_expiry') == 1;

        $result = $stock->stockResetForLocation(
            $business_id,
            $location_id,
            (int) session()->get('user.id'),
            $transaction_date,
            $accounting_method,
            $lot_n_exp
        );

        if (! $result['success']) {
            return back()->with('status', ['success' => 0, 'msg' => $result['msg']]);
        }

        if (! empty($result['transaction_id'])) {
            return redirect('/stock-adjustments/'.$result['transaction_id'])
                ->with('status', ['success' => 1, 'msg' => $result['msg']]);
        }

        return redirect()
            ->action([self::class, 'create'])
            ->with('status', ['success' => 1, 'msg' => $result['msg']]);
    }
}
