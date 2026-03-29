<?php

namespace Modules\Accounting\Http\Controllers;

use App\Utils\ModuleUtil;
use App\Utils\Util;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Accounting\Entities\AccountingAccount;
use Modules\Accounting\Entities\AccountingAccountsTransaction;
use Modules\Accounting\Entities\AccountingBankAccount;
use Modules\Accounting\Entities\AccountingBankStatementLine;

class ReconcileController extends Controller
{
    public function __construct(protected ModuleUtil $moduleUtil, protected Util $util)
    {
    }

    public function index()
    {
        $business_id = request()->session()->get('user.business_id');

        if (! (auth()->user()->can('superadmin') ||
            $this->moduleUtil->hasThePermissionInSubscription($business_id, 'accounting_module')) ||
            ! auth()->user()->can('accounting.reconcile')) {
            abort(403, 'Unauthorized action.');
        }

        $accounts = AccountingBankAccount::where('business_id', $business_id)
            ->orderBy('name')
            ->get();

        return view('accounting::reconcile.index', compact('accounts'));
    }

    public function create()
    {
        $business_id = request()->session()->get('user.business_id');

        if (! (auth()->user()->can('superadmin') ||
            $this->moduleUtil->hasThePermissionInSubscription($business_id, 'accounting_module')) ||
            ! auth()->user()->can('accounting.reconcile')) {
            abort(403, 'Unauthorized action.');
        }

        $gl_accounts = AccountingAccount::where('business_id', $business_id)
            ->where('status', 'active')
            ->where('is_cash_account', true)
            ->orderBy('name')
            ->pluck('name', 'id');

        return view('accounting::reconcile.create', compact('gl_accounts'));
    }

    public function store(Request $request)
    {
        $business_id = request()->session()->get('user.business_id');

        if (! (auth()->user()->can('superadmin') ||
            $this->moduleUtil->hasThePermissionInSubscription($business_id, 'accounting_module')) ||
            ! auth()->user()->can('accounting.reconcile')) {
            abort(403, 'Unauthorized action.');
        }

        $request->validate([
            'name' => 'required|string|max:256',
            'accounting_account_id' => 'required|integer',
        ]);

        AccountingBankAccount::create([
            'business_id' => $business_id,
            'accounting_account_id' => $request->input('accounting_account_id'),
            'name' => $request->input('name'),
            'is_active' => true,
        ]);

        return redirect()->route('accounting.bankReconciliation.index')
            ->with('status', ['success' => true, 'msg' => __('lang_v1.added_success')]);
    }

    public function statement(int $bank_account)
    {
        $business_id = request()->session()->get('user.business_id');

        if (! (auth()->user()->can('superadmin') ||
            $this->moduleUtil->hasThePermissionInSubscription($business_id, 'accounting_module')) ||
            ! auth()->user()->can('accounting.reconcile')) {
            abort(403, 'Unauthorized action.');
        }

        $bank = AccountingBankAccount::where('business_id', $business_id)->findOrFail($bank_account);
        $lines = AccountingBankStatementLine::where('bank_account_id', $bank->id)
            ->orderByDesc('line_date')
            ->orderByDesc('id')
            ->paginate(30);

        $gl = AccountingAccount::where('business_id', $business_id)
            ->where('id', $bank->accounting_account_id)
            ->first();

        return view('accounting::reconcile.statement', compact('bank', 'lines', 'gl'));
    }

    public function storeLine(Request $request)
    {
        $business_id = request()->session()->get('user.business_id');

        if (! (auth()->user()->can('superadmin') ||
            $this->moduleUtil->hasThePermissionInSubscription($business_id, 'accounting_module')) ||
            ! auth()->user()->can('accounting.import_bank_statement')) {
            abort(403, 'Unauthorized action.');
        }

        $request->validate([
            'bank_account_id' => 'required|integer',
            'line_date' => 'required|date',
            'amount' => 'required|numeric',
            'description' => 'nullable|string',
        ]);

        $bank = AccountingBankAccount::where('business_id', $business_id)
            ->findOrFail($request->input('bank_account_id'));

        AccountingBankStatementLine::create([
            'bank_account_id' => $bank->id,
            'line_date' => $request->input('line_date'),
            'amount' => $this->util->num_uf($request->input('amount')),
            'description' => $request->input('description'),
        ]);

        return redirect()->back()->with('status', ['success' => true, 'msg' => __('lang_v1.added_success')]);
    }

    public function reconcileLine(Request $request)
    {
        $business_id = request()->session()->get('user.business_id');

        if (! (auth()->user()->can('superadmin') ||
            $this->moduleUtil->hasThePermissionInSubscription($business_id, 'accounting_module')) ||
            ! auth()->user()->can('accounting.reconcile')) {
            abort(403, 'Unauthorized action.');
        }

        $request->validate([
            'statement_line_id' => 'required|integer',
            'matched_aat_id' => 'nullable|integer',
        ]);

        $line = AccountingBankStatementLine::query()
            ->where('id', $request->input('statement_line_id'))
            ->whereHas('bankAccount', function ($q) use ($business_id) {
                $q->where('business_id', $business_id);
            })
            ->firstOrFail();

        $bank = AccountingBankAccount::where('business_id', $business_id)
            ->where('id', $line->bank_account_id)
            ->firstOrFail();

        $aatId = $request->input('matched_aat_id');
        if (! empty($aatId)) {
            $aat = AccountingAccountsTransaction::where('id', $aatId)
                ->where('accounting_account_id', $bank->accounting_account_id)
                ->firstOrFail();
            $line->matched_aat_id = $aat->id;
        } else {
            $line->matched_aat_id = null;
        }

        $line->reconciled_at = now();
        $line->save();

        return redirect()->back()->with('status', ['success' => true, 'msg' => __('lang_v1.updated_success')]);
    }
}
