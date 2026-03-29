<?php

namespace Modules\InventoryReporting\Http\Controllers;

use App\Utils\ModuleUtil;
use Illuminate\Routing\Controller;
use Menu;

class DataController extends Controller
{
    public function user_permissions()
    {
        return [
            [
                'value' => 'inventoryreporting.stock_reset',
                'label' => __('inventoryreporting::lang.permission_stock_reset'),
                'default' => false,
            ],
            [
                'value' => 'inventoryreporting.lot_edit',
                'label' => __('inventoryreporting::lang.permission_lot_edit'),
                'default' => false,
            ],
            [
                'value' => 'inventoryreporting.adjust_stock',
                'label' => __('inventoryreporting::lang.permission_adjust_stock'),
                'default' => false,
            ],
            [
                'value' => 'inventoryreporting.reports',
                'label' => __('inventoryreporting::lang.permission_reports'),
                'default' => false,
            ],
            [
                'value' => 'inventoryreporting.settings',
                'label' => __('inventoryreporting::lang.permission_settings'),
                'default' => false,
            ],
        ];
    }

    public function modifyAdminMenu()
    {
        $moduleUtil = new ModuleUtil();
        if (! $moduleUtil->isModuleInstalled('InventoryReporting')) {
            return;
        }

        if (! auth()->check()) {
            return;
        }

        $any = auth()->user()->can('inventoryreporting.stock_reset')
            || auth()->user()->can('inventoryreporting.lot_edit')
            || auth()->user()->can('inventoryreporting.adjust_stock')
            || auth()->user()->can('inventoryreporting.reports')
            || auth()->user()->can('inventoryreporting.settings');

        if (! $any) {
            return;
        }

        Menu::modify('admin-sidebar-menu', function ($menu) {
            $menu->dropdown(
                __('inventoryreporting::lang.menu_inventory'),
                function ($sub) {
                    if (auth()->user()->can('inventoryreporting.stock_reset')) {
                        $sub->url(
                            action([\Modules\InventoryReporting\Http\Controllers\StockResetController::class, 'create']),
                            __('inventoryreporting::lang.stock_reset'),
                            ['icon' => '', 'active' => request()->routeIs('inventoryreporting.stock-reset.*')]
                        );
                    }
                    if (auth()->user()->can('inventoryreporting.adjust_stock')) {
                        $sub->url(
                            action([\Modules\InventoryReporting\Http\Controllers\SignedStockAdjustmentController::class, 'create']),
                            __('stock_adjustment.add'),
                            ['icon' => '', 'active' => request()->routeIs('inventoryreporting.adjustment.*')]
                        );
                    }
                    if (auth()->user()->can('inventoryreporting.lot_edit')) {
                        $sub->url(
                            action([\Modules\InventoryReporting\Http\Controllers\LotController::class, 'index']),
                            __('inventoryreporting::lang.lot_management'),
                            ['icon' => '', 'active' => request()->routeIs('inventoryreporting.lots.*')]
                        );
                    }
                    if (auth()->user()->can('inventoryreporting.reports')) {
                        $sub->url(
                            action([\Modules\InventoryReporting\Http\Controllers\InventoryReportController::class, 'ageing']),
                            __('inventoryreporting::lang.report_ageing'),
                            ['icon' => '', 'active' => request()->routeIs('inventoryreporting.reports.ageing')]
                        );
                        $sub->url(
                            action([\Modules\InventoryReporting\Http\Controllers\InventoryReportController::class, 'stockAsAt']),
                            __('inventoryreporting::lang.report_stock_as_at'),
                            ['icon' => '', 'active' => request()->routeIs('inventoryreporting.reports.stock-as-at')]
                        );
                    }
                    if (auth()->user()->can('inventoryreporting.settings')) {
                        $sub->url(
                            action([\Modules\InventoryReporting\Http\Controllers\SettingsController::class, 'edit']),
                            __('inventoryreporting::lang.settings'),
                            ['icon' => '', 'active' => request()->routeIs('inventoryreporting.settings.*')]
                        );
                    }
                },
                ['icon' => 'fas fa-warehouse', 'active' => request()->is('inventory-reporting*')]
            );
        });
    }
}
