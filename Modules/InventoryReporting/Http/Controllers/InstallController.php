<?php

namespace Modules\InventoryReporting\Http\Controllers;

use App\System;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

class InstallController extends Controller
{
    public function __construct()
    {
        $this->module_name = 'inventoryreporting';
        $this->appVersion = config('inventoryreporting.module_version', '1.0.0');
        $this->module_display_name = 'Inventory Reporting';
    }

    public function index()
    {
        if (! auth()->user()->can('manage_modules')) {
            abort(403, 'Unauthorized action.');
        }

        if (! empty(System::getProperty($this->module_name.'_version'))) {
            abort(404);
        }

        return view('inventoryreporting::install.index', [
            'action_url' => action([\Modules\InventoryReporting\Http\Controllers\InstallController::class, 'install']),
            'module_display_name' => $this->module_display_name,
        ]);
    }

    public function install(Request $request)
    {
        if (! auth()->user()->can('manage_modules')) {
            abort(403, 'Unauthorized action.');
        }

        try {
            DB::beginTransaction();

            if (! empty(System::getProperty($this->module_name.'_version'))) {
                abort(404);
            }

            config(['app.debug' => true]);
            Artisan::call('config:clear');

            DB::statement('SET default_storage_engine=INNODB;');
            Artisan::call('module:migrate', ['module' => 'InventoryReporting', '--force' => true]);
            System::addProperty($this->module_name.'_version', $this->appVersion);

            DB::commit();

            $output = ['success' => 1,
                'msg' => __('inventoryreporting::lang.install_success'),
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::emergency('InventoryReporting install: '.$e->getMessage());

            $output = [
                'success' => false,
                'msg' => $e->getMessage(),
            ];
        }

        return redirect()
            ->action([\App\Http\Controllers\Install\ModulesController::class, 'index'])
            ->with('status', $output);
    }

    public function uninstall()
    {
        if (! auth()->user()->can('manage_modules')) {
            abort(403, 'Unauthorized action.');
        }

        try {
            System::removeProperty($this->module_name.'_version');

            $output = ['success' => true,
                'msg' => __('lang_v1.success'),
            ];
        } catch (\Exception $e) {
            $output = ['success' => false,
                'msg' => $e->getMessage(),
            ];
        }

        return redirect()->back()->with(['status' => $output]);
    }
}
