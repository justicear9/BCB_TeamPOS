<?php

namespace Modules\Accounting\Http\Controllers;

use App\BusinessLocation;
use App\Utils\ModuleUtil;
use App\Utils\Util;
use DB;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Modules\Accounting\Entities\AccountingAccount;
use Modules\Accounting\Entities\AccountingAccountsTransaction;
use Modules\Accounting\Entities\AccountingBankAccount;
use Modules\Accounting\Entities\AccountingBankStatementLine;
use Modules\Accounting\Services\AccountingAuditService;
use Modules\Accounting\Utils\AccountingUtil;
use Yajra\DataTables\Facades\DataTables;

class ReconcileController extends Controller
{
    private const AMOUNT_TOLERANCE = 0.0001;

    public function __construct(
        protected ModuleUtil $moduleUtil,
        protected Util $util,
        protected AccountingUtil $accountingUtil
    ) {
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

        $gl_accounts = $this->eligibleBankGlAccountsQuery($business_id)
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

        $glId = (int) $request->input('accounting_account_id');
        $gl = $this->eligibleBankGlAccountsQuery($business_id)
            ->where('id', $glId)
            ->first();

        if (! $gl) {
            throw ValidationException::withMessages([
                'accounting_account_id' => __('accounting::lang.bank_reconcile_invalid_cash_account'),
            ]);
        }

        $bank = AccountingBankAccount::create([
            'business_id' => $business_id,
            'accounting_account_id' => $gl->id,
            'name' => $request->input('name'),
            'is_active' => true,
        ]);

        AccountingAuditService::log(
            $business_id,
            auth()->id(),
            'bank_profile.created',
            AccountingBankAccount::class,
            $bank->id,
            null,
            [
                'name' => $bank->name,
                'accounting_account_id' => $bank->accounting_account_id,
            ]
        );

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

        $status = request('status', 'all');
        if (! in_array($status, ['all', 'reconciled', 'unreconciled'], true)) {
            $status = 'all';
        }

        $linesQuery = AccountingBankStatementLine::where('bank_account_id', $bank->id)
            ->with(['matchedAat.accTransMapping']);

        if ($status === 'reconciled') {
            $linesQuery->whereNotNull('matched_aat_id');
        } elseif ($status === 'unreconciled') {
            $linesQuery->whereNull('matched_aat_id');
        }

        $lines = $linesQuery
            ->orderByDesc('line_date')
            ->orderByDesc('id')
            ->paginate(30)
            ->appends(['status' => $status]);

        $gl = AccountingAccount::where('business_id', $business_id)
            ->where('id', $bank->accounting_account_id)
            ->first();

        $business_locations = BusinessLocation::forDropdown($business_id, true);

        return view('accounting::reconcile.statement', compact('bank', 'lines', 'gl', 'status', 'business_locations'));
    }

    public function glLines(Request $request, int $bank_account)
    {
        $business_id = request()->session()->get('user.business_id');

        if (! (auth()->user()->can('superadmin') ||
            $this->moduleUtil->hasThePermissionInSubscription($business_id, 'accounting_module')) ||
            ! auth()->user()->can('accounting.reconcile')) {
            abort(403, 'Unauthorized action.');
        }

        if (! $request->ajax()) {
            abort(404);
        }

        $bank = AccountingBankAccount::where('business_id', $business_id)->findOrFail($bank_account);

        $gl = AccountingAccount::where('business_id', $business_id)
            ->where('id', $bank->accounting_account_id)
            ->firstOrFail();

        $excludeStatementLineId = $request->input('statement_line_id');

        $alreadySub = '(SELECT COUNT(*) FROM accounting_bank_statement_lines bsl '
            .'INNER JOIN accounting_bank_accounts ba ON ba.id = bsl.bank_account_id '
            .'WHERE bsl.matched_aat_id = accounting_accounts_transactions.id '
            .'AND ba.business_id = '.(int) $business_id;
        if ($excludeStatementLineId !== null && $excludeStatementLineId !== '') {
            $alreadySub .= ' AND bsl.id <> '.(int) $excludeStatementLineId;
        }
        $alreadySub .= ') as already_matched';

        $transactions = AccountingAccountsTransaction::where('accounting_accounts_transactions.accounting_account_id', $bank->accounting_account_id)
            ->leftjoin('accounting_acc_trans_mappings as ATM', 'accounting_accounts_transactions.acc_trans_mapping_id', '=', 'ATM.id')
            ->leftjoin('transactions as T', 'accounting_accounts_transactions.transaction_id', '=', 'T.id')
            ->leftJoin('transaction_payments as TP', 'accounting_accounts_transactions.transaction_payment_id', '=', 'TP.id')
            ->leftJoin('transactions as Tpay', 'TP.transaction_id', '=', 'Tpay.id')
            ->leftjoin('users AS U', 'accounting_accounts_transactions.created_by', 'U.id')
            ->select(
                'accounting_accounts_transactions.id as aat_id',
                'accounting_accounts_transactions.operation_date',
                'accounting_accounts_transactions.sub_type',
                'accounting_accounts_transactions.type',
                'accounting_accounts_transactions.note as aat_note',
                'accounting_accounts_transactions.transaction_id',
                'accounting_accounts_transactions.acc_trans_mapping_id',
                'ATM.id as mapping_id',
                'ATM.type as mapping_type',
                'ATM.fixed_asset_id',
                'ATM.ref_no as a_ref',
                'ATM.note',
                'accounting_accounts_transactions.amount',
                DB::raw("CONCAT(COALESCE(U.surname, ''),' ',COALESCE(U.first_name, ''),' ',COALESCE(U.last_name,'')) as added_by"),
                DB::raw('COALESCE(T.invoice_no, Tpay.invoice_no) as invoice_no'),
                DB::raw('COALESCE(T.ref_no, Tpay.ref_no) as ref_no'),
                'T.type as source_transaction_type',
                'T.opening_stock_product_id as opening_stock_product_id',
                'TP.payment_ref_no as payment_ref_no',
                'TP.method as payment_method',
                'TP.note as payment_note',
                DB::raw('COALESCE(accounting_accounts_transactions.transaction_id, TP.transaction_id) as source_transaction_id'),
                DB::raw($alreadySub)
            );

        $start_date = $request->input('start_date');
        $end_date = $request->input('end_date');
        if (! empty($start_date) && ! empty($end_date)) {
            $transactions->whereDate('accounting_accounts_transactions.operation_date', '>=', $start_date)
                ->whereDate('accounting_accounts_transactions.operation_date', '<=', $end_date);
        }

        $location_id = $request->input('location_id');
        if ($location_id !== null && $location_id !== '') {
            $transactions->where('accounting_accounts_transactions.location_id', $location_id);
        }

        return DataTables::of($transactions)
            ->editColumn('operation_date', function ($row) {
                return $this->accountingUtil->format_date($row->operation_date, true);
            })
            ->editColumn('ref_no', function ($row) {
                return $this->accountingUtil->ledgerLineDescriptionHtml($row);
            })
            ->addColumn('debit', function ($row) {
                if ($row->type == 'debit') {
                    return '<span class="debit" data-orig-value="'.$row->amount.'">'.$this->accountingUtil->num_f($row->amount, true).'</span>';
                }

                return '';
            })
            ->addColumn('credit', function ($row) {
                if ($row->type == 'credit') {
                    return '<span class="credit"  data-orig-value="'.$row->amount.'">'.$this->accountingUtil->num_f($row->amount, true).'</span>';
                }

                return '';
            })
            ->addColumn('signed_amount', function ($row) use ($gl) {
                $signed = $this->signedGlMovementForAccount($row, $gl->account_primary_type);

                return $this->accountingUtil->num_f($signed, true);
            })
            ->addColumn('document_link', function ($row) {
                return $this->accountingUtil->ledgerLineDocumentLinkHtml($row);
            })
            ->addColumn('picker_action', function ($row) {
                $blocked = (int) $row->already_matched > 0;
                if ($blocked) {
                    return '<span class="text-muted">'.e(__('accounting::lang.bank_reconcile_line_already_matched')).'</span>';
                }

                return '<button type="button" class="tw-dw-btn tw-dw-btn-xs tw-dw-btn-primary btn-select-gl-line" data-aat-id="'.(int) $row->aat_id.'">'
                    .e(__('accounting::lang.choose_gl_line')).'</button>';
            })
            ->rawColumns(['ref_no', 'credit', 'debit', 'document_link', 'picker_action'])
            ->make(true);
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
            'line_date' => 'required',
            'amount' => 'required',
            'description' => 'nullable|string',
        ]);

        $lineDateStr = $this->util->uf_date($request->input('line_date'), false);
        if (empty($lineDateStr)) {
            throw ValidationException::withMessages([
                'line_date' => __('validation.date', ['attribute' => __('messages.date')]),
            ]);
        }

        try {
            $this->accountingUtil->assertOperationDateNotLocked($business_id, $lineDateStr);
        } catch (\RuntimeException $e) {
            return redirect()->back()->with('status', ['success' => false, 'msg' => $e->getMessage()]);
        }

        $bank = AccountingBankAccount::where('business_id', $business_id)
            ->findOrFail($request->input('bank_account_id'));

        $line = AccountingBankStatementLine::create([
            'bank_account_id' => $bank->id,
            'line_date' => $lineDateStr,
            'amount' => $this->util->num_uf($request->input('amount')),
            'description' => $request->input('description'),
        ]);

        AccountingAuditService::log(
            $business_id,
            auth()->id(),
            'bank_statement_line.created',
            AccountingBankStatementLine::class,
            $line->id,
            null,
            [
                'bank_account_id' => $line->bank_account_id,
                'line_date' => (string) $line->line_date,
                'amount' => (string) $line->amount,
                'description' => $line->description,
            ]
        );

        return redirect()->back()->with('status', ['success' => true, 'msg' => __('lang_v1.added_success')]);
    }

    public function bankStatementImportTemplate(int $bank_account): StreamedResponse
    {
        $business_id = request()->session()->get('user.business_id');

        if (! (auth()->user()->can('superadmin') ||
            $this->moduleUtil->hasThePermissionInSubscription($business_id, 'accounting_module')) ||
            ! auth()->user()->can('accounting.import_bank_statement')) {
            abort(403, 'Unauthorized action.');
        }

        AccountingBankAccount::where('business_id', $business_id)->findOrFail($bank_account);

        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="bank_statement_import_template.csv"',
        ];

        $sample = "date,amount,description\n".gmdate('Y-m-d').",-12.50,Sample card fee\n";

        return response()->streamDownload(function () use ($sample) {
            echo "\xEF\xBB\xBF".$sample;
        }, 'bank_statement_import_template.csv', $headers);
    }

    public function importBankStatement(Request $request, int $bank_account)
    {
        $business_id = request()->session()->get('user.business_id');

        if (! (auth()->user()->can('superadmin') ||
            $this->moduleUtil->hasThePermissionInSubscription($business_id, 'accounting_module')) ||
            ! auth()->user()->can('accounting.import_bank_statement')) {
            abort(403, 'Unauthorized action.');
        }

        $bank = AccountingBankAccount::where('business_id', $business_id)->findOrFail($bank_account);

        $request->validate([
            'statement_file' => 'required|file|mimes:csv,txt|max:5120',
        ]);

        $path = $request->file('statement_file')->getRealPath();
        if ($path === false || ! is_readable($path)) {
            return redirect()->back()->with('status', [
                'success' => false,
                'msg' => __('accounting::lang.bank_statement_import_unreadable'),
            ]);
        }

        $handle = fopen($path, 'r');
        if ($handle === false) {
            return redirect()->back()->with('status', [
                'success' => false,
                'msg' => __('accounting::lang.bank_statement_import_unreadable'),
            ]);
        }

        $firstLine = fgets($handle);
        if ($firstLine === false) {
            fclose($handle);

            return redirect()->back()->with('status', [
                'success' => false,
                'msg' => __('accounting::lang.bank_statement_import_empty'),
            ]);
        }

        $firstLine = preg_replace('/^\xEF\xBB\xBF/', '', $firstLine);
        $headerRow = str_getcsv($firstLine);
        $headerRow = array_map(function ($h) {
            return strtolower(trim(str_replace([' ', '-'], '_', (string) $h)));
        }, $headerRow);

        $colDate = $this->csvPickColumn($headerRow, ['date', 'line_date', 'transaction_date', 'posting_date', 'value_date', 'booked']);
        $colAmount = $this->csvPickColumn($headerRow, ['amount', 'value', 'sum']);
        $colDebit = $this->csvPickColumn($headerRow, ['debit', 'withdrawal', 'out']);
        $colCredit = $this->csvPickColumn($headerRow, ['credit', 'deposit', 'in']);
        $colDesc = $this->csvPickColumn($headerRow, ['description', 'memo', 'narrative', 'details', 'payee', 'name', 'reference']);

        if ($colDate === null || ($colAmount === null && $colDebit === null && $colCredit === null)) {
            fclose($handle);

            return redirect()->back()->with('status', [
                'success' => false,
                'msg' => __('accounting::lang.bank_statement_import_bad_headers'),
            ]);
        }

        $batchId = (string) Str::uuid();
        $rows = [];
        $rowNum = 1;
        $maxRows = 10000;

        while (($data = fgetcsv($handle)) !== false) {
            $rowNum++;
            if ($rowNum > $maxRows + 1) {
                fclose($handle);

                return redirect()->back()->with('status', [
                    'success' => false,
                    'msg' => __('accounting::lang.bank_statement_import_too_many_rows', ['max' => $maxRows]),
                ]);
            }

            if ($this->csvRowIsEmpty($data)) {
                continue;
            }

            $dateRaw = $data[$colDate] ?? '';
            $lineDateStr = $this->util->uf_date($dateRaw, false);
            if (empty($lineDateStr)) {
                fclose($handle);

                return redirect()->back()->with('status', [
                    'success' => false,
                    'msg' => __('accounting::lang.bank_statement_import_bad_date', ['row' => $rowNum]),
                ]);
            }

            if ($colAmount !== null) {
                $amountVal = $this->util->num_uf($data[$colAmount] ?? '0');
            } else {
                $debit = $colDebit !== null ? $this->util->num_uf($data[$colDebit] ?? '0') : 0.0;
                $credit = $colCredit !== null ? $this->util->num_uf($data[$colCredit] ?? '0') : 0.0;
                $amountVal = $credit - $debit;
            }

            $desc = $colDesc !== null ? trim((string) ($data[$colDesc] ?? '')) : '';

            try {
                $this->accountingUtil->assertOperationDateNotLocked($business_id, $lineDateStr);
            } catch (\RuntimeException $e) {
                fclose($handle);

                return redirect()->back()->with('status', [
                    'success' => false,
                    'msg' => __('accounting::lang.bank_statement_import_period_locked', ['row' => $rowNum]),
                ]);
            }

            $rows[] = [
                'bank_account_id' => $bank->id,
                'line_date' => $lineDateStr,
                'amount' => $amountVal,
                'description' => $desc !== '' ? $desc : null,
                'import_batch_id' => $batchId,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        fclose($handle);

        if ($rows === []) {
            return redirect()->back()->with('status', [
                'success' => false,
                'msg' => __('accounting::lang.bank_statement_import_no_data_rows'),
            ]);
        }

        try {
            DB::beginTransaction();
            foreach (array_chunk($rows, 500) as $chunk) {
                AccountingBankStatementLine::insert($chunk);
            }

            AccountingAuditService::log(
                $business_id,
                auth()->id(),
                'bank_statement.imported',
                AccountingBankAccount::class,
                $bank->id,
                null,
                [
                    'import_batch_id' => $batchId,
                    'rows' => count($rows),
                    'bank_account_id' => $bank->id,
                ]
            );
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            \Log::error('Bank statement import failed', ['message' => $e->getMessage()]);

            return redirect()->back()->with('status', [
                'success' => false,
                'msg' => __('messages.something_went_wrong'),
            ]);
        }

        return redirect()->back()->with('status', [
            'success' => true,
            'msg' => __('accounting::lang.bank_statement_import_success', ['count' => count($rows)]),
        ]);
    }

    public function reconcileLine(Request $request)
    {
        $business_id = request()->session()->get('user.business_id');

        if (! (auth()->user()->can('superadmin') ||
            $this->moduleUtil->hasThePermissionInSubscription($business_id, 'accounting_module')) ||
            ! auth()->user()->can('accounting.reconcile')) {
            abort(403, 'Unauthorized action.');
        }

        $merge = [];
        if ($request->input('matched_aat_id') === '' || $request->input('matched_aat_id') === null) {
            $merge['matched_aat_id'] = null;
        }
        if ($merge !== []) {
            $request->merge($merge);
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

        $gl = AccountingAccount::where('business_id', $business_id)
            ->where('id', $bank->accounting_account_id)
            ->firstOrFail();

        $before = [
            'matched_aat_id' => $line->matched_aat_id,
            'reconciled_at' => $line->reconciled_at ? $line->reconciled_at->toIso8601String() : null,
            'reconciled_by' => $line->reconciled_by,
        ];

        $aatId = $request->input('matched_aat_id');

        try {
            if (! empty($aatId)) {
                $aat = AccountingAccountsTransaction::where('id', $aatId)
                    ->where('accounting_account_id', $bank->accounting_account_id)
                    ->first();
                if (! $aat) {
                    return redirect()->back()->with('status', [
                        'success' => false,
                        'msg' => __('accounting::lang.bank_reconcile_invalid_gl_transaction'),
                    ]);
                }

                $this->accountingUtil->assertOperationDateNotLocked($business_id, $aat->operation_date);

                $dup = AccountingBankStatementLine::where('matched_aat_id', $aat->id)
                    ->where('id', '!=', $line->id)
                    ->whereHas('bankAccount', function ($q) use ($business_id) {
                        $q->where('business_id', $business_id);
                    })
                    ->exists();

                if ($dup) {
                    return redirect()->back()->with('status', [
                        'success' => false,
                        'msg' => __('accounting::lang.bank_reconcile_aat_already_matched'),
                    ]);
                }

                $signed = $this->signedGlMovementForAccount($aat, $gl->account_primary_type);
                $stmtAmount = (float) $line->amount;
                if (abs($signed - $stmtAmount) > self::AMOUNT_TOLERANCE) {
                    return redirect()->back()->with('status', [
                        'success' => false,
                        'msg' => __('accounting::lang.bank_reconcile_amount_mismatch', [
                            'statement' => $this->accountingUtil->num_f($stmtAmount, true),
                            'gl' => $this->accountingUtil->num_f($signed, true),
                        ]),
                    ]);
                }

                $line->matched_aat_id = $aat->id;
                $line->reconciled_at = now();
                $line->reconciled_by = auth()->id();
            } else {
                if ($line->line_date) {
                    $this->accountingUtil->assertOperationDateNotLocked($business_id, $line->line_date);
                }
                $line->matched_aat_id = null;
                $line->reconciled_at = null;
                $line->reconciled_by = null;
            }
        } catch (\RuntimeException $e) {
            return redirect()->back()->with('status', ['success' => false, 'msg' => $e->getMessage()]);
        }

        $line->save();

        $after = [
            'matched_aat_id' => $line->matched_aat_id,
            'reconciled_at' => $line->reconciled_at ? $line->reconciled_at->toIso8601String() : null,
            'reconciled_by' => $line->reconciled_by,
        ];

        $action = ! empty($line->matched_aat_id)
            ? 'bank_statement_line.reconciled'
            : 'bank_statement_line.unreconciled';

        AccountingAuditService::log(
            $business_id,
            auth()->id(),
            $action,
            AccountingBankStatementLine::class,
            $line->id,
            $before,
            $after
        );

        return redirect()->back()->with('status', ['success' => true, 'msg' => __('lang_v1.updated_success')]);
    }

    /**
     * GL accounts that can be linked to a bank reconciliation profile: cash/bank flag, or standard
     * cash-and-bank detail types (most chart defaults never set the flag).
     */
    private function eligibleBankGlAccountsQuery(int $businessId)
    {
        $detailNames = [
            'bank',
            'cash_and_cash_equivalents',
            'cash_on_hand',
            'client_trust_account',
            'money_market',
            'rents_held_in_trust',
            'savings',
            'undeposited_funds',
        ];

        return AccountingAccount::query()
            ->where('business_id', $businessId)
            ->where('status', 'active')
            ->where('account_primary_type', 'asset')
            ->where(function ($q) use ($detailNames) {
                $q->where('is_cash_account', true)
                    ->orWhereHas('detail_type', function ($dq) use ($detailNames) {
                        $dq->where('account_type', 'detail_type')
                            ->whereIn('name', $detailNames);
                    });
            });
    }

    /**
     * Signed movement on the GL line for bank-statement comparison (positive = money in, negative = out).
     */
    private function signedGlMovementForAccount($aatRow, ?string $accountPrimaryType): float
    {
        $amount = (float) $aatRow->amount;
        $isDebit = $aatRow->type === 'debit';

        return match ($accountPrimaryType) {
            'asset' => $isDebit ? $amount : -$amount,
            'expense', 'expenses' => $isDebit ? $amount : -$amount,
            'income' => $isDebit ? -$amount : $amount,
            'equity' => $isDebit ? -$amount : $amount,
            'liability' => $isDebit ? -$amount : $amount,
            default => $isDebit ? $amount : -$amount,
        };
    }

    /**
     * @param  array<int, string>  $headers
     * @param  array<int, string>  $candidates
     */
    private function csvPickColumn(array $headers, array $candidates): ?int
    {
        foreach ($candidates as $name) {
            $idx = array_search($name, $headers, true);
            if ($idx !== false) {
                return (int) $idx;
            }
        }

        return null;
    }

    /**
     * @param  array<int, string|null>  $data
     */
    private function csvRowIsEmpty(array $data): bool
    {
        foreach ($data as $cell) {
            if (trim((string) $cell) !== '') {
                return false;
            }
        }

        return true;
    }
}
