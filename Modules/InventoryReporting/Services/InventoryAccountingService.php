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
 * when Accounting is enabled and accounts are configured.
 *
 * Inventory asset account: Accounting settings `inventory_asset_account_id` if set, else location Purchases deposit_to.
 * Inventory adjustment account: Accounting settings `inventory_adjustment_account_id` if set, else InventoryReporting per-location offset.
 * Both legs must be resolved and must differ, or no lines are posted.
 *
 * Decrease stock (stock_adjustment): Dr inventory adjustment account, Cr inventory asset (reduces inventory on the books).
 * Increase stock (opening_stock): Dr inventory asset, Cr inventory adjustment account.
 *
 * Stock transfer (sell_transfer + purchase_transfer): Cr inventory asset at sending location, Dr inventory asset at
 * receiving location (two lines). Same `accounting_account_id` with different `location_id` when both locations use the
 * global inventory account; otherwise distinct per-location deposit_to accounts.
 */
class InventoryAccountingService
{
    public const SUB_TYPE = 'inv_stock_adjustment';

    public const SUB_TYPE_TRANSFER = 'inv_stock_transfer';

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
     * @return array{0: ?int, 1: ?int} [inventory_asset_account_id, inventory_adjustment_account_id]
     */
    public function resolveAccountsForLocation(int $businessId, int $locationId): array
    {
        $settings = $this->accountingUtil->getAccountingSettings($businessId);
        $invFromSettings = (int) ($settings['inventory_asset_account_id'] ?? 0);
        $adjFromSettings = (int) ($settings['inventory_adjustment_account_id'] ?? 0);

        $location = BusinessLocation::where('business_id', $businessId)->where('id', $locationId)->first();
        if (! $location) {
            return [null, null];
        }

        $map = json_decode($location->accounting_default_map, true) ?: [];
        $invFromLocation = isset($map['purchases']['deposit_to']) ? (int) $map['purchases']['deposit_to'] : null;

        $invReporting = InventoryReportingLocationSetting::where('business_id', $businessId)
            ->where('location_id', $locationId)
            ->first();
        $adjFromLocation = $invReporting && $invReporting->inventory_adjustment_offset_account_id
            ? (int) $invReporting->inventory_adjustment_offset_account_id
            : null;

        $inventoryAsset = $this->accountingUtil->isValidBusinessAccount($businessId, $invFromSettings)
            ? $invFromSettings
            : ($invFromLocation ?: null);

        $adjustmentAccount = $this->accountingUtil->isValidBusinessAccount($businessId, $adjFromSettings)
            ? $adjFromSettings
            : ($adjFromLocation ?: null);

        if (! $inventoryAsset || ! $adjustmentAccount || $inventoryAsset === $adjustmentAccount) {
            return [null, null];
        }

        return [$inventoryAsset, $adjustmentAccount];
    }

    /**
     * Inventory asset GL only (per location), without requiring the adjustment account.
     */
    public function resolveInventoryAssetAccountId(int $businessId, int $locationId): ?int
    {
        $settings = $this->accountingUtil->getAccountingSettings($businessId);
        $invFromSettings = (int) ($settings['inventory_asset_account_id'] ?? 0);

        $location = BusinessLocation::where('business_id', $businessId)->where('id', $locationId)->first();
        if (! $location) {
            return null;
        }

        $map = json_decode($location->accounting_default_map, true) ?: [];
        $invFromLocation = isset($map['purchases']['deposit_to']) ? (int) $map['purchases']['deposit_to'] : null;

        $inventoryAsset = $this->accountingUtil->isValidBusinessAccount($businessId, $invFromSettings)
            ? $invFromSettings
            : ($invFromLocation ?: null);

        return $inventoryAsset ?: null;
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

    public function removeStockTransferBySellTransferId(int $businessId, int $sellTransferId): void
    {
        if (! class_exists(AccountingAccountsTransaction::class)) {
            return;
        }

        $rows = AccountingAccountsTransaction::query()
            ->where('transaction_id', $sellTransferId)
            ->where('sub_type', self::SUB_TYPE_TRANSFER)
            ->get();

        foreach ($rows as $row) {
            if ($this->accountingUtil->isOperationDateLocked($businessId, $row->operation_date)) {
                \Log::warning('InventoryReporting: cannot remove stock transfer accounting lines (period locked)', [
                    'transaction_id' => $sellTransferId,
                ]);

                return;
            }
        }

        AccountingAccountsTransaction::query()
            ->where('transaction_id', $sellTransferId)
            ->where('sub_type', self::SUB_TYPE_TRANSFER)
            ->delete();
    }

    /**
     * Inter-location stock transfer: two lines on the sell_transfer (parent) transaction_id.
     * Credit inventory asset at sending location; debit inventory asset at receiving location.
     */
    public function postStockTransfer(Transaction $sellTransfer, ?int $userId = null): void
    {
        if (! $this->shouldPost((int) $sellTransfer->business_id)) {
            return;
        }

        if ($sellTransfer->type !== 'sell_transfer') {
            return;
        }

        $businessId = (int) $sellTransfer->business_id;
        $sellId = (int) $sellTransfer->id;

        $purchaseTransfer = Transaction::query()
            ->where('business_id', $businessId)
            ->where('transfer_parent_id', $sellId)
            ->where('type', 'purchase_transfer')
            ->first();

        if (! $purchaseTransfer) {
            return;
        }

        $isCompleted = $sellTransfer->status === 'final' && $purchaseTransfer->status === 'received';

        $amount = (float) $sellTransfer->final_total;

        if (! $isCompleted || $amount <= 0) {
            $this->removeStockTransferBySellTransferId($businessId, $sellId);

            return;
        }

        $fromLoc = (int) $sellTransfer->location_id;
        $toLoc = (int) $purchaseTransfer->location_id;

        $invFrom = $this->resolveInventoryAssetAccountId($businessId, $fromLoc);
        $invTo = $this->resolveInventoryAssetAccountId($businessId, $toLoc);

        if (! $invFrom || ! $invTo) {
            return;
        }

        $op = \Carbon\Carbon::parse($sellTransfer->transaction_date);
        try {
            $this->accountingUtil->assertOperationDateNotLocked($businessId, $op);
        } catch (\Throwable $e) {
            \Log::warning('InventoryReporting accounting skip: '.$e->getMessage());

            return;
        }

        $this->removeStockTransferBySellTransferId($businessId, $sellId);

        $uid = $userId ?: (int) ($sellTransfer->created_by ?? 0);
        $refLabel = $sellTransfer->ref_no ? (string) $sellTransfer->ref_no : ('#'.$sellId);
        $note = __('inventoryreporting::lang.accounting_note_stock_transfer', ['ref' => $refLabel]);

        AccountingAccountsTransaction::createTransaction([
            'amount' => $amount,
            'accounting_account_id' => $invFrom,
            'transaction_id' => $sellId,
            'type' => 'credit',
            'sub_type' => self::SUB_TYPE_TRANSFER,
            'map_type' => 'inv_transfer_from',
            'operation_date' => $op,
            'created_by' => $uid,
            'note' => $note,
            'location_id' => $fromLoc,
        ]);

        AccountingAccountsTransaction::createTransaction([
            'amount' => $amount,
            'accounting_account_id' => $invTo,
            'transaction_id' => $sellId,
            'type' => 'debit',
            'sub_type' => self::SUB_TYPE_TRANSFER,
            'map_type' => 'inv_transfer_to',
            'operation_date' => $op,
            'created_by' => $uid,
            'note' => $note,
            'location_id' => $toLoc,
        ]);
    }

    /**
     * Stock reduction: Dr inventory adjustment account, Cr inventory asset (inventory GL is credited / reduced).
     */
    public function postStockDecrease(Transaction $transaction, ?int $userId = null): void
    {
        if (! $this->shouldPost((int) $transaction->business_id)) {
            return;
        }

        $amount = (float) $transaction->final_total;
        $businessId = (int) $transaction->business_id;
        $txId = (int) $transaction->id;

        if ($amount <= 0) {
            $this->removeByTransactionId($businessId, $txId);

            return;
        }

        [$inventoryAsset, $adjustmentAccount] = $this->resolveAccountsForLocation((int) $transaction->business_id, (int) $transaction->location_id);
        if (! $inventoryAsset || ! $adjustmentAccount) {
            return;
        }

        $op = \Carbon\Carbon::parse($transaction->transaction_date);
        try {
            $this->accountingUtil->assertOperationDateNotLocked($businessId, $op);
        } catch (\Throwable $e) {
            \Log::warning('InventoryReporting accounting skip: '.$e->getMessage());

            return;
        }

        $this->removeByTransactionId($businessId, $txId);

        $uid = $userId ?: (int) ($transaction->created_by ?? 0);
        $refLabel = $transaction->ref_no ? (string) $transaction->ref_no : ('#'.$transaction->id);
        $note = __('inventoryreporting::lang.accounting_note_stock_decrease', ['ref' => $refLabel]);

        AccountingAccountsTransaction::createTransaction([
            'amount' => $amount,
            'accounting_account_id' => $adjustmentAccount,
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
        $businessId = (int) $transaction->business_id;
        $txId = (int) $transaction->id;

        if ($amount <= 0) {
            $this->removeByTransactionId($businessId, $txId);

            return;
        }

        [$inventoryAsset, $adjustmentAccount] = $this->resolveAccountsForLocation((int) $transaction->business_id, (int) $transaction->location_id);
        if (! $inventoryAsset || ! $adjustmentAccount) {
            return;
        }

        $op = \Carbon\Carbon::parse($transaction->transaction_date);
        try {
            $this->accountingUtil->assertOperationDateNotLocked($businessId, $op);
        } catch (\Throwable $e) {
            \Log::warning('InventoryReporting accounting skip: '.$e->getMessage());

            return;
        }

        $this->removeByTransactionId($businessId, $txId);

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
            'accounting_account_id' => $adjustmentAccount,
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
