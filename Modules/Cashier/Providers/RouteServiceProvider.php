<?php

namespace Modules\Cashier\Providers;

use App\Http\Middleware\SetSessionData;
use App\Http\Middleware\Timezone;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Modules\Cashier\Http\Controllers\AuthController;
use Modules\Cashier\Http\Middleware\FreshCashierToken;

class RouteServiceProvider extends ServiceProvider
{
    public function map()
    {
        Route::middleware(['throttle:10,1'])
            ->prefix('cashier/api')
            ->post('auth/login', [AuthController::class, 'login']);

        Route::middleware([
            StartSession::class,
            'auth:api',
            FreshCashierToken::class,
            SetSessionData::class,
            Timezone::class,
            'throttle:240,1',
        ])
            ->prefix('cashier/api')
            ->group(__DIR__.'/../Routes/api.php');
    }
}
