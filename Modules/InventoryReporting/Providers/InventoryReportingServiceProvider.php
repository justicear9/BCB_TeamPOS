<?php

namespace Modules\InventoryReporting\Providers;

use Illuminate\Support\ServiceProvider;

class InventoryReportingServiceProvider extends ServiceProvider
{
    public function boot()
    {
        $this->registerConfig();
        $this->registerViews();
        $this->registerTranslations();
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');

        $this->app['events']->listen(
            \App\Events\StockAdjustmentCreatedOrModified::class,
            \Modules\InventoryReporting\Listeners\PostStockAdjustmentAccounting::class
        );

        $this->app['events']->listen(
            \App\Events\OpeningStockCreatedOrModified::class,
            \Modules\InventoryReporting\Listeners\PostOpeningStockAccounting::class
        );

        $this->app['events']->listen(
            \App\Events\StockTransferCreatedOrModified::class,
            \Modules\InventoryReporting\Listeners\PostStockTransferAccounting::class
        );
    }

    public function register()
    {
        $this->app->register(RouteServiceProvider::class);
    }

    protected function registerConfig()
    {
        $this->mergeConfigFrom(
            __DIR__.'/../Config/config.php',
            'inventoryreporting'
        );
    }

    public function registerViews()
    {
        $this->loadViewsFrom(__DIR__.'/../Resources/views', 'inventoryreporting');
    }

    public function registerTranslations()
    {
        $this->loadTranslationsFrom(__DIR__.'/../Resources/lang', 'inventoryreporting');
    }
}
