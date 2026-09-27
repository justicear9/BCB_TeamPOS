<?php

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

Route::middleware('web', 'SetSessionData', 'auth', 'language', 'timezone', 'AdminSidebarMenu')->prefix('accounting')->group(function () {
    Route::get('dashboard', [\Modules\Accounting\Http\Controllers\AccountingController::class, 'dashboard']);

    Route::get('accounts-dropdown', [\Modules\Accounting\Http\Controllers\AccountingController::class, 'AccountsDropdown'])->name('accounts-dropdown');

    Route::get('get-account-sub-types', [\Modules\Accounting\Http\Controllers\CoaController::class, 'getAccountSubTypes']);
    Route::get('get-account-details-types', [\Modules\Accounting\Http\Controllers\CoaController::class, 'getAccountDetailsType']);

    Route::prefix('bank-reconciliation')->group(function () {
        Route::get('/', [\Modules\Accounting\Http\Controllers\ReconcileController::class, 'index'])->name('accounting.bankReconciliation.index');
        Route::get('create', [\Modules\Accounting\Http\Controllers\ReconcileController::class, 'create'])->name('accounting.bankReconciliation.create');
        Route::post('/', [\Modules\Accounting\Http\Controllers\ReconcileController::class, 'store'])->name('accounting.bankReconciliation.store');
        Route::get('gl-lines/{bank_account}', [\Modules\Accounting\Http\Controllers\ReconcileController::class, 'glLines'])->name('accounting.bankReconciliation.glLines');
        Route::get('statement/{bank_account}/import-template', [\Modules\Accounting\Http\Controllers\ReconcileController::class, 'bankStatementImportTemplate'])->name('accounting.bankReconciliation.importTemplate');
        Route::post('statement/{bank_account}/import', [\Modules\Accounting\Http\Controllers\ReconcileController::class, 'importBankStatement'])->name('accounting.bankReconciliation.import');
        Route::get('statement/{bank_account}', [\Modules\Accounting\Http\Controllers\ReconcileController::class, 'statement'])->name('accounting.bankReconciliation.statement');
        Route::post('statement-line', [\Modules\Accounting\Http\Controllers\ReconcileController::class, 'storeLine'])->name('accounting.bankReconciliation.storeLine');
        Route::post('reconcile-line', [\Modules\Accounting\Http\Controllers\ReconcileController::class, 'reconcileLine'])->name('accounting.bankReconciliation.reconcileLine');
    });

    Route::get('audit-log', [\Modules\Accounting\Http\Controllers\AuditLogController::class, 'index'])->name('accounting.auditLog');

    Route::get('fixed-assets/run-depreciation', [\Modules\Accounting\Http\Controllers\FixedAssetController::class, 'depreciateForm'])->name('accounting.fixedAssets.depreciateForm');
    Route::post('fixed-assets/run-depreciation', [\Modules\Accounting\Http\Controllers\FixedAssetController::class, 'depreciateRun'])->name('accounting.fixedAssets.depreciateRun');
    Route::get('fixed-assets/schedule', [\Modules\Accounting\Http\Controllers\FixedAssetController::class, 'schedule'])->name('accounting.fixedAssets.schedule');
    Route::get('fixed-assets/schedule/export', [\Modules\Accounting\Http\Controllers\FixedAssetController::class, 'scheduleExport'])->name('accounting.fixedAssets.scheduleExport');
    Route::get('fixed-assets/{fixed_asset}/post-acquisition', [\Modules\Accounting\Http\Controllers\FixedAssetController::class, 'postAcquisitionForm'])->name('accounting.fixedAssets.postAcquisitionForm');
    Route::post('fixed-assets/{fixed_asset}/post-acquisition', [\Modules\Accounting\Http\Controllers\FixedAssetController::class, 'postAcquisition'])->name('accounting.fixedAssets.postAcquisition');
    Route::get('fixed-assets/{fixed_asset}/dispose', [\Modules\Accounting\Http\Controllers\FixedAssetController::class, 'disposeForm'])->name('accounting.fixedAssets.disposeForm');
    Route::post('fixed-assets/{fixed_asset}/dispose', [\Modules\Accounting\Http\Controllers\FixedAssetController::class, 'dispose'])->name('accounting.fixedAssets.dispose');
    Route::resource('fixed-assets', \Modules\Accounting\Http\Controllers\FixedAssetController::class)->names([
        'index' => 'accounting.fixedAssets.index',
        'create' => 'accounting.fixedAssets.create',
        'store' => 'accounting.fixedAssets.store',
        'show' => 'accounting.fixedAssets.show',
        'edit' => 'accounting.fixedAssets.edit',
        'update' => 'accounting.fixedAssets.update',
        'destroy' => 'accounting.fixedAssets.destroy',
    ]);

    Route::get('chart-of-accounts/import-template', [\Modules\Accounting\Http\Controllers\CoaController::class, 'importTemplate'])->name('accounting.chart_of_accounts.import_template');
    Route::post('chart-of-accounts/import', [\Modules\Accounting\Http\Controllers\CoaController::class, 'import'])->name('accounting.chart_of_accounts.import');
    Route::resource('chart-of-accounts', \Modules\Accounting\Http\Controllers\CoaController::class);
    Route::get('ledger/{id}', [\Modules\Accounting\Http\Controllers\CoaController::class, 'ledger'])->name('accounting.ledger');
    Route::get('activate-deactivate/{id}', [\Modules\Accounting\Http\Controllers\CoaController::class, 'activateDeactivate']);
    Route::get('create-default-accounts', [\Modules\Accounting\Http\Controllers\CoaController::class, 'createDefaultAccounts'])->name('accounting.create-default-accounts');

    Route::resource('journal-entry', \Modules\Accounting\Http\Controllers\JournalEntryController::class);

    Route::get('settings', [\Modules\Accounting\Http\Controllers\SettingsController::class, 'index']);
    Route::get('reset-data', [\Modules\Accounting\Http\Controllers\SettingsController::class, 'resetData']);

    Route::resource('account-type', \Modules\Accounting\Http\Controllers\AccountTypeController::class);

    Route::resource('transfer', \Modules\Accounting\Http\Controllers\TransferController::class)->except(['show']);

    Route::resource('budget', \Modules\Accounting\Http\Controllers\BudgetController::class)->except(['show', 'edit', 'update', 'destroy']);

    Route::get('reports', [\Modules\Accounting\Http\Controllers\ReportController::class, 'index']);
    Route::get('reports/trial-balance', [\Modules\Accounting\Http\Controllers\ReportController::class, 'trialBalance'])->name('accounting.trialBalance');
    Route::get('reports/balance-sheet', [\Modules\Accounting\Http\Controllers\ReportController::class, 'balanceSheet'])->name('accounting.balanceSheet');
    Route::get('reports/profit-loss', [\Modules\Accounting\Http\Controllers\ReportController::class, 'profitAndLoss'])->name('accounting.profitLoss');
    Route::get('reports/cash-flow', [\Modules\Accounting\Http\Controllers\ReportController::class, 'cashFlow'])->name('accounting.cashFlow');
    Route::get('reports/account-receivable-ageing-report',
    [\Modules\Accounting\Http\Controllers\ReportController::class, 'accountReceivableAgeingReport'])->name('accounting.account_receivable_ageing_report');
    Route::get('reports/account-receivable-ageing-details',
    [\Modules\Accounting\Http\Controllers\ReportController::class, 'accountReceivableAgeingDetails'])->name('accounting.account_receivable_ageing_details');

    Route::get('reports/account-payable-ageing-report',
    [\Modules\Accounting\Http\Controllers\ReportController::class, 'accountPayableAgeingReport'])->name('accounting.account_payable_ageing_report');
    Route::get('reports/account-payable-ageing-details',
    [\Modules\Accounting\Http\Controllers\ReportController::class, 'accountPayableAgeingDetails'])->name('accounting.account_payable_ageing_details');

    Route::get('transactions', [\Modules\Accounting\Http\Controllers\TransactionController::class, 'index']);
    Route::get('transactions/map', [\Modules\Accounting\Http\Controllers\TransactionController::class, 'map']);
    Route::post('transactions/save-map', [\Modules\Accounting\Http\Controllers\TransactionController::class, 'saveMap']);
    Route::post('save-settings', [\Modules\Accounting\Http\Controllers\SettingsController::class, 'saveSettings']);

    Route::get('install', [\Modules\Accounting\Http\Controllers\InstallController::class, 'index']);
    Route::post('install', [\Modules\Accounting\Http\Controllers\InstallController::class, 'install']);
    Route::get('install/uninstall', [\Modules\Accounting\Http\Controllers\InstallController::class, 'uninstall']);
    Route::get('install/update', [\Modules\Accounting\Http\Controllers\InstallController::class, 'update']);
});
