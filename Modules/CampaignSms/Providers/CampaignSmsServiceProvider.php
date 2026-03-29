<?php

namespace Modules\CampaignSms\Providers;

use App\Transaction;
use App\Utils\ModuleUtil;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Modules\CampaignSms\Services\PosInvoiceRefillSnapshotService;
use Modules\CampaignSms\Services\SmsTokenService;

class CampaignSmsServiceProvider extends ServiceProvider
{
    public function boot()
    {
        $this->registerConfig();
        $this->registerViews();
        $this->registerTranslations();
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
        $this->registerSuperadminBusinessViewComposer();
        $this->registerPosReceiptRefillComposer();
    }

    /**
     * Append refill snapshot HTML to receipt footer_text — no edits to core receipt blades.
     */
    protected function registerPosReceiptRefillComposer(): void
    {
        $paths = glob(resource_path('views/sale_pos/receipts/*.blade.php')) ?: [];
        $views = array_map(function ($path) {
            return 'sale_pos.receipts.'.basename($path, '.blade.php');
        }, $paths);

        if ($views === []) {
            return;
        }

        View::composer($views, function ($view) {
            try {
                $moduleUtil = app(ModuleUtil::class);
                if (! $moduleUtil->isModuleInstalled('CampaignSms')) {
                    return;
                }

                if (! Schema::hasTable('sms_pos_invoice_refill_snapshots')) {
                    return;
                }

                $data = $view->getData();
                $rd = $data['receipt_details'] ?? null;
                if (! is_object($rd) || empty($rd->invoice_no)) {
                    return;
                }

                if (! empty($rd->_campaignsms_footer_done)) {
                    return;
                }

                $businessId = (int) (optional(request()->session())->get('user.business_id') ?? 0);

                $transaction = null;
                if ($businessId > 0) {
                    $transaction = Transaction::where('business_id', $businessId)
                        ->where('type', 'sell')
                        ->where('invoice_no', $rd->invoice_no)
                        ->orderByDesc('id')
                        ->first();
                }

                // Guest invoice / odd contexts: session may lack business_id; resolve sell by invoice_no.
                if (! $transaction) {
                    $transaction = Transaction::where('type', 'sell')
                        ->where('invoice_no', $rd->invoice_no)
                        ->orderByDesc('id')
                        ->first();
                }

                if (! $transaction) {
                    return;
                }

                $snap = app(PosInvoiceRefillSnapshotService::class)->findForTransactionId((int) $transaction->id);
                if (! $snap || empty($snap->lines)) {
                    return;
                }

                $business = optional(request()->session())->get('business', []);
                $dateFormat = is_array($business) && ! empty($business['date_format'])
                    ? $business['date_format']
                    : 'Y-m-d';

                $html = view('campaignsms::receipts.refill_footer_block', [
                    'lines' => $snap->lines,
                    'date_format' => $dateFormat,
                ])->render();

                $footer = (string) ($rd->footer_text ?? '');
                $rd->footer_text = $footer.$html;
                $rd->_campaignsms_footer_done = true;
            } catch (\Throwable $e) {
                \Log::warning('CampaignSms receipt footer composer: '.$e->getMessage(), [
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ]);
            }
        });
    }

    protected function registerSuperadminBusinessViewComposer(): void
    {
        View::composer('superadmin::business.show', function ($view) {
            $moduleUtil = app(\App\Utils\ModuleUtil::class);
            if (! $moduleUtil->isModuleInstalled('CampaignSms')) {
                return;
            }
            $business = $view->business ?? null;
            if (! $business) {
                return;
            }
            $view->with(
                'campaignsms_token_balance',
                app(SmsTokenService::class)->getBalance((int) $business->id)
            );
        });
    }

    public function register()
    {
        $this->app->register(RouteServiceProvider::class);
        $this->commands([
            \Modules\CampaignSms\Console\ProcessRefillRemindersCommand::class,
        ]);
    }

    protected function registerConfig()
    {
        $this->mergeConfigFrom(
            __DIR__.'/../Config/config.php',
            'campaignsms'
        );
    }

    public function registerViews()
    {
        $sourcePath = __DIR__.'/../Resources/views';
        $this->loadViewsFrom($sourcePath, 'campaignsms');
    }

    public function registerTranslations()
    {
        $this->loadTranslationsFrom(__DIR__.'/../Resources/lang', 'campaignsms');
    }
}
