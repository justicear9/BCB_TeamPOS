<?php

namespace Modules\Cashier\Providers;

use Illuminate\Support\ServiceProvider;

class CashierServiceProvider extends ServiceProvider
{
    public function boot()
    {
        $this->mergeConfigFrom(__DIR__.'/../Config/config.php', 'cashier');
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
    }

    public function register()
    {
        $this->app->register(RouteServiceProvider::class);
    }
}
