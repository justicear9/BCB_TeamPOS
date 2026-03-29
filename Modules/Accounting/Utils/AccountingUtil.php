<?php

namespace Modules\Accounting\Utils;

use App\Business;
use App\Transaction;
use App\TransactionPayment;
use App\Utils\Util;
use DB;
use Modules\Accounting\Entities\AccountingAccountsTransaction;
use Modules\Accounting\Entities\AccountingFixedAsset;

class AccountingUtil extends Util
{
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
     * Delete payment / sale / purchase map lines. Returns false if period is locked.
     */
    public function deleteMap($business_id, $transaction_id, $transaction_payment_id): bool
    {
        $accountIds = DB::table('accounting_accounts')
            ->where('business_id', $business_id)
            ->pluck('id');

        $q = AccountingAccountsTransaction::query()
            ->whereIn('map_type', ['payment_account', 'deposit_to'])
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
            ->whereIn('map_type', ['payment_account', 'deposit_to'])
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

        return true;
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
