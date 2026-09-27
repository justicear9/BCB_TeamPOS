<?php

namespace Modules\CampaignSms\Http\Controllers;

use App\System;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

class InstallController extends Controller
{
    public function __construct()
    {
        $this->module_name = 'campaignsms';
        $this->appVersion = config('campaignsms.module_version');
        $this->module_display_name = 'Campaign SMS';
    }

    public function index()
    {
        if (! auth()->user()->can('manage_modules')) {
            abort(403, 'Unauthorized action.');
        }

        if (! empty(System::getProperty($this->module_name.'_version'))) {
            $output = [
                'success' => false,
                'msg' => 'Campaign SMS module is already installed',
            ];

            return redirect()
                ->action([\App\Http\Controllers\Install\ModulesController::class, 'index'])
                ->with('status', $output);
        }

        return view('campaignsms::install.index', [
            'action_url' => action([\Modules\CampaignSms\Http\Controllers\InstallController::class, 'install']),
            'module_display_name' => $this->module_display_name,
        ]);
    }

    public function install(Request $request)
    {
        if (! auth()->user()->can('manage_modules')) {
            abort(403, 'Unauthorized action.');
        }

        try {
            if (! empty(System::getProperty($this->module_name.'_version'))) {
                $output = [
                    'success' => false,
                    'msg' => 'Campaign SMS module is already installed',
                ];

                return redirect()
                    ->action([\App\Http\Controllers\Install\ModulesController::class, 'index'])
                    ->with('status', $output);
            }

            DB::beginTransaction();

            config(['app.debug' => true]);
            Artisan::call('config:clear');

            DB::statement('SET default_storage_engine=INNODB;');
            Artisan::call('module:migrate', ['module' => 'CampaignSms', '--force' => true]);
            System::addProperty($this->module_name.'_version', $this->appVersion);

            DB::commit();

            $output = ['success' => 1,
                'msg' => 'Campaign SMS module installed successfully',
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::emergency('CampaignSms install: '.$e->getMessage());

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
