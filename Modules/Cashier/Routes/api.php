<?php

use Illuminate\Support\Facades\Route;
use Modules\Cashier\Http\Controllers\SyncController;

Route::get('sync/locations', [SyncController::class, 'locations']);
Route::get('sync/changes', [SyncController::class, 'changes']);
Route::post('sync/operations', [SyncController::class, 'store']);
