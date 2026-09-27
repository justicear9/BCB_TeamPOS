<?php

use Illuminate\Support\Facades\Route;
use Modules\InventoryReporting\Http\Controllers\InstallController;
use Modules\InventoryReporting\Http\Controllers\InventoryReportController;
use Modules\InventoryReporting\Http\Controllers\LotController;
use Modules\InventoryReporting\Http\Controllers\SettingsController;
use Modules\InventoryReporting\Http\Controllers\StockResetController;

Route::middleware(['web', 'auth', 'language', 'timezone', 'AdminSidebarMenu', 'SetSessionData'])
    ->prefix('inventory-reporting')
    ->name('inventoryreporting.')
    ->group(function () {
        Route::get('/install', [InstallController::class, 'index']);
        Route::post('/install', [InstallController::class, 'install']);
        Route::get('/install/uninstall', [InstallController::class, 'uninstall']);

        Route::get('/stock-reset', [StockResetController::class, 'create'])->name('stock-reset.create');
        Route::post('/stock-reset', [StockResetController::class, 'store'])->name('stock-reset.store');

        Route::get('/lots', [LotController::class, 'index'])->name('lots.index');
        Route::get('/lots/{id}/edit', [LotController::class, 'edit'])->name('lots.edit');
        Route::put('/lots/{id}', [LotController::class, 'update'])->name('lots.update');

        Route::get('/reports/ageing', [InventoryReportController::class, 'ageing'])->name('reports.ageing');
        Route::get('/reports/ageing/export', [InventoryReportController::class, 'exportAgeing'])->name('reports.ageing.export');
        Route::get('/reports/stock-as-at', [InventoryReportController::class, 'stockAsAt'])->name('reports.stock-as-at');
        Route::get('/reports/stock-as-at/export', [InventoryReportController::class, 'exportStockAsAt'])->name('reports.stock-as-at.export');

        Route::get('/settings', [SettingsController::class, 'edit'])->name('settings.edit');
        Route::put('/settings', [SettingsController::class, 'update'])->name('settings.update');
    });
