<?php

use Illuminate\Support\Facades\Route;
use Modules\Cashier\Http\Controllers\AuthController;
use Modules\Cashier\Http\Controllers\DeskController;
use Modules\Cashier\Http\Controllers\SyncController;

Route::post('auth/logout', [AuthController::class, 'logout']);

Route::get('sync/locations', [SyncController::class, 'locations']);
Route::get('sync/changes', [SyncController::class, 'changes']);
Route::post('sync/operations', [SyncController::class, 'store']);
Route::get('sync/sales/{transaction}', [SyncController::class, 'sale']);
Route::get('sync/receipts/{transaction}', [SyncController::class, 'receipt']);

Route::get('register', [DeskController::class, 'register']);
Route::post('register/open', [DeskController::class, 'openRegister']);
Route::post('register/close', [DeskController::class, 'closeRegister']);
Route::post('returns', [DeskController::class, 'storeReturn']);
Route::post('customers', [DeskController::class, 'storeCustomer']);
Route::put('customers/{contact}', [DeskController::class, 'updateCustomer']);
