<?php

namespace Modules\InventoryReporting\Services;

use App\BusinessLocation;
use App\Transaction;
use App\Utils\ModuleUtil;
use Modules\Accounting\Entities\AccountingAccountsTransaction;
use Modules\Accounting\Utils\AccountingUtil;
use Modules\InventoryReporting\Entities\InventoryReportingLocationSetting;

/**
 * Posts inventory adjustment journal lines for stock_adjustment / opening_stock created by this module or core,
 * when Accounting is enabled and location accounts are configured.
 *
 * Decrease stock (stock_adjustment): Dr offset (expense), Cr inventory asset (purchase deposit_to).
 * Increase stock (opening_stock): Dr inventory asset, Cr offset.
 */
class InventoryAccountingService
{
    public const SUB_TYPE = 'inv_stock_adjustment';

    public function __construct(
        protected ModuleUtil $moduleUtil,
        protected AccountingUtil $accountingUtil
    ) {}

    public function shouldPost(int $businessId): bool
    {
        if (! $this->moduleUtil->isModuleInstalled('InventoryReporting')) {
            return false;
        }
        if (! $this->moduleUtil->isModuleInstalled('Accounting')) {
            return false;
        }
        if (! $this->moduleUtil->hasThePermissionInSubscription($businessId, 'accounting_module')) {
            return false;
        }

        return true;
    }

    /**
     * @return array{0: ?int, 1: ?int} [inventory_asset_account_id, offset_account_id]
     */
    public function resolveAccountsForLocation(int $businessId, int $locationId): array
    {
        $location = BusinessLocation::where('business_id', $businessId)->where('id', $locationId)->first();
        if (! $location) {
            return [null, null];
        }

        $map = json_decode($location->accounting_default_map, true) ?: [];
        $inventoryAsset = isset($map['purchases']['deposit_to']) ? (int) $map['purchases']['deposit_to'] : null;

        $setting = InventoryReportingLocationSetting::where('business_id', $businessId)
            ->where('location_id', $locationId)
            ->first();
        $offset = $setting && $setting->inventory_adjustment_offset_account_id
            ? (int) $setting->inventory_adjustment_offset_account_id
            : null;

        return [$inventoryAsset, $offset];
    }

    public function removeByTransactionId(int $businessId, int $transactionId): void
    {
        if (! class_exists(AccountingAccountsTransaction::class)) {
            return;
        }

        $rows = AccountingAccountsTransaction::query()
            ->where('transaction_id', $transactionId)
            ->where('sub_type', self::SUB_TYPE)
            ->get();

        foreach ($rows as $row) {
            if ($this->accountingUtil->isOperationDateLocked($businessId, $row->operation_date)) {
                \Log::warning('InventoryReporting: cannot remove accounting lines (period locked)', [
                    'transaction_id' => $transactionId,
                ]);

                return;
            }
        }

        AccountingAccountsTransaction::query()
            ->where('transaction_id', $transactionId)
            ->where('sub_type', self::SUB_TYPE)
            ->delete();
    }

    /**
     * Stock reduction: Dr offset, Cr inventory.
     */
    public function postStockDecrease(Transaction $transaction, ?int $userId = null): void
    {
        if (! $this->shouldPost((int) $transaction->business_id)) {
            return;
        }

        $amount = (float) $transaction->final_total;
        if ($amount <= 0) {
            return;
        }

        [$inventoryAsset, $offset] = $this->resolveAccountsForLocation((int) $transaction->business_id, (int) $transaction->location_id);
        if (! $inventoryAsset || ! $offset) {
            return;
        }

        $businessId = (int) $transaction->business_id;
        $op = \Carbon\Carbon::parse($transaction->transaction_date);
        try {
            $this->accountingUtil->assertOperationDateNotLocked($businessId, $op);
        } catch (\Throwable $e) {
            \Log::warning('InventoryReporting accounting skip: '.$e->getMessage());

            return;
        }

        $this->removeByTransactionId($businessId, (int) $transaction->id);

        $uid = $userId ?: (int) ($transaction->created_by ?? 0);
        $refLabel = $transaction->ref_no ? (string) $transaction->ref_no : ('#'.$transaction->id);
        $note = __('inventoryreporting::lang.accounting_note_stock_decrease', ['ref' => $refLabel]);

        AccountingAccountsTransaction::createTransaction([
            'amount' => $amount,
            'accounting_account_id' => $offset,
            'transaction_id' => $transaction->id,
            'type' => 'debit',
            'sub_type' => self::SUB_TYPE,
            'map_type' => 'inv_adj_offset',
            'operation_date' => $op,
            'created_by' => $uid,
            'note' => $note,
            'location_id' => $transaction->location_id,
        ]);

        AccountingAccountsTransaction::createTransaction([
            'amount' => $amount,
            'accounting_account_id' => $inventoryAsset,
            'transaction_id' => $transaction->id,
            'type' => 'credit',
            'sub_type' => self::SUB_TYPE,
            'map_type' => 'inv_adj_inventory',
            'operation_date' => $op,
            'created_by' => $uid,
            'note' => $note,
            'location_id' => $transaction->location_id,
        ]);
    }

    /**
     * Stock increase (opening_stock): Dr inventory, Cr offset.
     */
    public function postStockIncrease(Transaction $transaction, ?int $userId = null): void
    {
        if (! $this->shouldPost((int) $transaction->business_id)) {
            return;
        }

        $amount = (float) $transaction->final_total;
        if ($amount <= 0) {
            return;
        }

        [$inventoryAsset, $offset] = $this->resolveAccountsForLocation((int) $transaction->business_id, (int) $transaction->location_id);
        if (! $inventoryAsset || ! $offset) {
            return;
        }

        $businessId = (int) $transaction->business_id;
        $op = \Carbon\Carbon::parse($transaction->transaction_date);
        try {
            $this->accountingUtil->assertOperationDateNotLocked($businessId, $op);
        } catch (\Throwable $e) {
            \Log::warning('InventoryReporting accounting skip: '.$e->getMessage());

            return;
        }

        $this->removeByTransactionId($businessId, (int) $transaction->id);

        $uid = $userId ?: (int) ($transaction->created_by ?? 0);
        $refLabel = $transaction->ref_no ? (string) $transaction->ref_no : ('#'.$transaction->id);
        $note = __('inventoryreporting::lang.accounting_note_stock_increase', ['ref' => $refLabel]);

        AccountingAccountsTransaction::createTransaction([
            'amount' => $amount,
            'accounting_account_id' => $inventoryAsset,
            'transaction_id' => $transaction->id,
            'type' => 'debit',
            'sub_type' => self::SUB_TYPE,
            'map_type' => 'inv_adj_inventory',
            'operation_date' => $op,
            'created_by' => $uid,
            'note' => $note,
            'location_id' => $transaction->location_id,
        ]);

        AccountingAccountsTransaction::createTransaction([
            'amount' => $amount,
            'accounting_account_id' => $offset,
            'transaction_id' => $transaction->id,
            'type' => 'credit',
            'sub_type' => self::SUB_TYPE,
            'map_type' => 'inv_adj_offset',
            'operation_date' => $op,
            'created_by' => $uid,
            'note' => $note,
            'location_id' => $transaction->location_id,
        ]);
    }
}
