<?php

namespace Modules\AIBusinessManager\Http\Controllers;

use App\Utils\ModuleUtil;
use Illuminate\Routing\Controller;
use Menu;

class DataController extends Controller
{
    public function user_permissions()
    {
        return [
            [
                'value' => 'aibusinessmanager.use',
                'label' => __('aibusinessmanager::lang.permission_use'),
                'default' => false,
            ],
        ];
    }

    public function modifyAdminMenu()
    {
        $moduleUtil = new ModuleUtil();
        if (! $moduleUtil->isModuleInstalled('AIBusinessManager')) {
            return;
        }

        if (! auth()->check() || ! auth()->user()->can('aibusinessmanager.use')) {
            return;
        }

        Menu::modify('admin-sidebar-menu', function ($menu) {
            $menu->dropdown(
                __('aibusinessmanager::lang.menu'),
                function ($sub) {
                    $sub->url(
                        action([\Modules\AIBusinessManager\Http\Controllers\AssistantController::class, 'index']),
                        __('aibusinessmanager::lang.menu_chat'),
                        [
                            'icon' => '',
                            'active' => request()->routeIs('aibusinessmanager.assistant')
                                || request()->routeIs('aibusinessmanager.chat')
                                || request()->routeIs('aibusinessmanager.clear'),
                        ]
                    );
                    $sub->url(
                        action([\Modules\AIBusinessManager\Http\Controllers\SettingsController::class, 'edit']),
                        __('aibusinessmanager::lang.menu_settings'),
                        [
                            'icon' => '',
                            'active' => request()->routeIs('aibusinessmanager.settings.*'),
                        ]
                    );
                },
                [
                    'icon' => 'fas fa-robot',
                    'active' => request()->is('ai-business-manager*'),
                ]
            );
        });
    }
}
