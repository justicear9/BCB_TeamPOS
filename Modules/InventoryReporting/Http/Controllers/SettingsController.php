<?php

namespace Modules\InventoryReporting\Http\Controllers;

use App\BusinessLocation;
use App\Utils\ModuleUtil;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Accounting\Entities\AccountingAccount;
use Modules\InventoryReporting\Entities\InventoryReportingLocationSetting;

class SettingsController extends Controller
{
    public function edit()
    {
        if (! auth()->user()->can('inventoryreporting.settings')) {
            abort(403, 'Unauthorized action.');
        }

        $moduleUtil = app(ModuleUtil::class);
        if (! $moduleUtil->isModuleInstalled('InventoryReporting')) {
            abort(404);
        }

        $business_id = (int) session()->get('user.business_id');
        $accounts = [];
        if (class_exists(AccountingAccount::class)) {
            $accounts = AccountingAccount::where('business_id', $business_id)
                ->orderBy('name')
                ->pluck('name', 'id');
        }

        $locations = BusinessLocation::where('business_id', $business_id)->orderBy('name')->get();
        $settings = InventoryReportingLocationSetting::where('business_id', $business_id)
            ->get()
            ->keyBy('location_id');

        return view('inventoryreporting::settings.edit', compact('locations', 'accounts', 'settings'));
    }

    public function update(Request $request)
    {
        if (! auth()->user()->can('inventoryreporting.settings')) {
            abort(403, 'Unauthorized action.');
        }

        $business_id = (int) session()->get('user.business_id');
        $data = $request->input('location_accounts', []);

        foreach ($data as $location_id => $row) {
            $location_id = (int) $location_id;
            $accountId = ! empty($row['inventory_adjustment_offset_account_id'])
                ? (int) $row['inventory_adjustment_offset_account_id']
                : null;

            InventoryReportingLocationSetting::updateOrCreate(
                ['business_id' => $business_id, 'location_id' => $location_id],
                ['inventory_adjustment_offset_account_id' => $accountId]
            );
        }

        return redirect()
            ->action([self::class, 'edit'])
            ->with('status', ['success' => 1, 'msg' => __('lang_v1.updated_success')]);
    }
}
