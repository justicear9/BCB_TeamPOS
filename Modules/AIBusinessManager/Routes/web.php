<?php

use Illuminate\Support\Facades\Route;
use Modules\AIBusinessManager\Http\Controllers\AssistantController;
use Modules\AIBusinessManager\Http\Controllers\InstallController;
use Modules\AIBusinessManager\Http\Controllers\SettingsController;

Route::middleware(['web', 'auth', 'language', 'timezone', 'AdminSidebarMenu', 'SetSessionData'])
    ->prefix('ai-business-manager')
    ->name('aibusinessmanager.')
    ->group(function () {
        Route::get('/install', [InstallController::class, 'index'])->name('install.index');
        Route::post('/install', [InstallController::class, 'install'])->name('install.store');
        Route::get('/install/uninstall', [InstallController::class, 'uninstall'])->name('install.uninstall');

        Route::get('/', [AssistantController::class, 'index'])->name('assistant');
        Route::get('/settings', [SettingsController::class, 'edit'])->name('settings.edit');
        Route::put('/settings', [SettingsController::class, 'update'])->name('settings.update');
        Route::put('/settings/manager', [SettingsController::class, 'updateManager'])->name('settings.manager');
        Route::get('/chat/messages', [AssistantController::class, 'messages'])
            ->middleware('throttle:120,1')
            ->name('chat.messages');
        Route::post('/chat', [AssistantController::class, 'chat'])
            ->middleware('throttle:30,1')
            ->name('chat');
        Route::get('/asset/eli-floating-icon.png', [AssistantController::class, 'eliFloatingIcon'])
            ->middleware('throttle:120,1')
            ->name('eli-icon');
        Route::post('/clear', [AssistantController::class, 'clear'])->name('clear');
    });
