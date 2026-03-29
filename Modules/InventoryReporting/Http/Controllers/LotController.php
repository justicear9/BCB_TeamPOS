<?php

namespace Modules\InventoryReporting\Http\Controllers;

use App\Utils\ModuleUtil;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\InventoryReporting\Services\InventoryLotService;

class LotController extends Controller
{
    public function index(Request $request, InventoryLotService $lots)
    {
        if (! auth()->user()->can('inventoryreporting.lot_edit')) {
            abort(403, 'Unauthorized action.');
        }

        $moduleUtil = app(ModuleUtil::class);
        if (! $moduleUtil->isModuleInstalled('InventoryReporting')) {
            abort(404);
        }

        $business_id = (int) session()->get('user.business_id');
        $locations = \App\BusinessLocation::forDropdown($business_id, false, false);
        $location_id = $request->input('location_id') ? (int) $request->input('location_id') : null;
        $table_rows = collect();
        if ($location_id) {
            $table_rows = $lots->queryLotsForLocation($business_id, $location_id)->orderBy('PL.id')->get();
        }

        return view('inventoryreporting::lots.index', compact('locations', 'location_id', 'table_rows'));
    }

    public function edit(int $id)
    {
        if (! auth()->user()->can('inventoryreporting.lot_edit')) {
            abort(403, 'Unauthorized action.');
        }

        $business_id = (int) session()->get('user.business_id');
        $pl = \App\PurchaseLine::with(['product', 'transaction'])->where('id', $id)->firstOrFail();
        if ((int) $pl->transaction->business_id !== $business_id) {
            abort(404);
        }

        return view('inventoryreporting::lots.edit', compact('pl'));
    }

    public function update(Request $request, int $id, InventoryLotService $lots)
    {
        if (! auth()->user()->can('inventoryreporting.lot_edit')) {
            abort(403, 'Unauthorized action.');
        }

        $business_id = (int) session()->get('user.business_id');
        $location_id = (int) $request->input('location_id');

        $request->validate([
            'lot_number' => 'nullable|string|max:255',
            'exp_date' => 'nullable|date',
        ]);

        $exp = $request->input('exp_date') ? \Carbon\Carbon::parse($request->input('exp_date'))->format('Y-m-d') : null;

        try {
            $lots->updateLotForPurchaseLine($business_id, $id, $request->input('lot_number'), $exp, $location_id);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return back()->withInput()->with('status', ['success' => 0, 'msg' => collect($e->errors())->flatten()->first()]);
        }

        return redirect()
            ->action([self::class, 'index'], ['location_id' => $location_id])
            ->with('status', ['success' => 1, 'msg' => __('lang_v1.updated_success')]);
    }
}
