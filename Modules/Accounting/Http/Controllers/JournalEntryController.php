<?php

namespace Modules\Accounting\Http\Controllers;

use App\BusinessLocation;
use App\Contact;
use App\Utils\ModuleUtil;
use App\Utils\Util;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Entities\AccountingAccountsTransaction;
use Modules\Accounting\Entities\AccountingAccTransMapping;
use Modules\Accounting\Services\AccountingAuditService;
use Modules\Accounting\Utils\AccountingUtil;
use Yajra\DataTables\Facades\DataTables;

class JournalEntryController extends Controller
{
    /**
     * All Utils instance.
     */
    protected $util;

    /**
     * Constructor
     *
     * @param  ProductUtils  $product
     * @return void
     */
    public function __construct(Util $util, ModuleUtil $moduleUtil, AccountingUtil $accountingUtil)
    {
        $this->util = $util;
        $this->moduleUtil = $moduleUtil;
        $this->accountingUtil = $accountingUtil;
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
            ! (auth()->user()->can('accounting.view_journal'))) {
            abort(403, 'Unauthorized action.');
        }

        if (request()->ajax()) {
            $journal = AccountingAccTransMapping::where('accounting_acc_trans_mappings.business_id', $business_id)
                        ->join('users as u', 'accounting_acc_trans_mappings.created_by', 'u.id')
                        ->whereIn('accounting_acc_trans_mappings.type', [
                            'journal_entry',
                            'fixed_asset_depreciation',
                            'fixed_asset_acquisition',
                            'fixed_asset_disposal',
                        ])
                        ->select(['accounting_acc_trans_mappings.id', 'accounting_acc_trans_mappings.type', 'ref_no', 'operation_date', 'note',
                            DB::raw("CONCAT(COALESCE(u.surname, ''),' ',COALESCE(u.first_name, ''),' ',COALESCE(u.last_name,'')) as added_by"),
                        ]);

            if (! empty(request()->start_date) && ! empty(request()->end_date)) {
                $start = request()->start_date;
                $end = request()->end_date;
                $journal->whereDate('accounting_acc_trans_mappings.operation_date', '>=', $start)
                            ->whereDate('accounting_acc_trans_mappings.operation_date', '<=', $end);
            }

            return Datatables::of($journal)
                ->addColumn(
                    'action', function ($row) {
                        $html = '<div class="btn-group">
                                <button type="button" class="tw-dw-btn tw-dw-btn-xs tw-dw-btn-outline tw-dw-btn-info tw-w-max dropdown-toggle" 
                                    data-toggle="dropdown" aria-expanded="false">'.
                                    __('messages.actions').
                                    '<span class="caret"></span><span class="sr-only">Toggle Dropdown
                                    </span>
                                </button>
                                <ul class="dropdown-menu dropdown-menu-right" role="menu">';
                        if (auth()->user()->can('accounting.view_journal')) {
                            // $html .= '<li>
                            //         <a href="#" data-href="'.action([\Modules\Accounting\Http\Controllers\JournalEntryController::class, 'show'], [$row->id]).'">
                            //             <i class="fas fa-eye" aria-hidden="true"></i>'.__("messages.view").'
                            //         </a>
                            //         </li>';
                        }

                        if (auth()->user()->can('accounting.edit_journal') && $row->type === 'journal_entry') {
                            $html .= '<li>
                                    <a href="'.action([\Modules\Accounting\Http\Controllers\JournalEntryController::class, 'edit'], [$row->id]).'">
                                        <i class="fas fa-edit"></i>'.__('messages.edit').'
                                    </a>
                                </li>';
                        }

                        if (auth()->user()->can('accounting.delete_journal') && $row->type === 'journal_entry') {
                            $html .= '<li>
                                    <a href="#" data-href="'.action([\Modules\Accounting\Http\Controllers\JournalEntryController::class, 'destroy'], [$row->id]).'" class="delete_journal_button">
                                        <i class="fas fa-trash" aria-hidden="true"></i>'.__('messages.delete').'
                                    </a>
                                    </li>';
                        }

                        $html .= '</ul></div>';

                        return $html;
                    })
                ->rawColumns(['action'])
                ->make(true);
        }

        return view('accounting::journal_entry.index');
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return Response
     */
    public function create()
    {
        $business_id = request()->session()->get('user.business_id');

        if (! (auth()->user()->can('superadmin') ||
            $this->moduleUtil->hasThePermissionInSubscription($business_id, 'accounting_module')) ||
            ! (auth()->user()->can('accounting.add_journal'))) {
            abort(403, 'Unauthorized action.');
        }

        $business_locations = BusinessLocation::forDropdown($business_id, false);

        return view('accounting::journal_entry.create')->with(compact('business_locations'));
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  Request  $request
     * @return Response
     */
    public function store(Request $request)
    {
        $business_id = request()->session()->get('user.business_id');

        if (! (auth()->user()->can('superadmin') ||
            $this->moduleUtil->hasThePermissionInSubscription($business_id, 'accounting_module')) ||
            ! (auth()->user()->can('accounting.add_journal'))) {
            abort(403, 'Unauthorized action.');
        }

        try {
            DB::beginTransaction();

            $user_id = request()->session()->get('user.id');

            $account_ids = $request->get('account_id');
            $credits = $request->get('credit');
            $debits = $request->get('debit');
            $journal_date = $request->get('journal_date');

            $op_date = $this->util->uf_date($journal_date, true);
            $this->accountingUtil->assertOperationDateNotLocked($business_id, $op_date);
            $this->accountingUtil->assertJournalEntryLinesBalanced($account_ids, $debits, $credits);

            $accounting_settings = $this->accountingUtil->getAccountingSettings($business_id);

            $ref_no = $request->get('ref_no');
            $ref_count = $this->util->setAndGetReferenceCount('journal_entry');
            if (empty($ref_no)) {
                $prefix = ! empty($accounting_settings['journal_entry_prefix']) ?
                $accounting_settings['journal_entry_prefix'] : '';

                //Generate reference number
                $ref_no = $this->util->generateReferenceNumber('journal_entry', $ref_count, $business_id, $prefix);
            }

            $acc_trans_mapping = new AccountingAccTransMapping();
            $acc_trans_mapping->business_id = $business_id;
            $acc_trans_mapping->ref_no = $ref_no;
            $acc_trans_mapping->note = $request->get('note');
            $acc_trans_mapping->type = 'journal_entry';
            $acc_trans_mapping->created_by = $user_id;
            $acc_trans_mapping->operation_date = $op_date;
            $acc_trans_mapping->save();

            //save details in account trnsactions table
            foreach ($account_ids as $index => $account_id) {
                if (empty($account_id)) {
                    continue;
                }

                $creditAmount = $this->util->num_uf($credits[$index] ?? '');
                $debitAmount = $this->util->num_uf($debits[$index] ?? '');

                if ($creditAmount <= 0 && $debitAmount <= 0) {
                    continue;
                }

                if ($creditAmount > 0 && $debitAmount > 0) {
                    throw new \RuntimeException(__('accounting::lang.journal_line_debit_credit_exclusive'));
                }

                $transaction_row = [];
                $transaction_row['accounting_account_id'] = $account_id;
                if ($creditAmount > 0) {
                    $transaction_row['amount'] = $creditAmount;
                    $transaction_row['type'] = 'credit';
                } else {
                    $transaction_row['amount'] = $debitAmount;
                    $transaction_row['type'] = 'debit';
                }

                $transaction_row = array_merge($transaction_row, $this->journalLineExtras($request, (int) $index, $business_id));

                $transaction_row['created_by'] = $user_id;
                $transaction_row['operation_date'] = $op_date;
                $transaction_row['sub_type'] = 'journal_entry';
                $transaction_row['acc_trans_mapping_id'] = $acc_trans_mapping->id;

                $accounts_transactions = new AccountingAccountsTransaction();
                $accounts_transactions->fill($transaction_row);
                $accounts_transactions->save();
            }

            DB::commit();

            AccountingAuditService::log(
                $business_id,
                $user_id,
                'journal_entry.created',
                AccountingAccTransMapping::class,
                $acc_trans_mapping->id,
                null,
                ['ref_no' => $acc_trans_mapping->ref_no, 'operation_date' => (string) $acc_trans_mapping->operation_date]
            );

            $output = ['success' => 1,
                'msg' => __('lang_v1.added_success'),
            ];
        } catch (\RuntimeException $e) {
            DB::rollBack();
            $output = ['success' => 0,
                'msg' => $e->getMessage(),
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::emergency('File:'.$e->getFile().'Line:'.$e->getLine().'Message:'.$e->getMessage());

            $output = ['success' => 0,
                'msg' => __('messages.something_went_wrong'),
            ];
        }

        return redirect()->route('journal-entry.index')->with('status', $output);
    }

    /**
     * Show the specified resource.
     *
     * @param  int  $id
     * @return Response
     */
    public function show($id)
    {
        $business_id = request()->session()->get('user.business_id');

        if (! (auth()->user()->can('superadmin') ||
            $this->moduleUtil->hasThePermissionInSubscription($business_id, 'accounting_module')) ||
            ! (auth()->user()->can('accounting.view_journal'))) {
            abort(403, 'Unauthorized action.');
        }

        return view('accounting::journal_entry.show');
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     * @return Response
     */
    public function edit($id)
    {
        $business_id = request()->session()->get('user.business_id');

        if (! (auth()->user()->can('superadmin') ||
            $this->moduleUtil->hasThePermissionInSubscription($business_id, 'accounting_module')) ||
            ! (auth()->user()->can('accounting.edit_journal'))) {
            abort(403, 'Unauthorized action.');
        }

        $journal = AccountingAccTransMapping::where('business_id', $business_id)
                    ->where('type', 'journal_entry')
                    ->where('id', $id)
                    ->firstOrFail();
        $lines = AccountingAccountsTransaction::with(['account', 'contact'])
            ->where('acc_trans_mapping_id', $id)
            ->orderBy('id')
            ->get();

        $business_locations = BusinessLocation::forDropdown($business_id, false);
        $size = max($lines->count(), 10);

        return view('accounting::journal_entry.edit')
            ->with(compact('journal', 'lines', 'business_locations', 'size'));
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  Request  $request
     * @param  int  $id
     * @return Response
     */
    public function update(Request $request, $id)
    {
        $business_id = request()->session()->get('user.business_id');

        if (! (auth()->user()->can('superadmin') ||
            $this->moduleUtil->hasThePermissionInSubscription($business_id, 'accounting_module')) ||
            ! (auth()->user()->can('accounting.edit_journal'))) {
            abort(403, 'Unauthorized action.');
        }

        try {
            DB::beginTransaction();

            $user_id = request()->session()->get('user.id');

            $account_ids = $request->get('account_id');
            $accounts_transactions_id = $request->get('accounts_transactions_id');
            $credits = $request->get('credit');
            $debits = $request->get('debit');
            $journal_date = $request->get('journal_date');

            $acc_trans_mapping = AccountingAccTransMapping::where('business_id', $business_id)
                        ->where('type', 'journal_entry')
                        ->where('id', $id)
                        ->firstOrFail();

            foreach (AccountingAccountsTransaction::where('acc_trans_mapping_id', $id)->get() as $line) {
                if ($this->accountingUtil->isOperationDateLocked($business_id, $line->operation_date)) {
                    DB::rollBack();

                    return redirect()->route('journal-entry.index')->with('status', [
                        'success' => 0,
                        'msg' => __('accounting::lang.period_locked'),
                    ]);
                }
            }

            $op_date = $this->util->uf_date($journal_date, true);
            $this->accountingUtil->assertOperationDateNotLocked($business_id, $op_date);
            $this->accountingUtil->assertJournalEntryLinesBalanced($account_ids, $debits, $credits);

            $location_id = $request->input('location_id');
            $location_id = ($location_id === '' || $location_id === null) ? null : (int) $location_id;

            $acc_trans_mapping->note = $request->get('note');
            $acc_trans_mapping->operation_date = $op_date;
            $acc_trans_mapping->update();

            //save details in account trnsactions table
            foreach ($account_ids as $index => $account_id) {
                $creditAmount = $this->util->num_uf($credits[$index] ?? '');
                $debitAmount = $this->util->num_uf($debits[$index] ?? '');
                $existing_id = $accounts_transactions_id[$index] ?? null;

                if (empty($account_id)) {
                    if (! empty($existing_id)) {
                        AccountingAccountsTransaction::where('id', $existing_id)->delete();
                    }

                    continue;
                }

                if ($creditAmount <= 0 && $debitAmount <= 0) {
                    if (! empty($existing_id)) {
                        AccountingAccountsTransaction::where('id', $existing_id)->delete();
                    }

                    continue;
                }

                if ($creditAmount > 0 && $debitAmount > 0) {
                    throw new \RuntimeException(__('accounting::lang.journal_line_debit_credit_exclusive'));
                }

                $transaction_row = [];
                $transaction_row['accounting_account_id'] = $account_id;
                if ($creditAmount > 0) {
                    $transaction_row['amount'] = $creditAmount;
                    $transaction_row['type'] = 'credit';
                } else {
                    $transaction_row['amount'] = $debitAmount;
                    $transaction_row['type'] = 'debit';
                }

                $transaction_row = array_merge($transaction_row, $this->journalLineExtras($request, (int) $index, $business_id));

                $transaction_row['created_by'] = $user_id;
                $transaction_row['operation_date'] = $op_date;
                $transaction_row['sub_type'] = 'journal_entry';
                $transaction_row['acc_trans_mapping_id'] = $acc_trans_mapping->id;

                if (! empty($existing_id)) {
                    $accounts_transactions = AccountingAccountsTransaction::find($existing_id);
                    $accounts_transactions->fill($transaction_row);
                    $accounts_transactions->update();
                } else {
                    $accounts_transactions = new AccountingAccountsTransaction();
                    $accounts_transactions->fill($transaction_row);
                    $accounts_transactions->save();
                }
            }

            DB::commit();

            AccountingAuditService::log(
                $business_id,
                $user_id,
                'journal_entry.updated',
                AccountingAccTransMapping::class,
                (int) $id,
                null,
                ['ref_no' => $acc_trans_mapping->ref_no, 'operation_date' => (string) $acc_trans_mapping->operation_date]
            );

            $output = ['success' => 1,
                'msg' => __('lang_v1.updated_success'),
            ];
        } catch (\RuntimeException $e) {
            DB::rollBack();
            $output = ['success' => 0,
                'msg' => $e->getMessage(),
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::emergency('File:'.$e->getFile().'Line:'.$e->getLine().'Message:'.$e->getMessage());

            $output = ['success' => 0,
                'msg' => __('messages.something_went_wrong'),
            ];
        }

        return redirect()->route('journal-entry.index')->with('status', $output);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return Response
     */
    public function destroy($id)
    {
        $business_id = request()->session()->get('user.business_id');
        if (! (auth()->user()->can('superadmin') ||
            $this->moduleUtil->hasThePermissionInSubscription($business_id, 'accounting_module')) ||
            ! (auth()->user()->can('accounting.delete_journal'))) {
            abort(403, 'Unauthorized action.');
        }

        $user_id = request()->session()->get('user.id');

        $acc_trans_mapping = AccountingAccTransMapping::where('id', $id)
                        ->where('business_id', $business_id)->firstOrFail();

        if (in_array($acc_trans_mapping->type, ['fixed_asset_depreciation', 'fixed_asset_acquisition', 'fixed_asset_disposal'], true)) {
            return ['success' => 0,
                'msg' => __('accounting::lang.delete_fixed_asset_journal_via_register'),
            ];
        }

        foreach (AccountingAccountsTransaction::where('acc_trans_mapping_id', $id)->get() as $line) {
            if ($this->accountingUtil->isOperationDateLocked($business_id, $line->operation_date)) {
                return ['success' => 0,
                    'msg' => __('accounting::lang.period_locked'),
                ];
            }
        }

        if (! empty($acc_trans_mapping)) {
            AccountingAccountsTransaction::where('acc_trans_mapping_id', $id)->delete();
            $acc_trans_mapping->delete();
        }

        AccountingAuditService::log(
            $business_id,
            $user_id,
            'journal_entry.deleted',
            AccountingAccTransMapping::class,
            (int) $id,
            ['id' => (int) $id],
            null
        );

        return ['success' => 1,
            'msg' => __('lang_v1.deleted_success'),
        ];
    }

    /**
     * Per journal line: memo, contact, location. Billable/job are cleared on save.
     *
     * @return array<string, mixed>
     */
    protected function journalLineExtras(Request $request, int $index, int $business_id): array
    {
        $notes = $request->input('journal_line_note', []);
        $contactIds = $request->input('journal_line_contact_id', []);
        $lineLocs = $request->input('journal_line_location_id', []);

        $note = isset($notes[$index]) ? trim((string) $notes[$index]) : '';
        $note = $note === '' ? null : $note;

        $contactId = $contactIds[$index] ?? null;
        if ($contactId === '' || $contactId === null) {
            $contactId = null;
        } else {
            $contactId = (int) $contactId;
            if (! Contact::where('business_id', $business_id)->where('id', $contactId)->exists()) {
                throw new \RuntimeException(__('messages.something_went_wrong'));
            }
        }

        $lineLoc = $lineLocs[$index] ?? null;
        $lineLoc = ($lineLoc === '' || $lineLoc === null) ? null : (int) $lineLoc;

        $hasLocations = BusinessLocation::where('business_id', $business_id)->exists();
        if ($hasLocations && $lineLoc === null) {
            throw new \RuntimeException(__('accounting::lang.journal_line_location_required'));
        }

        if ($lineLoc !== null) {
            if (! BusinessLocation::where('business_id', $business_id)->where('id', $lineLoc)->exists()) {
                throw new \RuntimeException(__('messages.something_went_wrong'));
            }
        }

        return [
            'note' => $note,
            'contact_id' => $contactId,
            'billable' => false,
            'job_name' => null,
            'location_id' => $lineLoc,
        ];
    }
}
