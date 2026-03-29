<?php

namespace Modules\Accounting\Services;

use Illuminate\Support\Facades\DB;
use Modules\Accounting\Entities\AccountingAccount;
use Modules\Accounting\Utils\AccountingUtil;

class FinancialStatementsService
{
    public function __construct(
        protected AccountingUtil $accountingUtil
    ) {}

    /**
     * @return \Illuminate\Support\Collection<int, object>
     */
    public function profitAndLossRows(int $businessId, string $startDate, string $endDate, ?int $locationId = null)
    {
        $balanceFormula = $this->accountingUtil->balanceFormula();

        $q = AccountingAccount::join('accounting_accounts_transactions as AAT', 'AAT.accounting_account_id', '=', 'accounting_accounts.id')
            ->where('accounting_accounts.business_id', $businessId)
            ->whereIn('accounting_accounts.account_primary_type', ['income', 'expenses'])
            ->whereDate('AAT.operation_date', '>=', $startDate)
            ->whereDate('AAT.operation_date', '<=', $endDate)
            ->select(
                'accounting_accounts.id',
                'accounting_accounts.gl_code',
                'accounting_accounts.name',
                'accounting_accounts.account_primary_type',
                DB::raw($balanceFormula)
            )
            ->groupBy('accounting_accounts.id', 'accounting_accounts.gl_code', 'accounting_accounts.name', 'accounting_accounts.account_primary_type');

        if ($locationId !== null) {
            $q->where('AAT.location_id', $locationId);
        }

        return $q->orderBy('accounting_accounts.account_primary_type')
            ->orderBy('accounting_accounts.gl_code')
            ->orderBy('accounting_accounts.name')
            ->get();
    }

    /**
     * @return array{income_total: float, expense_total: float, net_income: float}
     */
    public function profitAndLossTotals(int $businessId, string $startDate, string $endDate, ?int $locationId = null): array
    {
        $rows = $this->profitAndLossRows($businessId, $startDate, $endDate, $locationId);

        $income = 0.0;
        $expense = 0.0;

        foreach ($rows as $row) {
            $b = (float) ($row->balance ?? 0);
            if ($row->account_primary_type === 'income') {
                $income += $b;
            } else {
                $expense += $b;
            }
        }

        return [
            'income_total' => $income,
            'expense_total' => $expense,
            'net_income' => $income - $expense,
        ];
    }

    /**
     * Direct cash flow: net cash movement per flagged cash/bank GL account.
     *
     * @return \Illuminate\Support\Collection<int, object>
     */
    public function cashFlowDirectRows(int $businessId, string $startDate, string $endDate, ?int $locationId = null)
    {
        $q = AccountingAccount::join('accounting_accounts_transactions as AAT', 'AAT.accounting_account_id', '=', 'accounting_accounts.id')
            ->where('accounting_accounts.business_id', $businessId)
            ->where('accounting_accounts.is_cash_account', true)
            ->whereDate('AAT.operation_date', '>=', $startDate)
            ->whereDate('AAT.operation_date', '<=', $endDate)
            ->select(
                'accounting_accounts.id',
                'accounting_accounts.gl_code',
                'accounting_accounts.name',
                DB::raw('SUM(IF(AAT.type = "debit", AAT.amount, 0)) as debit_total'),
                DB::raw('SUM(IF(AAT.type = "credit", AAT.amount, 0)) as credit_total'),
                DB::raw('SUM(IF(AAT.type = "debit", AAT.amount, 0)) - SUM(IF(AAT.type = "credit", AAT.amount, 0)) as net_cash')
            )
            ->groupBy('accounting_accounts.id', 'accounting_accounts.gl_code', 'accounting_accounts.name');

        if ($locationId !== null) {
            $q->where('AAT.location_id', $locationId);
        }

        return $q->orderBy('accounting_accounts.gl_code')->orderBy('accounting_accounts.name')->get();
    }
}
