<?php

namespace Modules\Cashier\Providers;

use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    public function map()
    {
        Route::middleware('auth:api')
            ->prefix('cashier/api')
            ->group(__DIR__.'/../Routes/api.php');
    }
}
