<?php

namespace Modules\Accounting\Http\Controllers;

use App\BusinessLocation;
use App\Utils\BusinessUtil;
use App\Utils\ModuleUtil;
use DB;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Modules\Accounting\Entities\AccountingAccount;
use Modules\Accounting\Services\FinancialStatementsService;
use Modules\Accounting\Utils\AccountingUtil;

class ReportController extends Controller
{
    protected $accountingUtil;

    protected $businessUtil;

    protected $moduleUtil;

    /**
     * Constructor
     *
     * @return void
     */
    public function __construct(
        AccountingUtil $accountingUtil,
        BusinessUtil $businessUtil,
        ModuleUtil $moduleUtil,
        protected FinancialStatementsService $financialStatements
    ) {
        $this->accountingUtil = $accountingUtil;
        $this->businessUtil = $businessUtil;
        $this->moduleUtil = $moduleUtil;
    }

    /**
     * Display a listing of the resource.
     *
     * @return Response
     */
    public function index()
    {
        $business_id = request()->session()->get('user.business_id');

        if (! (auth()->user()->can('superadmin') ||
            $this->moduleUtil->hasThePermissionInSubscription($business_id, 'accounting_module')) ||
            ! (auth()->user()->can('accounting.view_reports'))) {
            abort(403, 'Unauthorized action.');
        }

        $first_account = AccountingAccount::where('business_id', $business_id)
                            ->where('status', 'active')
                            ->first();
        $ledger_url = null;
        if (! empty($first_account)) {
            $ledger_url = route('accounting.ledger', $first_account);
        }

        return view('accounting::report.index')
            ->with(compact('ledger_url'));
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function reportDateRange(int $business_id): array
    {
        if (! empty(request()->start_date) && ! empty(request()->end_date)) {
            return [request()->start_date, request()->end_date];
        }

        $fy = $this->businessUtil->getCurrentFinancialYear($business_id);

        return [$fy['start'], $fy['end']];
    }

    /**
     * Trial Balance
     *
     * @return Response
     */
    public function trialBalance()
    {
        $business_id = request()->session()->get('user.business_id');

        if (! (auth()->user()->can('superadmin') ||
            $this->moduleUtil->hasThePermissionInSubscription($business_id, 'accounting_module')) ||
            ! (auth()->user()->can('accounting.view_reports'))) {
            abort(403, 'Unauthorized action.');
        }

        [$start_date, $end_date] = $this->reportDateRange($business_id);
        $location_id = request()->input('location_id', null);
        $location_id = $location_id === '' ? null : $location_id;

        $accounts = AccountingAccount::join('accounting_accounts_transactions as AAT',
                                'AAT.accounting_account_id', '=', 'accounting_accounts.id')
                            ->where('business_id', $business_id)
                            ->whereDate('AAT.operation_date', '>=', $start_date)
                            ->whereDate('AAT.operation_date', '<=', $end_date)
                            ->when($location_id !== null, function ($q) use ($location_id) {
                                $q->where('AAT.location_id', $location_id);
                            })
                            ->select(
                                DB::raw("SUM(IF(AAT.type = 'credit', AAT.amount, 0)) as credit_balance"),
                                DB::raw("SUM(IF(AAT.type = 'debit', AAT.amount, 0)) as debit_balance"),
                                'accounting_accounts.id as account_id',
                                'accounting_accounts.gl_code',
                                'accounting_accounts.name'
                            )
                            ->groupBy('accounting_accounts.id', 'accounting_accounts.gl_code', 'accounting_accounts.name')
                            ->get();

        $business_locations = BusinessLocation::forDropdown($business_id, true);

        return view('accounting::report.trial_balance')
            ->with(compact('accounts', 'start_date', 'end_date', 'business_locations', 'location_id'));
    }

    /**
     * Balance Sheet
     *
     * @return Response
     */
    public function balanceSheet()
    {
        $business_id = request()->session()->get('user.business_id');

        if (! (auth()->user()->can('superadmin') ||
            $this->moduleUtil->hasThePermissionInSubscription($business_id, 'accounting_module')) ||
            ! (auth()->user()->can('accounting.view_reports'))) {
            abort(403, 'Unauthorized action.');
        }

        [$start_date, $end_date] = $this->reportDateRange($business_id);
        $location_id = request()->input('location_id', null);
        $location_id = $location_id === '' ? null : $location_id;

        $balance_formula = $this->accountingUtil->balanceFormula();

        $base = function ($primaryTypes) use ($business_id, $start_date, $end_date, $balance_formula, $location_id) {
            return AccountingAccount::join('accounting_accounts_transactions as AAT',
                                    'AAT.accounting_account_id', '=', 'accounting_accounts.id')
                        ->join('accounting_account_types as AATP',
                                    'AATP.id', '=', 'accounting_accounts.account_sub_type_id')
                        ->whereDate('AAT.operation_date', '>=', $start_date)
                        ->whereDate('AAT.operation_date', '<=', $end_date)
                        ->when($location_id !== null, function ($q) use ($location_id) {
                            $q->where('AAT.location_id', $location_id);
                        })
                        ->select(
                            DB::raw($balance_formula),
                            'accounting_accounts.id as account_id',
                            'accounting_accounts.gl_code',
                            'accounting_accounts.name',
                            'AATP.name as sub_type'
                        )
                        ->where('accounting_accounts.business_id', $business_id)
                        ->whereIn('accounting_accounts.account_primary_type', $primaryTypes)
                        ->groupBy('accounting_accounts.id', 'accounting_accounts.gl_code', 'accounting_accounts.name', 'AATP.name');
        };

        $assets = $base(['asset'])->get();
        $liabilities = $base(['liability'])->get();
        $equities = $base(['equity'])->get();

        $business_locations = BusinessLocation::forDropdown($business_id, true);

        return view('accounting::report.balance_sheet')
            ->with(compact('assets', 'liabilities', 'equities', 'start_date', 'end_date', 'business_locations', 'location_id'));
    }

    public function profitAndLoss()
    {
        $business_id = request()->session()->get('user.business_id');

        if (! (auth()->user()->can('superadmin') ||
            $this->moduleUtil->hasThePermissionInSubscription($business_id, 'accounting_module')) ||
            ! (auth()->user()->can('accounting.view_reports'))) {
            abort(403, 'Unauthorized action.');
        }

        [$start_date, $end_date] = $this->reportDateRange($business_id);
        $location_id = request()->input('location_id', null);
        $location_id = $location_id === '' ? null : $location_id;

        $rows = $this->financialStatements->profitAndLossRows(
            (int) $business_id,
            $start_date,
            $end_date,
            $location_id !== null ? (int) $location_id : null
        );
        $totals = $this->financialStatements->profitAndLossTotals(
            (int) $business_id,
            $start_date,
            $end_date,
            $location_id !== null ? (int) $location_id : null
        );

        $business_locations = BusinessLocation::forDropdown($business_id, true);

        return view('accounting::report.profit_loss')
            ->with(compact('rows', 'totals', 'start_date', 'end_date', 'business_locations', 'location_id'));
    }

    public function cashFlow()
    {
        $business_id = request()->session()->get('user.business_id');

        if (! (auth()->user()->can('superadmin') ||
            $this->moduleUtil->hasThePermissionInSubscription($business_id, 'accounting_module')) ||
            ! (auth()->user()->can('accounting.view_reports'))) {
            abort(403, 'Unauthorized action.');
        }

        [$start_date, $end_date] = $this->reportDateRange($business_id);
        $location_id = request()->input('location_id', null);
        $location_id = $location_id === '' ? null : $location_id;

        $rows = $this->financialStatements->cashFlowDirectRows(
            (int) $business_id,
            $start_date,
            $end_date,
            $location_id !== null ? (int) $location_id : null
        );

        $business_locations = BusinessLocation::forDropdown($business_id, true);

        return view('accounting::report.cash_flow')
            ->with(compact('rows', 'start_date', 'end_date', 'business_locations', 'location_id'));
    }

    public function accountReceivableAgeingReport()
    {
        $business_id = request()->session()->get('user.business_id');

        if (! (auth()->user()->can('superadmin') ||
            $this->moduleUtil->hasThePermissionInSubscription($business_id, 'accounting_module')) ||
            ! (auth()->user()->can('accounting.view_reports'))) {
            abort(403, 'Unauthorized action.');
        }

        $location_id = request()->input('location_id', null);

        $report_details = $this->accountingUtil->getAgeingReport($business_id, 'sell', 'contact', $location_id);

        $business_locations = BusinessLocation::forDropdown($business_id, true);

        return view('accounting::report.account_receivable_ageing_report')
        ->with(compact('report_details', 'business_locations'));
    }

    public function accountPayableAgeingReport()
    {
        $business_id = request()->session()->get('user.business_id');

        if (! (auth()->user()->can('superadmin') ||
            $this->moduleUtil->hasThePermissionInSubscription($business_id, 'accounting_module')) ||
            ! (auth()->user()->can('accounting.view_reports'))) {
            abort(403, 'Unauthorized action.');
        }

        $location_id = request()->input('location_id', null);
        $report_details = $this->accountingUtil->getAgeingReport($business_id, 'purchase', 'contact',
        $location_id);
        $business_locations = BusinessLocation::forDropdown($business_id, true);

        return view('accounting::report.account_payable_ageing_report')
        ->with(compact('report_details', 'business_locations'));
    }

    public function accountReceivableAgeingDetails()
    {
        $business_id = request()->session()->get('user.business_id');

        if (! (auth()->user()->can('superadmin') ||
            $this->moduleUtil->hasThePermissionInSubscription($business_id, 'accounting_module')) ||
            ! (auth()->user()->can('accounting.view_reports'))) {
            abort(403, 'Unauthorized action.');
        }

        $location_id = request()->input('location_id', null);

        $report_details = $this->accountingUtil->getAgeingReport($business_id, 'sell', 'due_date',
        $location_id);

        $business_locations = BusinessLocation::forDropdown($business_id, true);

        return view('accounting::report.account_receivable_ageing_details')
        ->with(compact('business_locations', 'report_details'));
    }

    public function accountPayableAgeingDetails()
    {
        $business_id = request()->session()->get('user.business_id');

        if (! (auth()->user()->can('superadmin') ||
            $this->moduleUtil->hasThePermissionInSubscription($business_id, 'accounting_module')) ||
            ! (auth()->user()->can('accounting.view_reports'))) {
            abort(403, 'Unauthorized action.');
        }

        $location_id = request()->input('location_id', null);

        $report_details = $this->accountingUtil->getAgeingReport($business_id, 'purchase', 'due_date',
        $location_id);

        $business_locations = BusinessLocation::forDropdown($business_id, true);

        return view('accounting::report.account_payable_ageing_details')
        ->with(compact('business_locations', 'report_details'));
    }
}
