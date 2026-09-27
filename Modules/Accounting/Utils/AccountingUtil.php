<?php

namespace Modules\Accounting\Utils;

use App\Business;
use App\BusinessLocation;
use App\Transaction;
use App\TransactionPayment;
use App\TransactionSellLine;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\PurchaseController;
use App\Http\Controllers\SellController;
use App\Http\Controllers\OpeningStockController;
use App\Http\Controllers\StockAdjustmentController;
use App\Http\Controllers\StockTransferController;
use App\Http\Controllers\SellReturnController;
use App\Utils\Util;
use DB;
use Modules\Accounting\Entities\AccountingAccountsTransaction;
use Modules\Accounting\Entities\AccountingFixedAsset;

class AccountingUtil extends Util
{
    public const JOURNAL_BALANCE_TOLERANCE = 0.0001;

    public const MAP_TYPE_INVENTORY_PURCHASE_ASSET = 'inventory_purchase_asset';
    public const MAP_TYPE_INVENTORY_PURCHASE_OFFSET = 'inventory_purchase_offset';
    public const MAP_TYPE_INVENTORY_SELL_COGS = 'inventory_sell_cogs';
    public const MAP_TYPE_INVENTORY_SELL_ASSET = 'inventory_sell_asset';
    public const MAP_TYPE_PURCHASE_DISCOUNT_RECEIVED = 'purchase_discount_received';
    public const MAP_TYPE_SELL_DISCOUNT_APPLIED = 'sell_discount_applied';

    public const MAP_TYPE_SELL_RETURN_INVENTORY_ASSET = 'sell_return_inventory_asset';

    public const MAP_TYPE_SELL_RETURN_COGS = 'sell_return_cogs_reversal';

    public const MAP_TYPE_SELL_RETURN_CONTRA_REVENUE = 'sell_return_contra_revenue';

    public const MAP_TYPE_SELL_RETURN_AR = 'sell_return_ar';

    public function balanceFormula($accounting_accounts_alias = 'accounting_accounts',
                                 $accounting_account_transaction_alias = 'AAT')
    {
        return "SUM( IF(
            ($accounting_accounts_alias.account_primary_type='asset' AND $accounting_account_transaction_alias.type='debit')
            OR ($accounting_accounts_alias.account_primary_type IN ('expense', 'expenses') AND $accounting_account_transaction_alias.type='debit')
            OR ($accounting_accounts_alias.account_primary_type='income' AND $accounting_account_transaction_alias.type='credit')
            OR ($accounting_accounts_alias.account_primary_type='equity' AND $accounting_account_transaction_alias.type='credit')
            OR ($accounting_accounts_alias.account_primary_type='liability' AND $accounting_account_transaction_alias.type='credit'), 
            amount, -1*amount)) as balance";
    }

    public function getAccountingSettings($business_id)
    {
        $accounting_settings = Business::where('id', $business_id)
                                ->value('accounting_settings');

        $accounting_settings = ! empty($accounting_settings) ? json_decode($accounting_settings, true) : [];

        return $accounting_settings;
    }

    public function getAgeingReport($business_id, $type, $group_by, $location_id = null)
    {
        $today = \Carbon::now()->format('Y-m-d');
        $query = Transaction::where('transactions.business_id', $business_id);

        if ($type == 'sell') {
            $query->where('transactions.type', 'sell')
            ->where('transactions.status', 'final');
        } elseif ($type == 'purchase') {
            $query->where('transactions.type', 'purchase')
                ->where('transactions.status', 'received');
        }

        if (! empty($location_id)) {
            $query->where('transactions.location_id', $location_id);
        }

        $dues = $query->whereNotNull('transactions.pay_term_number')
                ->whereIn('transactions.payment_status', ['partial', 'due'])
                ->join('contacts as c', 'c.id', '=', 'transactions.contact_id')
                ->select(
                    DB::raw(
                        'DATEDIFF(
                            "'.$today.'", 
                            IF(
                                transactions.pay_term_type="days",
                                DATE_ADD(transactions.transaction_date, INTERVAL transactions.pay_term_number DAY),
                                DATE_ADD(transactions.transaction_date, INTERVAL transactions.pay_term_number MONTH)
                            )
                        ) as diff'
                    ),
                    DB::raw('SUM(transactions.final_total - 
                        (SELECT COALESCE(SUM(IF(tp.is_return = 1, -1*tp.amount, tp.amount)), 0) 
                        FROM transaction_payments as tp WHERE tp.transaction_id = transactions.id) )  
                        as total_due'),

                    'c.name as contact_name',
                    'transactions.contact_id',
                    'transactions.invoice_no',
                    'transactions.ref_no',
                    'transactions.transaction_date',
                    DB::raw('IF(
                        transactions.pay_term_type="days",
                        DATE_ADD(transactions.transaction_date, INTERVAL transactions.pay_term_number DAY),
                        DATE_ADD(transactions.transaction_date, INTERVAL transactions.pay_term_number MONTH)
                    ) as due_date')
                )
                ->groupBy('transactions.id')
                ->get();

        $report_details = [];
        if ($group_by == 'contact') {
            foreach ($dues as $due) {
                if (! isset($report_details[$due->contact_id])) {
                    $report_details[$due->contact_id] = [
                        'name' => $due->contact_name,
                        '<1' => 0,
                        '1_30' => 0,
                        '31_60' => 0,
                        '61_90' => 0,
                        '>90' => 0,
                        'total_due' => 0,
                    ];
                }

                if ($due->diff < 1) {
                    $report_details[$due->contact_id]['<1'] += $due->total_due;
                } elseif ($due->diff >= 1 && $due->diff <= 30) {
                    $report_details[$due->contact_id]['1_30'] += $due->total_due;
                } elseif ($due->diff >= 31 && $due->diff <= 60) {
                    $report_details[$due->contact_id]['31_60'] += $due->total_due;
                } elseif ($due->diff >= 61 && $due->diff <= 90) {
                    $report_details[$due->contact_id]['61_90'] += $due->total_due;
                } elseif ($due->diff > 90) {
                    $report_details[$due->contact_id]['>90'] += $due->total_due;
                }

                $report_details[$due->contact_id]['total_due'] += $due->total_due;
            }
        } elseif ($group_by == 'due_date') {
            $report_details = [
                'current' => [],
                '1_30' => [],
                '31_60' => [],
                '61_90' => [],
                '>90' => [],
            ];
            foreach ($dues as $due) {
                $temp_array = [
                    'transaction_date' => $this->format_date($due->transaction_date),
                    'due_date' => $this->format_date($due->due_date),
                    'ref_no' => $due->ref_no,
                    'invoice_no' => $due->invoice_no,
                    'contact_name' => $due->contact_name,
                    'due' => $due->total_due,
                ];
                if ($due->diff < 1) {
                    $report_details['current'][] = $temp_array;
                } elseif ($due->diff >= 1 && $due->diff <= 30) {
                    $report_details['1_30'][] = $temp_array;
                } elseif ($due->diff >= 31 && $due->diff <= 60) {
                    $report_details['31_60'][] = $temp_array;
                } elseif ($due->diff >= 61 && $due->diff <= 90) {
                    $report_details['61_90'][] = $temp_array;
                } elseif ($due->diff > 90) {
                    $report_details['>90'][] = $temp_array;
                }
            }
        }

        return $report_details;
    }

    /**
     * Dates on or before this day (inclusive) are locked for posting.
     */
    public function isOperationDateLocked($business_id, $operationDate): bool
    {
        $settings = $this->getAccountingSettings($business_id);
        $lockEnd = $settings['accounting_period_lock_end'] ?? null;
        if (empty($lockEnd)) {
            return false;
        }

        $lock = \Carbon\Carbon::parse($lockEnd)->startOfDay();
        $op = \Carbon\Carbon::parse($operationDate)->startOfDay();

        return $op->lte($lock);
    }

    /**
     * @throws \RuntimeException
     */
    public function assertOperationDateNotLocked($business_id, $operationDate): void
    {
        if ($this->isOperationDateLocked($business_id, $operationDate)) {
            throw new \RuntimeException(__('accounting::lang.period_locked'));
        }
    }

    /**
     * Ensures journal lines have at least one amount and total debits equal total credits.
     *
     * @param  array<string|int, mixed>|null  $accountIds
     * @param  array<string|int, mixed>|null  $debits
     * @param  array<string|int, mixed>|null  $credits
     *
     * @throws \RuntimeException
     */
    public function assertJournalEntryLinesBalanced($accountIds, $debits, $credits): void
    {
        $accountIds = is_array($accountIds) ? $accountIds : [];
        $debits = is_array($debits) ? $debits : [];
        $credits = is_array($credits) ? $credits : [];

        $totalDebit = 0.0;
        $totalCredit = 0.0;

        foreach ($accountIds as $index => $accountId) {
            if (empty($accountId)) {
                continue;
            }

            $creditAmount = $this->num_uf($credits[$index] ?? '');
            $debitAmount = $this->num_uf($debits[$index] ?? '');

            if ($creditAmount <= 0 && $debitAmount <= 0) {
                continue;
            }

            if ($creditAmount > 0 && $debitAmount > 0) {
                throw new \RuntimeException(__('accounting::lang.journal_line_debit_credit_exclusive'));
            }

            if ($creditAmount > 0) {
                $totalCredit += $creditAmount;
            } else {
                $totalDebit += $debitAmount;
            }
        }

        if ($totalDebit <= 0 && $totalCredit <= 0) {
            throw new \RuntimeException(__('accounting::lang.journal_requires_lines'));
        }

        if (abs($totalDebit - $totalCredit) > self::JOURNAL_BALANCE_TOLERANCE) {
            throw new \RuntimeException(__('accounting::lang.credit_debit_equal'));
        }
    }

    /**
     * HTML description for a ledger / GL line (shared by account ledger and bank reconciliation picker).
     */
    public function ledgerLineDescriptionHtml(object $row): string
    {
        $description = '';

        if (($row->sub_type ?? null) === 'journal_entry') {
            $description = '<b>'.e(__('accounting::lang.journal_entry')).'</b>';
            $description .= '<br>'.e(__('purchase.ref_no')).': '.e((string) ($row->a_ref ?? ''));
            $description .= '<br>'.e(__('lang_v1.description')).': '.e((string) ($row->aat_note ?? ''));
        }

        if (($row->sub_type ?? null) === 'opening_balance') {
            $description = '<b>'.e(__('accounting::lang.opening_balance')).'</b>';
            $description .= '<br>'.e(__('lang_v1.description')).': '.e((string) ($row->aat_note ?? ''));
        }

        if (($row->sub_type ?? null) === 'sell') {
            $description = '<b>'.e(__('sale.sale')).'</b>';
            $description .= '<br>'.e(__('sale.invoice_no')).': '.e((string) ($row->invoice_no ?? ''));
            $description .= '<br>'.e(__('lang_v1.description')).': '.e((string) ($row->aat_note ?? ''));
        }

        if (($row->sub_type ?? null) === 'sell_return') {
            $description = '<b>'.e(__('lang_v1.sell_return')).'</b>';
            $description .= '<br>'.e(__('sale.invoice_no')).': '.e((string) ($row->invoice_no ?? ''));
            if (trim((string) ($row->aat_note ?? '')) !== '') {
                $description .= '<br>'.e(__('lang_v1.description')).': '.e((string) $row->aat_note);
            }
        }

        if (($row->sub_type ?? null) === 'purchase') {
            $description = '<b>'.e(__('lang_v1.purchase')).'</b>';
            $description .= '<br>'.e(__('purchase.ref_no')).': '.e((string) ($row->ref_no ?? ''));
            $description .= '<br>'.e(__('lang_v1.description')).': '.e((string) ($row->aat_note ?? ''));
        }

        if (($row->sub_type ?? null) === 'sell_payment') {
            $description = '<b>'.e(__('accounting::lang.ledger_payment_sale')).'</b>';
            if (trim((string) ($row->invoice_no ?? '')) !== '') {
                $description .= '<br>'.e(__('sale.invoice_no')).': '.e((string) $row->invoice_no);
            }
            if (trim((string) ($row->ref_no ?? '')) !== '') {
                $description .= '<br>'.e(__('purchase.ref_no')).': '.e((string) $row->ref_no);
            }
            if (trim((string) ($row->payment_ref_no ?? '')) !== '') {
                $description .= '<br>'.e(__('accounting::lang.ledger_payment_reference')).': '.e((string) $row->payment_ref_no);
            }
            if (trim((string) ($row->payment_method ?? '')) !== '') {
                $description .= '<br>'.e(__('purchase.payment_method')).': '.e(ucwords(str_replace('_', ' ', (string) $row->payment_method)));
            }
            if (trim((string) ($row->payment_note ?? '')) !== '') {
                $description .= '<br>'.e(__('purchase.payment_note')).': '.e((string) $row->payment_note);
            }
            if (trim((string) ($row->aat_note ?? '')) !== '') {
                $description .= '<br>'.e(__('lang_v1.description')).': '.e((string) $row->aat_note);
            }
        }

        if (($row->sub_type ?? null) === 'purchase_payment') {
            $description = '<b>'.e(__('accounting::lang.ledger_payment_purchase')).'</b>';
            if (trim((string) ($row->ref_no ?? '')) !== '') {
                $description .= '<br>'.e(__('purchase.ref_no')).': '.e((string) $row->ref_no);
            }
            if (trim((string) ($row->invoice_no ?? '')) !== '') {
                $description .= '<br>'.e(__('sale.invoice_no')).': '.e((string) $row->invoice_no);
            }
            if (trim((string) ($row->payment_ref_no ?? '')) !== '') {
                $description .= '<br>'.e(__('accounting::lang.ledger_payment_reference')).': '.e((string) $row->payment_ref_no);
            }
            if (trim((string) ($row->payment_method ?? '')) !== '') {
                $description .= '<br>'.e(__('purchase.payment_method')).': '.e(ucwords(str_replace('_', ' ', (string) $row->payment_method)));
            }
            if (trim((string) ($row->payment_note ?? '')) !== '') {
                $description .= '<br>'.e(__('purchase.payment_note')).': '.e((string) $row->payment_note);
            }
            if (trim((string) ($row->aat_note ?? '')) !== '') {
                $description .= '<br>'.e(__('lang_v1.description')).': '.e((string) $row->aat_note);
            }
        }

        if (($row->sub_type ?? null) === 'expense') {
            $description = '<b>'.e(__('accounting::lang.expense')).'</b>';
            $description .= '<br>'.e(__('purchase.ref_no')).': '.e((string) ($row->ref_no ?? ''));
            $description .= '<br>'.e(__('lang_v1.description')).': '.e((string) ($row->aat_note ?? ''));
        }

        if (($row->sub_type ?? null) === 'inv_stock_adjustment') {
            $isOpening = ($row->source_transaction_type ?? null) === 'opening_stock';
            $description = '<b>'.e($isOpening
                ? __('accounting::lang.ledger_opening_stock')
                : __('accounting::lang.ledger_stock_adjustment')).'</b>';
            if (trim((string) ($row->ref_no ?? '')) !== '') {
                $description .= '<br>'.e(__('purchase.ref_no')).': '.e((string) $row->ref_no);
            }
            if (trim((string) ($row->aat_note ?? '')) !== '') {
                $description .= '<br>'.e(__('lang_v1.description')).': '.e((string) $row->aat_note);
            }
        }

        if (($row->sub_type ?? null) === 'inv_stock_transfer') {
            $description = '<b>'.e(__('accounting::lang.ledger_stock_transfer')).'</b>';
            if (trim((string) ($row->ref_no ?? '')) !== '') {
                $description .= '<br>'.e(__('purchase.ref_no')).': '.e((string) $row->ref_no);
            }
            if (trim((string) ($row->aat_note ?? '')) !== '') {
                $description .= '<br>'.e(__('lang_v1.description')).': '.e((string) $row->aat_note);
            }
        }

        if (($row->sub_type ?? null) === 'fixed_asset_depreciation') {
            $description = '<b>'.e(__('accounting::lang.fixed_asset_depreciation')).'</b>';
            $description .= '<br>'.e(__('purchase.ref_no')).': '.e((string) ($row->a_ref ?? ''));
            $description .= '<br>'.e(__('lang_v1.description')).': '.e((string) ($row->note ?? ''));
        }

        if (($row->sub_type ?? null) === 'fixed_asset_acquisition') {
            $description = '<b>'.e(__('accounting::lang.journal_entry')).'</b>';
            $description .= '<br>'.e(__('purchase.ref_no')).': '.e((string) ($row->a_ref ?? ''));
            $description .= '<br>'.e((string) ($row->note ?? ''));
        }

        if (($row->sub_type ?? null) === 'fixed_asset_disposal') {
            $description = '<b>'.e(__('accounting::lang.journal_entry')).'</b>';
            $description .= '<br>'.e(__('purchase.ref_no')).': '.e((string) ($row->a_ref ?? ''));
            $description .= '<br>'.e((string) ($row->note ?? ''));
        }

        if ($description === '' && (
            trim((string) ($row->aat_note ?? '')) !== ''
            || trim((string) ($row->note ?? '')) !== ''
            || trim((string) ($row->a_ref ?? '')) !== ''
        )) {
            $description = e((string) ($row->aat_note ?: ($row->note ?? '') ?: ($row->a_ref ?? '')));
        }

        return $description;
    }

    /**
     * Compact link to open the source document (journal, transfer, POS transaction, fixed asset, etc.).
     */
    public function ledgerLineDocumentLinkHtml(object $row): string
    {
        $user = auth()->user();
        if (! $user) {
            return '';
        }

        $mappingType = $row->mapping_type ?? null;
        $subType = $row->sub_type ?? null;
        $mappingId = isset($row->mapping_id) ? (int) $row->mapping_id : (isset($row->acc_trans_mapping_id) ? (int) $row->acc_trans_mapping_id : 0);
        $fixedAssetId = isset($row->fixed_asset_id) ? (int) $row->fixed_asset_id : null;
        $sourceTxnId = isset($row->source_transaction_id) ? (int) $row->source_transaction_id : null;
        $transactionId = isset($row->transaction_id) ? (int) $row->transaction_id : null;
        $href = null;
        /** @var bool Sell/Purchase show views are modal HTML fragments; load via .btn-modal + .view_modal like the rest of the app */
        $loadInViewModal = false;
        $label = __('accounting::lang.view_source_document');

        if ($mappingType === 'journal_entry' && $mappingId > 0 && $user->can('accounting.edit_journal')) {
            $href = route('journal-entry.edit', $mappingId);
        } elseif ($mappingType === 'transfer' && $mappingId > 0 && $user->can('accounting.edit_transfer')) {
            $href = route('transfer.edit', $mappingId);
        } elseif ($fixedAssetId !== null && $fixedAssetId > 0
            && in_array($mappingType, ['fixed_asset_depreciation', 'fixed_asset_acquisition', 'fixed_asset_disposal'], true)
            && $user->can('accounting.view_fixed_assets')) {
            $href = route('accounting.fixedAssets.show', $fixedAssetId);
        } elseif ($sourceTxnId !== null && $sourceTxnId > 0) {
            if (in_array($subType, ['sell', 'sell_payment'], true) && ($user->can('sell.view') || $user->can('sell.create') || $user->can('direct_sell.access') || $user->can('view_own_sell_only'))) {
                $href = action([SellController::class, 'show'], $sourceTxnId);
                $loadInViewModal = true;
            } elseif (in_array($subType, ['purchase', 'purchase_payment'], true) && ($user->can('purchase.view') || $user->can('purchase.create') || $user->can('view_own_purchase'))) {
                $href = action([PurchaseController::class, 'show'], $sourceTxnId);
                $loadInViewModal = true;
            } elseif ($subType === 'inv_stock_adjustment' && $user->can('purchase.view')) {
                if (($row->source_transaction_type ?? null) === 'opening_stock') {
                    $pid = (int) ($row->opening_stock_product_id ?? 0);
                    if ($pid > 0 && $user->can('product.opening_stock')) {
                        $href = action([OpeningStockController::class, 'add'], $pid);
                        $loadInViewModal = true;
                    }
                } else {
                    $href = action([StockAdjustmentController::class, 'show'], $sourceTxnId);
                    $loadInViewModal = true;
                }
            } elseif ($subType === 'inv_stock_transfer' && $user->can('purchase.view')) {
                $href = action([StockTransferController::class, 'show'], $sourceTxnId);
                $loadInViewModal = true;
            } elseif ($subType === 'sell_return' && ($user->can('access_sell_return') || $user->can('access_own_sell_return'))) {
                $href = action([SellReturnController::class, 'show'], $sourceTxnId);
                $loadInViewModal = true;
            }
        }

        if ($href === null && $subType === 'expense' && $transactionId !== null && $transactionId > 0
            && ($user->can('expense.edit') || $user->can('expense.add'))) {
            $href = action([ExpenseController::class, 'edit'], $transactionId);
        }

        if ($href === null) {
            return '';
        }

        if ($loadInViewModal) {
            return '<a href="#" class="btn-modal tw-text-sm tw-text-blue-600" data-href="'.e($href).'" data-container=".view_modal">'
                .'<i class="fas fa-eye" aria-hidden="true"></i> '.e($label).'</a>';
        }

        return '<a href="'.e($href).'" class="tw-text-sm tw-text-blue-600"><i class="fas fa-external-link-alt" aria-hidden="true"></i> '.e($label).'</a>';
    }

    /**
     * Delete payment / sale / purchase map lines. Returns false if period is locked.
     */
    public function deleteMap($business_id, $transaction_id, $transaction_payment_id): bool
    {
        $accountIds = DB::table('accounting_accounts')
            ->where('business_id', $business_id)
            ->pluck('id');

        $mapTypes = [
            'payment_account',
            'deposit_to',
            self::MAP_TYPE_PURCHASE_DISCOUNT_RECEIVED,
            self::MAP_TYPE_SELL_DISCOUNT_APPLIED,
        ];

        $q = AccountingAccountsTransaction::query()
            ->whereIn('map_type', $mapTypes)
            ->whereIn('accounting_account_id', $accountIds);

        if (! empty($transaction_payment_id)) {
            $q->where('transaction_payment_id', $transaction_payment_id);
        } else {
            $q->where('transaction_id', $transaction_id)
                ->whereNull('transaction_payment_id');
        }

        foreach ($q->get() as $row) {
            if ($this->isOperationDateLocked($business_id, $row->operation_date)) {
                return false;
            }
        }

        $del = AccountingAccountsTransaction::query()
            ->whereIn('map_type', $mapTypes)
            ->whereIn('accounting_account_id', $accountIds);

        if (! empty($transaction_payment_id)) {
            $del->where('transaction_payment_id', $transaction_payment_id);
        } else {
            $del->where('transaction_id', $transaction_id)
                ->whereNull('transaction_payment_id');
        }

        $del->delete();

        return true;
    }

    /**
     * Delete inventory valuation map lines for a transaction.
     */
    public function deleteInventoryMap(int $business_id, int $transaction_id): bool
    {
        $accountIds = DB::table('accounting_accounts')
            ->where('business_id', $business_id)
            ->pluck('id');

        $mapTypes = [
            self::MAP_TYPE_INVENTORY_PURCHASE_ASSET,
            self::MAP_TYPE_INVENTORY_PURCHASE_OFFSET,
            self::MAP_TYPE_INVENTORY_SELL_COGS,
            self::MAP_TYPE_INVENTORY_SELL_ASSET,
        ];

        $rows = AccountingAccountsTransaction::query()
            ->whereIn('map_type', $mapTypes)
            ->whereIn('accounting_account_id', $accountIds)
            ->where('transaction_id', $transaction_id)
            ->whereNull('transaction_payment_id')
            ->get();

        foreach ($rows as $row) {
            if ($this->isOperationDateLocked($business_id, $row->operation_date)) {
                return false;
            }
        }

        AccountingAccountsTransaction::query()
            ->whereIn('map_type', $mapTypes)
            ->whereIn('accounting_account_id', $accountIds)
            ->where('transaction_id', $transaction_id)
            ->whereNull('transaction_payment_id')
            ->delete();

        return true;
    }

    /**
     * Delete sales return GL lines (credit note) for a transaction.
     */
    public function deleteSellReturnMap(int $business_id, int $transaction_id): bool
    {
        $accountIds = DB::table('accounting_accounts')
            ->where('business_id', $business_id)
            ->pluck('id');

        $mapTypes = [
            self::MAP_TYPE_SELL_RETURN_INVENTORY_ASSET,
            self::MAP_TYPE_SELL_RETURN_COGS,
            self::MAP_TYPE_SELL_RETURN_CONTRA_REVENUE,
            self::MAP_TYPE_SELL_RETURN_AR,
        ];

        $rows = AccountingAccountsTransaction::query()
            ->whereIn('map_type', $mapTypes)
            ->whereIn('accounting_account_id', $accountIds)
            ->where('transaction_id', $transaction_id)
            ->whereNull('transaction_payment_id')
            ->get();

        foreach ($rows as $row) {
            if ($this->isOperationDateLocked($business_id, $row->operation_date)) {
                return false;
            }
        }

        AccountingAccountsTransaction::query()
            ->whereIn('map_type', $mapTypes)
            ->whereIn('accounting_account_id', $accountIds)
            ->where('transaction_id', $transaction_id)
            ->whereNull('transaction_payment_id')
            ->delete();

        return true;
    }

    /**
     * Post sales return accounting on the credit note transaction: Dr inventory / Cr COGS for returned cost;
     * Dr sales returns / Cr A/R (location sale deposit_to) for the return total.
     */
    public function saveSellReturnAccounting(Transaction $sellReturn, ?int $user_id = null): bool
    {
        if ($sellReturn->type !== 'sell_return' || $sellReturn->status !== 'final') {
            return $this->deleteSellReturnMap((int) $sellReturn->business_id, (int) $sellReturn->id);
        }

        $business_id = (int) $sellReturn->business_id;
        $sell_return_id = (int) $sellReturn->id;
        $operation_date = \Carbon\Carbon::parse($sellReturn->transaction_date);

        if ($this->isOperationDateLocked($business_id, $operation_date)) {
            return false;
        }

        if (! $this->deleteSellReturnMap($business_id, $sell_return_id)) {
            return false;
        }

        $parent_id = (int) $sellReturn->return_parent_id;
        if ($parent_id <= 0) {
            return true;
        }

        $settings = $this->getAccountingSettings($business_id);
        $inventoryAccountId = (int) ($settings['inventory_asset_account_id'] ?? 0);
        $cogsAccountId = (int) ($settings['inventory_cogs_account_id'] ?? 0);
        $salesReturnAccountId = (int) ($settings['sales_return_account_id'] ?? 0);

        $location = BusinessLocation::find($sellReturn->location_id);
        $defaultMap = $location ? json_decode((string) $location->accounting_default_map, true) : [];
        $arAccountId = (int) ($defaultMap['sale']['deposit_to'] ?? 0);

        $costAmount = (float) DB::table('transaction_sell_lines_purchase_lines as tspl')
            ->join('transaction_sell_lines as tsl', 'tsl.id', '=', 'tspl.sell_line_id')
            ->join('purchase_lines as pl', 'pl.id', '=', 'tspl.purchase_line_id')
            ->join('products as p', 'p.id', '=', 'tsl.product_id')
            ->where('tsl.transaction_id', $parent_id)
            ->where('p.enable_stock', 1)
            ->sum(DB::raw('COALESCE(tspl.qty_returned, 0) * pl.purchase_price_inc_tax'));

        $revenueAmount = (float) $sellReturn->final_total;
        $createdBy = (int) ($user_id ?: ($sellReturn->created_by ?? 1));

        $postCost = $costAmount > 0
            && $this->isValidBusinessAccount($business_id, $inventoryAccountId)
            && $this->isValidBusinessAccount($business_id, $cogsAccountId);

        $postRevenue = $revenueAmount > 0
            && $this->isValidBusinessAccount($business_id, $salesReturnAccountId)
            && $this->isValidBusinessAccount($business_id, $arAccountId);

        if (! $postCost && ! $postRevenue) {
            return true;
        }

        if ($postCost) {
            AccountingAccountsTransaction::updateOrCreateMapTransaction([
                'accounting_account_id' => $inventoryAccountId,
                'transaction_id' => $sell_return_id,
                'transaction_payment_id' => null,
                'amount' => $costAmount,
                'type' => 'debit',
                'sub_type' => 'sell_return',
                'note' => 'Sales return — inventory',
                'map_type' => self::MAP_TYPE_SELL_RETURN_INVENTORY_ASSET,
                'created_by' => $createdBy,
                'operation_date' => $operation_date,
                'location_id' => $sellReturn->location_id,
            ]);

            AccountingAccountsTransaction::updateOrCreateMapTransaction([
                'accounting_account_id' => $cogsAccountId,
                'transaction_id' => $sell_return_id,
                'transaction_payment_id' => null,
                'amount' => $costAmount,
                'type' => 'credit',
                'sub_type' => 'sell_return',
                'note' => 'Sales return — COGS reversal',
                'map_type' => self::MAP_TYPE_SELL_RETURN_COGS,
                'created_by' => $createdBy,
                'operation_date' => $operation_date,
                'location_id' => $sellReturn->location_id,
            ]);
        }

        if ($postRevenue) {
            AccountingAccountsTransaction::updateOrCreateMapTransaction([
                'accounting_account_id' => $salesReturnAccountId,
                'transaction_id' => $sell_return_id,
                'transaction_payment_id' => null,
                'amount' => $revenueAmount,
                'type' => 'debit',
                'sub_type' => 'sell_return',
                'note' => 'Sales return — contra revenue',
                'map_type' => self::MAP_TYPE_SELL_RETURN_CONTRA_REVENUE,
                'created_by' => $createdBy,
                'operation_date' => $operation_date,
                'location_id' => $sellReturn->location_id,
            ]);

            AccountingAccountsTransaction::updateOrCreateMapTransaction([
                'accounting_account_id' => $arAccountId,
                'transaction_id' => $sell_return_id,
                'transaction_payment_id' => null,
                'amount' => $revenueAmount,
                'type' => 'credit',
                'sub_type' => 'sell_return',
                'note' => 'Sales return — A/R',
                'map_type' => self::MAP_TYPE_SELL_RETURN_AR,
                'created_by' => $createdBy,
                'operation_date' => $operation_date,
                'location_id' => $sellReturn->location_id,
            ]);
        }

        return true;
    }

    /**
     * Total invoice discount: header (transaction) + line discounts for purchase or sell.
     */
    public function getTransactionDiscountTotal(Transaction $transaction): float
    {
        $header = $this->getTransactionHeaderDiscountAmount($transaction);
        $line = 0.0;
        if ($transaction->type === 'sell') {
            $line = $this->getSellLineDiscountTotal((int) $transaction->id);
        } elseif ($transaction->type === 'purchase') {
            $line = $this->getPurchaseLineDiscountTotal((int) $transaction->id);
        }

        return round($header + $line, 4);
    }

    protected function getTransactionHeaderDiscountAmount(Transaction $transaction): float
    {
        if (empty($transaction->discount_type) || (float) $transaction->discount_amount <= 0) {
            return 0.0;
        }
        if ($transaction->discount_type === 'fixed') {
            return (float) $transaction->discount_amount;
        }
        if ($transaction->discount_type === 'percentage') {
            return (float) $transaction->total_before_tax * (float) $transaction->discount_amount / 100;
        }

        return 0.0;
    }

    protected function getSellLineDiscountTotal(int $transactionId): float
    {
        $sum = 0.0;
        foreach (TransactionSellLine::where('transaction_id', $transactionId)->get() as $line) {
            $sum += (float) $line->get_discount_amount() * (float) $line->quantity;
        }

        return round($sum, 4);
    }

    protected function getPurchaseLineDiscountTotal(int $transactionId): float
    {
        $sum = 0.0;
        foreach (DB::table('purchase_lines')->where('transaction_id', $transactionId)->get() as $pl) {
            $qty = (float) $pl->quantity;
            if ($qty <= 0) {
                continue;
            }
            $pp = (float) $pl->pp_without_discount;
            $price = (float) $pl->purchase_price;
            if ($pp > 0 && $pp > $price) {
                $sum += $qty * ($pp - $price);
            } elseif ((float) $pl->discount_percent > 0 && $pp > 0) {
                $sum += $qty * $pp * (float) $pl->discount_percent / 100;
            }
        }

        return round($sum, 4);
    }

    /**
     * Stock receipt value for GL: line totals (qty × price inc tax) are already net of line discounts;
     * invoice-level (header) discount is allocated to stock lines in proportion to their share of all lines.
     */
    protected function getPurchaseStockReceiptAmountAfterDiscount(Transaction $transaction): float
    {
        $transactionId = (int) $transaction->id;
        $headerDiscount = $this->getTransactionHeaderDiscountAmount($transaction);

        $stockGross = 0.0;
        $allGross = 0.0;

        foreach (DB::table('purchase_lines as pl')
            ->join('products as p', 'p.id', '=', 'pl.product_id')
            ->where('pl.transaction_id', $transactionId)
            ->select('pl.quantity', 'pl.purchase_price_inc_tax', 'p.enable_stock')
            ->get() as $row) {
            $line = (float) $row->quantity * (float) $row->purchase_price_inc_tax;
            $allGross += $line;
            if ((int) $row->enable_stock === 1) {
                $stockGross += $line;
            }
        }

        if ($stockGross <= 0) {
            return 0.0;
        }

        if ($headerDiscount <= self::JOURNAL_BALANCE_TOLERANCE || $allGross <= 0) {
            return round($stockGross, 4);
        }

        $stockShare = $headerDiscount * ($stockGross / $allGross);

        return round(max(0.0, $stockGross - $stockShare), 4);
    }

    /**
     * Post or remove discount map line for purchase (credit discount received) or sell (debit discount applied).
     *
     * @return bool false if period lock prevents change
     */
    protected function syncDiscountMapForTransaction(
        Transaction $transaction,
        string $type,
        int $business_id,
        ?int $user_id,
        \Carbon\Carbon $operation_date,
        int $location_id,
        ?string $note
    ): bool {
        $mapType = $type === 'purchase'
            ? self::MAP_TYPE_PURCHASE_DISCOUNT_RECEIVED
            : self::MAP_TYPE_SELL_DISCOUNT_APPLIED;

        $accountIds = DB::table('accounting_accounts')
            ->where('business_id', $business_id)
            ->pluck('id');

        $existing = AccountingAccountsTransaction::query()
            ->where('transaction_id', $transaction->id)
            ->whereNull('transaction_payment_id')
            ->where('map_type', $mapType)
            ->whereIn('accounting_account_id', $accountIds)
            ->first();

        $settings = $this->getAccountingSettings($business_id);
        $discountAccountId = $type === 'purchase'
            ? (int) ($settings['discount_received_account_id'] ?? 0)
            : (int) ($settings['discount_applied_account_id'] ?? 0);

        $discountTotal = $this->getTransactionDiscountTotal($transaction);
        $post = $discountTotal > self::JOURNAL_BALANCE_TOLERANCE
            && $this->isValidBusinessAccount($business_id, $discountAccountId);

        if (! $post) {
            if ($existing !== null) {
                if ($this->isOperationDateLocked($business_id, $existing->operation_date)) {
                    return false;
                }
                AccountingAccountsTransaction::query()
                    ->where('id', $existing->id)
                    ->delete();
            }

            return true;
        }

        if ($existing !== null && $this->isOperationDateLocked($business_id, $existing->operation_date)) {
            return false;
        }

        $createdBy = (int) ($user_id ?: ($transaction->created_by ?? 1));
        $discNote = $type === 'purchase'
            ? 'Purchase discount received (auto)'
            : 'Sales discount applied (auto)';

        AccountingAccountsTransaction::updateOrCreateMapTransaction([
            'accounting_account_id' => $discountAccountId,
            'transaction_id' => (int) $transaction->id,
            'transaction_payment_id' => null,
            'amount' => $discountTotal,
            'type' => $type === 'purchase' ? 'credit' : 'debit',
            'sub_type' => $type,
            'note' => $note ?: $discNote,
            'map_type' => $mapType,
            'created_by' => $createdBy,
            'operation_date' => $operation_date,
            'location_id' => $location_id,
        ]);

        return true;
    }

    /**
     * @return bool false when period lock prevents posting
     */
    public function saveMap($type, $id, $user_id, $business_id, $deposit_to, $payment_account, $note = null)
    {
        $payment_data = null;
        $deposit_data = null;

        if ($type == 'sell') {
            $transaction = Transaction::where('business_id', $business_id)->where('id', $id)->firstOrFail();
            $operation_date = \Carbon\Carbon::parse($transaction->transaction_date);
            $location_id = $transaction->location_id;
            $created_by = $user_id ?: ($transaction->created_by ?? 1);

            if ($this->isOperationDateLocked($business_id, $operation_date)) {
                return false;
            }

            $finalTotal = (float) $transaction->final_total;
            $settings = $this->getAccountingSettings($business_id);
            $discTotal = $this->getTransactionDiscountTotal($transaction);
            $discAccountId = (int) ($settings['discount_applied_account_id'] ?? 0);
            $useDiscount = $discTotal > self::JOURNAL_BALANCE_TOLERANCE
                && $this->isValidBusinessAccount($business_id, $discAccountId);
            $paymentAmount = $useDiscount ? $finalTotal + $discTotal : $finalTotal;
            $depositAmount = $finalTotal;

            $payment_data = [
                'accounting_account_id' => $payment_account,
                'transaction_id' => $id,
                'transaction_payment_id' => null,
                'amount' => $paymentAmount,
                'type' => 'credit',
                'sub_type' => $type,
                'note' => $note,
                'map_type' => 'payment_account',
                'created_by' => $created_by,
                'operation_date' => $operation_date,
                'location_id' => $location_id,
            ];

            $deposit_data = [
                'accounting_account_id' => $deposit_to,
                'transaction_id' => $id,
                'transaction_payment_id' => null,
                'amount' => $depositAmount,
                'type' => 'debit',
                'sub_type' => $type,
                'note' => $note,
                'map_type' => 'deposit_to',
                'created_by' => $created_by,
                'operation_date' => $operation_date,
                'location_id' => $location_id,
            ];
        } elseif (in_array($type, ['purchase_payment', 'sell_payment'])) {
            $transaction_payment = TransactionPayment::where('id', $id)->where('business_id', $business_id)
                ->firstOrFail();
            $transaction = Transaction::where('business_id', $business_id)->where('id', $transaction_payment->transaction_id)->firstOrFail();
            $operation_date = \Carbon\Carbon::parse($transaction_payment->paid_on);
            $location_id = $transaction->location_id;
            $created_by = $user_id ?: ($transaction_payment->created_by ?? $transaction->created_by ?? 1);

            if ($this->isOperationDateLocked($business_id, $operation_date)) {
                return false;
            }

            $payment_data = [
                'accounting_account_id' => $payment_account,
                'transaction_id' => null,
                'transaction_payment_id' => $id,
                'amount' => $transaction_payment->amount,
                'type' => 'credit',
                'sub_type' => $type,
                'note' => $note,
                'map_type' => 'payment_account',
                'created_by' => $created_by,
                'operation_date' => $operation_date,
                'location_id' => $location_id,
            ];

            $deposit_data = [
                'accounting_account_id' => $deposit_to,
                'transaction_id' => null,
                'transaction_payment_id' => $id,
                'amount' => $transaction_payment->amount,
                'type' => 'debit',
                'sub_type' => $type,
                'note' => $note,
                'map_type' => 'deposit_to',
                'created_by' => $created_by,
                'operation_date' => $operation_date,
                'location_id' => $location_id,
            ];
        } elseif ($type == 'purchase') {
            $transaction = Transaction::where('business_id', $business_id)->where('id', $id)->firstOrFail();
            $operation_date = \Carbon\Carbon::parse($transaction->transaction_date);
            $location_id = $transaction->location_id;
            $created_by = $user_id ?: ($transaction->created_by ?? 1);

            if ($this->isOperationDateLocked($business_id, $operation_date)) {
                return false;
            }

            $finalTotal = (float) $transaction->final_total;
            $settings = $this->getAccountingSettings($business_id);
            $discTotal = $this->getTransactionDiscountTotal($transaction);
            $discAccountId = (int) ($settings['discount_received_account_id'] ?? 0);
            $useDiscount = $discTotal > self::JOURNAL_BALANCE_TOLERANCE
                && $this->isValidBusinessAccount($business_id, $discAccountId);
            $depositAmount = $useDiscount ? $finalTotal + $discTotal : $finalTotal;
            $paymentAmount = $finalTotal;

            $payment_data = [
                'accounting_account_id' => $payment_account,
                'transaction_id' => $id,
                'transaction_payment_id' => null,
                'amount' => $paymentAmount,
                'type' => 'credit',
                'sub_type' => $type,
                'note' => $note,
                'map_type' => 'payment_account',
                'created_by' => $created_by,
                'operation_date' => $operation_date,
                'location_id' => $location_id,
            ];

            $deposit_data = [
                'accounting_account_id' => $deposit_to,
                'transaction_id' => $id,
                'transaction_payment_id' => null,
                'amount' => $depositAmount,
                'type' => 'debit',
                'sub_type' => $type,
                'note' => $note,
                'map_type' => 'deposit_to',
                'created_by' => $created_by,
                'operation_date' => $operation_date,
                'location_id' => $location_id,
            ];
        } elseif ($type == 'expense') {
            $transaction = Transaction::where('business_id', $business_id)->where('id', $id)->firstOrFail();
            $operation_date = \Carbon\Carbon::parse($transaction->transaction_date);
            $location_id = $transaction->location_id;
            $created_by = $user_id ?: ($transaction->created_by ?? 1);

            if ($this->isOperationDateLocked($business_id, $operation_date)) {
                return false;
            }

            $payment_data = [
                'accounting_account_id' => $payment_account,
                'transaction_id' => $id,
                'transaction_payment_id' => null,
                'amount' => $transaction->final_total,
                'type' => 'credit',
                'sub_type' => $type,
                'note' => $note,
                'map_type' => 'payment_account',
                'created_by' => $created_by,
                'operation_date' => $operation_date,
                'location_id' => $location_id,
            ];

            $deposit_data = [
                'accounting_account_id' => $deposit_to,
                'transaction_id' => $id,
                'transaction_payment_id' => null,
                'amount' => $transaction->final_total,
                'type' => 'debit',
                'sub_type' => $type,
                'note' => $note,
                'map_type' => 'deposit_to',
                'created_by' => $created_by,
                'operation_date' => $operation_date,
                'location_id' => $location_id,
            ];
        }

        if ($payment_data === null || $deposit_data === null) {
            return false;
        }

        AccountingAccountsTransaction::updateOrCreateMapTransaction($payment_data);
        AccountingAccountsTransaction::updateOrCreateMapTransaction($deposit_data);

        if ($type === 'sell' || $type === 'purchase') {
            $txn = Transaction::where('business_id', $business_id)->where('id', $id)->firstOrFail();
            $opDate = \Carbon\Carbon::parse($txn->transaction_date);
            if (! $this->syncDiscountMapForTransaction(
                $txn,
                $type,
                $business_id,
                $user_id,
                $opDate,
                (int) $txn->location_id,
                $note
            )) {
                return false;
            }
        }

        return true;
    }

    /**
     * Post inventory value movement for purchase receipts.
     * Debit Inventory asset, Credit direct costs account (if set in settings) or the location Purchases payment account.
     */
    public function saveInventoryMapForPurchase(Transaction $transaction, ?int $user_id = null): bool
    {
        if ($transaction->type !== 'purchase' || $transaction->status !== 'received') {
            return $this->deleteInventoryMap((int) $transaction->business_id, (int) $transaction->id);
        }

        $business_id = (int) $transaction->business_id;
        $transaction_id = (int) $transaction->id;
        $operation_date = \Carbon\Carbon::parse($transaction->transaction_date);
        if ($this->isOperationDateLocked($business_id, $operation_date)) {
            return false;
        }

        $settings = $this->getAccountingSettings($business_id);
        $inventoryAccountId = (int) ($settings['inventory_asset_account_id'] ?? 0);
        if (! $this->isValidBusinessAccount($business_id, $inventoryAccountId)) {
            return true;
        }

        $location = BusinessLocation::find($transaction->location_id);
        $defaultMap = $location ? json_decode((string) $location->accounting_default_map, true) : [];
        $purchaseDepositTo = (int) ($defaultMap['purchases']['deposit_to'] ?? 0);
        // Location Purchases mapping already debits this account for the full purchase (saveMap). Posting receipt lines again would double-hit Inventory.
        if ($purchaseDepositTo > 0 && $purchaseDepositTo === $inventoryAccountId) {
            return $this->deleteInventoryMap($business_id, $transaction_id);
        }

        $directCostsAccountId = (int) ($settings['direct_costs_account_id'] ?? 0);
        $offsetAccountId = (int) ($defaultMap['purchases']['payment_account'] ?? 0);
        $creditAccountId = $this->isValidBusinessAccount($business_id, $directCostsAccountId)
            ? $directCostsAccountId
            : $offsetAccountId;
        if (! $this->isValidBusinessAccount($business_id, $creditAccountId) || $creditAccountId === $inventoryAccountId) {
            return true;
        }

        $amount = $this->getPurchaseStockReceiptAmountAfterDiscount($transaction);

        if ($amount <= 0) {
            return $this->deleteInventoryMap($business_id, $transaction_id);
        }

        $createdBy = (int) ($user_id ?: ($transaction->created_by ?? 1));

        AccountingAccountsTransaction::updateOrCreateMapTransaction([
            'accounting_account_id' => $inventoryAccountId,
            'transaction_id' => $transaction_id,
            'transaction_payment_id' => null,
            'amount' => $amount,
            'type' => 'debit',
            'sub_type' => 'purchase',
            'note' => 'Inventory receipt auto-posting',
            'map_type' => self::MAP_TYPE_INVENTORY_PURCHASE_ASSET,
            'created_by' => $createdBy,
            'operation_date' => $operation_date,
            'location_id' => $transaction->location_id,
        ]);

        $offsetNote = $creditAccountId === $directCostsAccountId
            ? 'Direct costs auto-posting'
            : 'Inventory receipt auto-posting';

        AccountingAccountsTransaction::updateOrCreateMapTransaction([
            'accounting_account_id' => $creditAccountId,
            'transaction_id' => $transaction_id,
            'transaction_payment_id' => null,
            'amount' => $amount,
            'type' => 'credit',
            'sub_type' => 'purchase',
            'note' => $offsetNote,
            'map_type' => self::MAP_TYPE_INVENTORY_PURCHASE_OFFSET,
            'created_by' => $createdBy,
            'operation_date' => $operation_date,
            'location_id' => $transaction->location_id,
        ]);

        return true;
    }

    /**
     * Post inventory value movement for final sales.
     * Debit COGS account and Credit Inventory asset based on linked purchase-line costs.
     */
    public function saveInventoryMapForSell(Transaction $transaction, ?int $user_id = null): bool
    {
        if ($transaction->type !== 'sell' || $transaction->status !== 'final') {
            return $this->deleteInventoryMap((int) $transaction->business_id, (int) $transaction->id);
        }

        $business_id = (int) $transaction->business_id;
        $transaction_id = (int) $transaction->id;
        $operation_date = \Carbon\Carbon::parse($transaction->transaction_date);
        if ($this->isOperationDateLocked($business_id, $operation_date)) {
            return false;
        }

        $settings = $this->getAccountingSettings($business_id);
        $inventoryAccountId = (int) ($settings['inventory_asset_account_id'] ?? 0);
        $cogsAccountId = (int) ($settings['inventory_cogs_account_id'] ?? 0);
        if (! $this->isValidBusinessAccount($business_id, $inventoryAccountId) ||
            ! $this->isValidBusinessAccount($business_id, $cogsAccountId)) {
            return true;
        }

        $amount = (float) DB::table('transaction_sell_lines_purchase_lines as tspl')
            ->join('transaction_sell_lines as tsl', 'tsl.id', '=', 'tspl.sell_line_id')
            ->join('purchase_lines as pl', 'pl.id', '=', 'tspl.purchase_line_id')
            ->join('products as p', 'p.id', '=', 'tsl.product_id')
            ->where('tsl.transaction_id', $transaction_id)
            ->where('p.enable_stock', 1)
            ->sum(DB::raw('tspl.quantity * pl.purchase_price_inc_tax'));

        if ($amount <= 0) {
            return $this->deleteInventoryMap($business_id, $transaction_id);
        }

        $createdBy = (int) ($user_id ?: ($transaction->created_by ?? 1));

        AccountingAccountsTransaction::updateOrCreateMapTransaction([
            'accounting_account_id' => $cogsAccountId,
            'transaction_id' => $transaction_id,
            'transaction_payment_id' => null,
            'amount' => $amount,
            'type' => 'debit',
            'sub_type' => 'sell',
            'note' => 'COGS auto-posting',
            'map_type' => self::MAP_TYPE_INVENTORY_SELL_COGS,
            'created_by' => $createdBy,
            'operation_date' => $operation_date,
            'location_id' => $transaction->location_id,
        ]);

        AccountingAccountsTransaction::updateOrCreateMapTransaction([
            'accounting_account_id' => $inventoryAccountId,
            'transaction_id' => $transaction_id,
            'transaction_payment_id' => null,
            'amount' => $amount,
            'type' => 'credit',
            'sub_type' => 'sell',
            'note' => 'COGS auto-posting',
            'map_type' => self::MAP_TYPE_INVENTORY_SELL_ASSET,
            'created_by' => $createdBy,
            'operation_date' => $operation_date,
            'location_id' => $transaction->location_id,
        ]);

        return true;
    }

    public function isValidBusinessAccount(int $business_id, int $accountId): bool
    {
        if ($accountId <= 0) {
            return false;
        }

        return DB::table('accounting_accounts')
            ->where('business_id', $business_id)
            ->where('id', $accountId)
            ->exists();
    }

    /**
     * Allowed characters for fixed-asset code prefix (alphanumeric only).
     */
    public function sanitizedFixedAssetCodePrefix(?string $prefix): string
    {
        $prefix = preg_replace('/[^A-Za-z0-9]/', '', (string) $prefix);

        return mb_substr($prefix, 0, 20);
    }

    /**
     * Next sequential code: [prefix] + 6 digits (000001–999999). Prefix comes from accounting settings.
     *
     * @throws \RuntimeException
     */
    public function generateNextFixedAssetCode(int $businessId): string
    {
        $settings = $this->getAccountingSettings($businessId);
        $prefix = $this->sanitizedFixedAssetCodePrefix($settings['fixed_asset_code_prefix'] ?? '');
        $pattern = $prefix === ''
            ? '/^(\d{6})$/'
            : '/^'.preg_quote($prefix, '/').'(\d{6})$/';

        $max = 0;
        $codes = AccountingFixedAsset::where('business_id', $businessId)
            ->whereNotNull('asset_code')
            ->where('asset_code', '!=', '')
            ->pluck('asset_code');

        foreach ($codes as $c) {
            $c = trim((string) $c);
            if (preg_match($pattern, $c, $m)) {
                $max = max($max, (int) $m[1]);
            }
        }

        $next = $max + 1;
        if ($next > 999999) {
            throw new \RuntimeException(__('accounting::lang.fixed_asset_code_max_reached'));
        }

        return $prefix.str_pad((string) $next, 6, '0', STR_PAD_LEFT);
    }
}
