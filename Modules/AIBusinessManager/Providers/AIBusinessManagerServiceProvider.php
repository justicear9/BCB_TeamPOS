<?php

namespace Modules\AIBusinessManager\Providers;

use App\Utils\ModuleUtil;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Modules\AIBusinessManager\Http\Controllers\AssistantController;
use Modules\AIBusinessManager\Services\ReportPageContextService;

class AIBusinessManagerServiceProvider extends ServiceProvider
{
    public function boot()
    {
        $this->registerConfig();
        $this->registerViews();
        $this->registerTranslations();
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
        $this->registerFloatingChatComposer();
    }

    protected function registerFloatingChatComposer(): void
    {
        View::composer('layouts.app', function (\Illuminate\View\View $view) {
            $defaults = [
                'aibm_show_floating_chat' => false,
                'aibm_chat_post_url' => '',
                'aibm_chat_messages_url' => '',
                'aibm_csrf_token' => '',
                'aibm_report_context' => null,
                'aibm_floating_accent_hex' => '#3c8dbc',
                'aibm_floating_accent_rgb' => '60, 141, 188',
                'aibm_eli_icon_url' => '',
            ];

            $request = request();
            if (! auth()->check()) {
                $view->with($defaults);

                return;
            }

            $user = auth()->user();
            if (! $user || ! $user->can('aibusinessmanager.use')) {
                $view->with($defaults);

                return;
            }

            $moduleUtil = new ModuleUtil();
            if (! $moduleUtil->isModuleInstalled('AIBusinessManager')) {
                $view->with($defaults);

                return;
            }

            $pos_layout = $request->segment(1) == 'pos'
                && ($request->segment(2) == 'create' || $request->segment(3) == 'edit' || $request->segment(2) == 'payment');

            if ($pos_layout || $request->segment(1) == 'customer-display') {
                $view->with($defaults);

                return;
            }

            if ($request->segment(1) === 'pos') {
                $view->with($defaults);

                return;
            }

            if ($request->segment(2) === 'create') {
                $view->with($defaults);

                return;
            }

            if ($request->routeIs('aibusinessmanager.install.*')
                || $request->routeIs('aibusinessmanager.assistant')
                || $request->routeIs('aibusinessmanager.settings.*')) {
                session()->forget('aibm_last_report_context');
                $view->with($defaults);

                return;
            }

            $accent = app(AssistantController::class)->themeAccent();

            $report_context = app(ReportPageContextService::class)->resolve($request);

            if ($report_context !== null) {
                session(['aibm_last_report_context' => $report_context]);
            } else {
                session()->forget('aibm_last_report_context');
            }

            $view->with([
                'aibm_show_floating_chat' => true,
                'aibm_chat_post_url' => action([AssistantController::class, 'chat']),
                'aibm_chat_messages_url' => action([AssistantController::class, 'messages']),
                'aibm_csrf_token' => csrf_token(),
                'aibm_report_context' => $report_context,
                'aibm_floating_accent_hex' => $accent['hex'],
                'aibm_floating_accent_rgb' => $accent['rgb'],
                'aibm_eli_icon_url' => route('aibusinessmanager.eli-icon'),
            ]);
        });
    }

    public function register()
    {
        $this->app->register(RouteServiceProvider::class);
    }

    protected function registerConfig()
    {
        $this->mergeConfigFrom(
            __DIR__.'/../Config/config.php',
            'aibusinessmanager'
        );
    }

    public function registerViews()
    {
        $this->loadViewsFrom(__DIR__.'/../Resources/views', 'aibusinessmanager');
    }

    public function registerTranslations()
    {
        $this->loadTranslationsFrom(__DIR__.'/../Resources/lang', 'aibusinessmanager');
    }
}
