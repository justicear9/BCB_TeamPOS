<?php

use Illuminate\Support\Facades\Route;
use Modules\CampaignSms\Http\Controllers\InstallController;
use Modules\CampaignSms\Http\Controllers\RefillReminderController;
use Modules\CampaignSms\Http\Controllers\RefillSettingsController;
use Modules\CampaignSms\Http\Controllers\SmsCampaignController;
use Modules\CampaignSms\Http\Controllers\SuperadminSmsTokenController;

Route::middleware(['web', 'auth', 'language', 'timezone', 'AdminSidebarMenu', 'SetSessionData'])
    ->prefix('sms-campaigns')
    ->group(function () {
        Route::get('/install', [InstallController::class, 'index']);
        Route::post('/install', [InstallController::class, 'install']);
        Route::get('/install/uninstall', [InstallController::class, 'uninstall']);

        Route::get('/', [SmsCampaignController::class, 'index'])->name('campaignsms.campaigns.index');
        Route::get('/create', [SmsCampaignController::class, 'create'])->name('campaignsms.campaigns.create');
        Route::post('/send', [SmsCampaignController::class, 'store'])->name('campaignsms.campaigns.store');
        Route::get('/contacts/search', [SmsCampaignController::class, 'searchContacts'])->name('campaignsms.contacts.search');
        Route::get('/audience-count', [SmsCampaignController::class, 'audienceCount'])->name('campaignsms.audience-count');

        Route::get('/refill-settings', [RefillSettingsController::class, 'edit'])->name('campaignsms.refill-settings.edit');
        Route::put('/refill-settings', [RefillSettingsController::class, 'update'])->name('campaignsms.refill-settings.update');

        Route::get('/refills/contact/{contact_id}/data', [RefillReminderController::class, 'data'])->name('campaignsms.refills.data');
        Route::post('/refills/contact/{contact_id}', [RefillReminderController::class, 'store'])->name('campaignsms.refills.store');
        Route::put('/refills/{id}', [RefillReminderController::class, 'update'])->name('campaignsms.refills.update');
        Route::delete('/refills/{id}', [RefillReminderController::class, 'destroy'])->name('campaignsms.refills.destroy');
        Route::get('/refills/products/search', [RefillReminderController::class, 'products'])->name('campaignsms.refills.products');

        Route::get('/{campaign}', [SmsCampaignController::class, 'show'])->name('campaignsms.campaigns.show');
    });

Route::middleware(['web', 'auth', 'language', 'AdminSidebarMenu', 'superadmin'])
    ->prefix('superadmin')
    ->group(function () {
        Route::get('/business/{business_id}/sms-tokens', [SuperadminSmsTokenController::class, 'edit'])->name('campaignsms.superadmin.tokens');
        Route::put('/business/{business_id}/sms-tokens', [SuperadminSmsTokenController::class, 'update'])->name('campaignsms.superadmin.tokens.update');
    });
