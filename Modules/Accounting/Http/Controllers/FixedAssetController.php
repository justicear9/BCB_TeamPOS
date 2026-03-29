<?php

namespace Modules\Accounting\Http\Controllers;

use App\BusinessLocation;
use App\Utils\ModuleUtil;
use App\Utils\Util;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Facades\Excel;
use Modules\Accounting\Exports\FixedAssetScheduleExport;
use Modules\Accounting\Entities\AccountingAccount;
use Modules\Accounting\Entities\AccountingFixedAsset;
use Modules\Accounting\Services\AccountingAuditService;
use Modules\Accounting\Services\FixedAssetAcquisitionService;
use Modules\Accounting\Services\FixedAssetDepreciationService;
use Modules\Accounting\Services\FixedAssetDisposalService;
use Modules\Accounting\Utils\AccountingUtil;

class FixedAssetController extends Controller
{
    public function __construct(
        protected ModuleUtil $moduleUtil,
        protected Util $util,
        protected AccountingUtil $accountingUtil,
        protected FixedAssetDepreciationService $depreciationService,
        protected FixedAssetAcquisitionService $acquisitionService,
        protected FixedAssetDisposalService $disposalService
    ) {
    }

    public function index()
    {
        $business_id = request()->session()->get('user.business_id');

        if (! (auth()->user()->can('superadmin') ||
            $this->moduleUtil->hasThePermissionInSubscription($business_id, 'accounting_module')) ||
            ! auth()->user()->can('accounting.view_fixed_assets')) {
            abort(403, 'Unauthorized action.');
        }

        $assets = AccountingFixedAsset::where('business_id', $business_id)
            ->with(['assetAccount', 'location'])
            ->orderBy('name')
            ->get();

        return view('accounting::fixed_assets.index', compact('assets'));
    }

    public function schedule(Request $request)
    {
        $business_id = request()->session()->get('user.business_id');

        if (! (auth()->user()->can('superadmin') ||
            $this->moduleUtil->hasThePermissionInSubscription($business_id, 'accounting_module')) ||
            ! auth()->user()->can('accounting.view_fixed_assets') ||
            ! auth()->user()->can('accounting.view_reports')) {
            abort(403, 'Unauthorized action.');
        }

        $location_id = $request->input('location_id');
        $location_id = ($location_id === '' || $location_id === null) ? null : (int) $location_id;
        $status = $request->input('status');

        $assets = $this->scheduleAssetsQuery($business_id, $location_id, $status);
        $business_locations = BusinessLocation::forDropdown($business_id, true);

        return view('accounting::fixed_assets.schedule', compact('assets', 'business_locations', 'location_id', 'status'));
    }

    public function scheduleExport(Request $request)
    {
        $business_id = request()->session()->get('user.business_id');

        if (! (auth()->user()->can('superadmin') ||
            $this->moduleUtil->hasThePermissionInSubscription($business_id, 'accounting_module')) ||
            ! auth()->user()->can('accounting.view_fixed_assets') ||
            ! auth()->user()->can('accounting.view_reports')) {
            abort(403, 'Unauthorized action.');
        }

        $location_id = $request->input('location_id');
        $location_id = ($location_id === '' || $location_id === null) ? null : (int) $location_id;
        $status = $request->input('status');

        $assets = $this->scheduleAssetsQuery($business_id, $location_id, $status);
        $dateFormat = session('business.date_format', 'Y-m-d');
        $precision = (int) session('business.currency_precision', 2);
        $currency = session('currency', []);
        $dec = $currency['decimal_separator'] ?? '.';
        $thou = $currency['thousand_separator'] ?? ',';

        $headings = [
            __('accounting::lang.fixed_asset_code'),
            __('accounting::lang.fixed_asset_name'),
            __('accounting::lang.location'),
            __('accounting::lang.asset_account'),
            __('sale.cost'),
            __('accounting::lang.opening_accumulated_depreciation'),
            __('accounting::lang.accumulated_depreciation_posted'),
            __('accounting::lang.accumulated_depreciation_total'),
            __('accounting::lang.net_book_value'),
            __('accounting::lang.acquisition_date'),
            __('accounting::lang.useful_life_months'),
            __('accounting::lang.depreciates'),
            __('accounting::lang.record_status'),
        ];

        $rows = $assets->map(function (AccountingFixedAsset $a) use ($dateFormat, $precision, $dec, $thou) {
            $fmt = fn ($n) => number_format((float) $n, $precision, $dec, $thou);
            $acq = '';
            if ($a->acquisition_date) {
                try {
                    $acq = Carbon::parse($a->acquisition_date)->format($dateFormat);
                } catch (\Throwable $e) {
                    $acq = (string) $a->acquisition_date;
                }
            }

            return [
                $a->asset_code ?? '',
                $a->name,
                $a->location->name ?? '',
                $a->assetAccount->name ?? '',
                $fmt($a->cost),
                $fmt($a->opening_accumulated_depreciation),
                $fmt($a->accumulated_depreciation_posted),
                $fmt($a->totalAccumulatedDepreciation()),
                $fmt($a->netBookValue()),
                $acq,
                $a->useful_life_months ?? '',
                $a->is_depreciable ? __('messages.yes') : __('accounting::lang.non_depreciable'),
                $this->fixedAssetStatusLabel($a->status),
            ];
        });

        $filename = 'fixed-asset-schedule-'.now()->format('Y-m-d-His').'.xlsx';

        return Excel::download(new FixedAssetScheduleExport($rows, $headings), $filename);
    }

    public function create()
    {
        $business_id = request()->session()->get('user.business_id');

        if (! (auth()->user()->can('superadmin') ||
            $this->moduleUtil->hasThePermissionInSubscription($business_id, 'accounting_module')) ||
            ! auth()->user()->can('accounting.manage_fixed_assets')) {
            abort(403, 'Unauthorized action.');
        }

        $accounts = $this->accountsForSelect($business_id);
        $business_locations = BusinessLocation::forDropdown($business_id, true);

        return view('accounting::fixed_assets.create', compact('accounts', 'business_locations'));
    }

    public function store(Request $request)
    {
        $business_id = request()->session()->get('user.business_id');

        if (! (auth()->user()->can('superadmin') ||
            $this->moduleUtil->hasThePermissionInSubscription($business_id, 'accounting_module')) ||
            ! auth()->user()->can('accounting.manage_fixed_assets')) {
            abort(403, 'Unauthorized action.');
        }

        $data = $this->validateAsset($request, $business_id);

        $asset = AccountingFixedAsset::create($data + ['business_id' => $business_id]);

        AccountingAuditService::log(
            $business_id,
            (int) request()->session()->get('user.id'),
            'fixed_asset.created',
            AccountingFixedAsset::class,
            (int) $asset->id,
            null,
            ['name' => $asset->name, 'cost' => (string) $asset->cost]
        );

        return redirect()->route('accounting.fixedAssets.index')
            ->with('status', ['success' => true, 'msg' => __('lang_v1.added_success')]);
    }

    public function show(int $fixed_asset)
    {
        $business_id = request()->session()->get('user.business_id');

        if (! (auth()->user()->can('superadmin') ||
            $this->moduleUtil->hasThePermissionInSubscription($business_id, 'accounting_module')) ||
            ! auth()->user()->can('accounting.view_fixed_assets')) {
            abort(403, 'Unauthorized action.');
        }

        $asset = AccountingFixedAsset::where('business_id', $business_id)
            ->with(['assetAccount', 'accumulatedDepreciationAccount', 'depreciationExpenseAccount', 'depreciationMappings', 'acquisitionMapping', 'disposalMapping'])
            ->findOrFail($fixed_asset);

        $accounts = $this->accountsForSelect($business_id);

        return view('accounting::fixed_assets.show', compact('asset', 'accounts'));
    }

    public function edit(int $fixed_asset)
    {
        $business_id = request()->session()->get('user.business_id');

        if (! (auth()->user()->can('superadmin') ||
            $this->moduleUtil->hasThePermissionInSubscription($business_id, 'accounting_module')) ||
            ! auth()->user()->can('accounting.manage_fixed_assets')) {
            abort(403, 'Unauthorized action.');
        }

        $asset = AccountingFixedAsset::where('business_id', $business_id)->findOrFail($fixed_asset);
        if ($asset->status === 'disposed') {
            return redirect()->route('accounting.fixedAssets.show', $asset->id)
                ->with('status', ['success' => false, 'msg' => __('accounting::lang.cannot_edit_disposed_asset')]);
        }

        $accounts = $this->accountsForSelect($business_id);
        $business_locations = BusinessLocation::forDropdown($business_id, true);
        $faRestrict = $asset->depreciationMappings()->exists() || $asset->acquisition_mapping_id;

        return view('accounting::fixed_assets.edit', compact('asset', 'accounts', 'business_locations', 'faRestrict'));
    }

    public function update(Request $request, int $fixed_asset)
    {
        $business_id = request()->session()->get('user.business_id');

        if (! (auth()->user()->can('superadmin') ||
            $this->moduleUtil->hasThePermissionInSubscription($business_id, 'accounting_module')) ||
            ! auth()->user()->can('accounting.manage_fixed_assets')) {
            abort(403, 'Unauthorized action.');
        }

        $asset = AccountingFixedAsset::where('business_id', $business_id)->findOrFail($fixed_asset);

        if ($asset->status === 'disposed') {
            return redirect()->back()
                ->with('status', ['success' => false, 'msg' => __('accounting::lang.cannot_edit_disposed_asset')]);
        }

        $restrict = $asset->depreciationMappings()->exists() || $asset->acquisition_mapping_id;

        if ($restrict) {
            $uniqueCode = Rule::unique('accounting_fixed_assets', 'asset_code')
                ->where(fn ($q) => $q->where('business_id', $business_id))
                ->ignore($asset->id);

            $validated = $request->validate([
                'name' => 'required|string|max:256',
                'asset_code' => ['nullable', 'string', 'max:64', $uniqueCode],
                'notes' => 'nullable|string',
                'location_id' => 'nullable|integer',
            ]);
            $location_id = $request->input('location_id');
            $validated['location_id'] = ($location_id === '' || $location_id === null) ? null : (int) $location_id;

            $ac = trim((string) ($validated['asset_code'] ?? ''));
            if ($ac === '') {
                try {
                    $validated['asset_code'] = $this->accountingUtil->generateNextFixedAssetCode($business_id);
                } catch (\RuntimeException $e) {
                    throw ValidationException::withMessages(['asset_code' => [$e->getMessage()]]);
                }
            } else {
                $validated['asset_code'] = $ac;
            }

            $before = $asset->only(['name', 'asset_code', 'notes', 'location_id']);
            $asset->update($validated);

            AccountingAuditService::log(
                $business_id,
                (int) request()->session()->get('user.id'),
                'fixed_asset.updated',
                AccountingFixedAsset::class,
                (int) $asset->id,
                $before,
                $asset->fresh()->only(['name', 'asset_code', 'notes', 'location_id'])
            );

            return redirect()->route('accounting.fixedAssets.index')
                ->with('status', ['success' => true, 'msg' => __('lang_v1.updated_success')]);
        }

        $before = $asset->only([
            'name', 'asset_code', 'location_id', 'asset_account_id', 'cost', 'acquisition_date',
            'useful_life_months', 'is_depreciable', 'opening_accumulated_depreciation', 'notes',
            'accumulated_depreciation_account_id', 'depreciation_expense_account_id', 'salvage_value',
        ]);
        $data = $this->validateAsset($request, $business_id, $asset->id);
        $asset->update($data);

        AccountingAuditService::log(
            $business_id,
            (int) request()->session()->get('user.id'),
            'fixed_asset.updated',
            AccountingFixedAsset::class,
            (int) $asset->id,
            $before,
            $asset->fresh()->only(array_keys($before))
        );

        return redirect()->route('accounting.fixedAssets.index')
            ->with('status', ['success' => true, 'msg' => __('lang_v1.updated_success')]);
    }

    public function destroy(int $fixed_asset)
    {
        $business_id = request()->session()->get('user.business_id');

        if (! (auth()->user()->can('superadmin') ||
            $this->moduleUtil->hasThePermissionInSubscription($business_id, 'accounting_module')) ||
            ! auth()->user()->can('accounting.manage_fixed_assets')) {
            abort(403, 'Unauthorized action.');
        }

        $asset = AccountingFixedAsset::where('business_id', $business_id)->findOrFail($fixed_asset);

        if ($asset->depreciationMappings()->exists()) {
            return ['success' => 0, 'msg' => __('accounting::lang.fixed_asset_cannot_delete_after_depreciation')];
        }
        if ($asset->acquisition_mapping_id) {
            return ['success' => 0, 'msg' => __('accounting::lang.fixed_asset_cannot_delete_after_acquisition')];
        }
        if ($asset->disposal_mapping_id || $asset->status === 'disposed') {
            return ['success' => 0, 'msg' => __('accounting::lang.fixed_asset_cannot_delete_after_disposal')];
        }

        $id = (int) $asset->id;
        $asset->delete();

        AccountingAuditService::log(
            $business_id,
            (int) request()->session()->get('user.id'),
            'fixed_asset.deleted',
            AccountingFixedAsset::class,
            $id,
            ['id' => $id],
            null
        );

        return ['success' => 1, 'msg' => __('lang_v1.deleted_success')];
    }

    public function postAcquisitionForm(int $fixed_asset)
    {
        $business_id = request()->session()->get('user.business_id');

        if (! (auth()->user()->can('superadmin') ||
            $this->moduleUtil->hasThePermissionInSubscription($business_id, 'accounting_module')) ||
            ! auth()->user()->can('accounting.manage_fixed_assets')) {
            abort(403, 'Unauthorized action.');
        }

        $asset = AccountingFixedAsset::where('business_id', $business_id)->findOrFail($fixed_asset);
        if ($asset->acquisition_mapping_id) {
            return redirect()->route('accounting.fixedAssets.show', $asset->id)
                ->with('status', ['success' => false, 'msg' => __('accounting::lang.acquisition_already_posted')]);
        }
        if ($asset->status === 'disposed') {
            abort(403, 'Unauthorized action.');
        }

        $accounts = $this->accountsForSelect($business_id);
        $defaultDate = Carbon::parse($asset->acquisition_date)->format('Y-m-d');

        return view('accounting::fixed_assets.post_acquisition', compact('asset', 'accounts', 'defaultDate'));
    }

    public function postAcquisition(Request $request, int $fixed_asset)
    {
        $business_id = request()->session()->get('user.business_id');

        if (! (auth()->user()->can('superadmin') ||
            $this->moduleUtil->hasThePermissionInSubscription($business_id, 'accounting_module')) ||
            ! auth()->user()->can('accounting.manage_fixed_assets')) {
            abort(403, 'Unauthorized action.');
        }

        $asset = AccountingFixedAsset::where('business_id', $business_id)->findOrFail($fixed_asset);

        $request->validate([
            'counter_account_id' => 'required|integer',
            'operation_date' => 'required',
        ]);

        $opDateStr = $this->util->uf_date($request->input('operation_date'), false);
        $opDate = Carbon::parse($opDateStr);
        $counterId = (int) $request->input('counter_account_id');

        try {
            $this->acquisitionService->post(
                $asset,
                $business_id,
                (int) request()->session()->get('user.id'),
                $counterId,
                $opDate
            );
        } catch (\Throwable $e) {
            return redirect()->back()->with('status', ['success' => false, 'msg' => $e->getMessage()]);
        }

        return redirect()->route('accounting.fixedAssets.show', $asset->id)
            ->with('status', ['success' => true, 'msg' => __('accounting::lang.acquisition_posted_success')]);
    }

    public function disposeForm(int $fixed_asset)
    {
        $business_id = request()->session()->get('user.business_id');

        if (! (auth()->user()->can('superadmin') ||
            $this->moduleUtil->hasThePermissionInSubscription($business_id, 'accounting_module')) ||
            ! auth()->user()->can('accounting.manage_fixed_assets')) {
            abort(403, 'Unauthorized action.');
        }

        $asset = AccountingFixedAsset::where('business_id', $business_id)->findOrFail($fixed_asset);
        if ($asset->status === 'disposed') {
            return redirect()->route('accounting.fixedAssets.show', $asset->id)
                ->with('status', ['success' => false, 'msg' => __('accounting::lang.asset_already_disposed')]);
        }

        $accounts = $this->accountsForSelect($business_id);
        $defaultDate = now()->format('Y-m-d');

        return view('accounting::fixed_assets.dispose', compact('asset', 'accounts', 'defaultDate'));
    }

    public function dispose(Request $request, int $fixed_asset)
    {
        $business_id = request()->session()->get('user.business_id');

        if (! (auth()->user()->can('superadmin') ||
            $this->moduleUtil->hasThePermissionInSubscription($business_id, 'accounting_module')) ||
            ! auth()->user()->can('accounting.manage_fixed_assets')) {
            abort(403, 'Unauthorized action.');
        }

        $asset = AccountingFixedAsset::where('business_id', $business_id)->findOrFail($fixed_asset);

        $request->validate([
            'proceeds' => 'nullable|numeric|min:0',
            'proceeds_account_id' => 'required|integer',
            'gain_loss_account_id' => 'required|integer',
            'operation_date' => 'required',
        ]);

        $opDateStr = $this->util->uf_date($request->input('operation_date'), false);
        $opDate = Carbon::parse($opDateStr);
        $proceeds = $this->util->num_uf($request->input('proceeds', 0));

        try {
            $this->disposalService->post(
                $asset->fresh(),
                $business_id,
                (int) request()->session()->get('user.id'),
                (float) $proceeds,
                (int) $request->input('proceeds_account_id'),
                (int) $request->input('gain_loss_account_id'),
                $opDate
            );
        } catch (\Throwable $e) {
            return redirect()->back()->with('status', ['success' => false, 'msg' => $e->getMessage()]);
        }

        return redirect()->route('accounting.fixedAssets.show', $asset->id)
            ->with('status', ['success' => true, 'msg' => __('accounting::lang.disposal_posted_success')]);
    }

    public function depreciateForm()
    {
        $business_id = request()->session()->get('user.business_id');

        if (! (auth()->user()->can('superadmin') ||
            $this->moduleUtil->hasThePermissionInSubscription($business_id, 'accounting_module')) ||
            ! auth()->user()->can('accounting.run_depreciation')) {
            abort(403, 'Unauthorized action.');
        }

        $defaultPeriod = now()->format('Y-m');
        $defaultDate = now()->endOfMonth()->format('Y-m-d');

        return view('accounting::fixed_assets.depreciate', compact('defaultPeriod', 'defaultDate'));
    }

    public function depreciateRun(Request $request)
    {
        $business_id = request()->session()->get('user.business_id');

        if (! (auth()->user()->can('superadmin') ||
            $this->moduleUtil->hasThePermissionInSubscription($business_id, 'accounting_module')) ||
            ! auth()->user()->can('accounting.run_depreciation')) {
            abort(403, 'Unauthorized action.');
        }

        $request->validate([
            'depreciation_period' => 'required|regex:/^\d{4}-\d{2}$/',
            'operation_date' => 'required',
        ]);

        $period = $request->input('depreciation_period');
        $opDateStr = $this->util->uf_date($request->input('operation_date'), false);
        $opDate = Carbon::parse($opDateStr);

        if ($opDate->format('Y-m') !== $period) {
            return redirect()->back()->with('status', [
                'success' => false,
                'msg' => __('accounting::lang.depreciation_period_must_match_operation_month'),
            ]);
        }

        try {
            $this->accountingUtil->assertOperationDateNotLocked($business_id, $opDate);
        } catch (\RuntimeException $e) {
            return redirect()->back()->with('status', ['success' => false, 'msg' => $e->getMessage()]);
        }

        $userId = (int) request()->session()->get('user.id');

        $result = $this->depreciationService->runMonth($business_id, $userId, $period, $opDate);

        $msg = __('accounting::lang.depreciation_run_summary', [
            'posted' => $result['posted'],
            'skipped' => $result['skipped'],
        ]);

        if (! empty($result['errors'])) {
            $msg .= ' '.implode(' ', $result['errors']);
        }

        return redirect()->route('accounting.fixedAssets.index')
            ->with('status', ['success' => empty($result['errors']), 'msg' => $msg]);
    }

    /**
     * @return array<string, string>
     */
    /**
     * @return Collection<int, AccountingFixedAsset>
     */
    private function scheduleAssetsQuery(int $business_id, ?int $location_id, mixed $status): Collection
    {
        $q = AccountingFixedAsset::where('business_id', $business_id)
            ->with(['assetAccount', 'accumulatedDepreciationAccount', 'depreciationExpenseAccount', 'location']);

        if ($location_id !== null) {
            $q->where('location_id', $location_id);
        }
        if (! empty($status)) {
            $q->where('status', $status);
        }

        return $q->orderBy('name')->get();
    }

    private function fixedAssetStatusLabel(?string $status): string
    {
        return match ($status) {
            'active' => __('accounting::lang.fixed_asset_status_active'),
            'fully_depreciated' => __('accounting::lang.fixed_asset_status_fully_depreciated'),
            'disposed' => __('accounting::lang.fixed_asset_status_disposed'),
            default => $status ?? '',
        };
    }

    private function accountsForSelect(int $business_id): array
    {
        return AccountingAccount::where('business_id', $business_id)
            ->where('status', 'active')
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn ($a) => [$a->id => ($a->gl_code ? $a->gl_code.' — ' : '').$a->name])
            ->all();
    }

    private function validateAccountPrimaryType(int $business_id, int $accountId, string $expected, string $field = 'asset_account_id'): void
    {
        $acc = AccountingAccount::where('business_id', $business_id)->where('id', $accountId)->first();
        if (! $acc || $acc->account_primary_type !== $expected) {
            throw ValidationException::withMessages([
                $field => [__('accounting::lang.invalid_account_type')],
            ]);
        }
    }

    private function validateAsset(Request $request, int $business_id, ?int $ignore_asset_id = null): array
    {
        $uniqueCode = Rule::unique('accounting_fixed_assets', 'asset_code')
            ->where(fn ($q) => $q->where('business_id', $business_id));
        if ($ignore_asset_id !== null) {
            $uniqueCode = $uniqueCode->ignore($ignore_asset_id);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:256',
            'asset_code' => ['nullable', 'string', 'max:64', $uniqueCode],
            'location_id' => 'nullable|integer',
            'asset_account_id' => 'required|integer',
            'accumulated_depreciation_account_id' => 'nullable|integer',
            'depreciation_expense_account_id' => 'nullable|integer',
            'acquisition_date' => 'required',
            'cost' => 'required|numeric|min:0',
            'salvage_value' => 'nullable|numeric|min:0',
            'opening_accumulated_depreciation' => 'nullable|numeric|min:0',
            'useful_life_months' => 'nullable|integer|min:1',
            'notes' => 'nullable|string',
            'is_depreciable' => 'nullable|in:0,1',
        ]);

        $isDepreciable = (bool) ((int) $request->input('is_depreciable', 1));

        $location_id = $request->input('location_id');
        $validated['location_id'] = ($location_id === '' || $location_id === null) ? null : (int) $location_id;
        $validated['cost'] = $this->util->num_uf($request->input('cost'));
        $validated['acquisition_date'] = $this->util->uf_date($request->input('acquisition_date'), false);
        $validated['is_depreciable'] = $isDepreciable;
        $validated['opening_accumulated_depreciation'] = $this->util->num_uf($request->input('opening_accumulated_depreciation', 0));

        foreach (['asset_account_id'] as $fid) {
            $ok = AccountingAccount::where('business_id', $business_id)
                ->where('id', $validated[$fid])
                ->exists();
            if (! $ok) {
                throw ValidationException::withMessages([
                    $fid => [__('accounting::lang.invalid_account')],
                ]);
            }
        }
        $this->validateAccountPrimaryType($business_id, (int) $validated['asset_account_id'], 'asset', 'asset_account_id');

        if ($isDepreciable) {
            $request->validate([
                'accumulated_depreciation_account_id' => 'required|integer',
                'depreciation_expense_account_id' => 'required|integer',
                'useful_life_months' => 'required|integer|min:1',
            ]);

            $validated['accumulated_depreciation_account_id'] = (int) $request->input('accumulated_depreciation_account_id');
            $validated['depreciation_expense_account_id'] = (int) $request->input('depreciation_expense_account_id');
            $validated['useful_life_months'] = (int) $request->input('useful_life_months');
            $validated['salvage_value'] = $this->util->num_uf($request->input('salvage_value', 0));
            $validated['depreciation_method'] = 'straight_line';

            foreach (['accumulated_depreciation_account_id', 'depreciation_expense_account_id'] as $fid) {
                $ok = AccountingAccount::where('business_id', $business_id)
                    ->where('id', $validated[$fid])
                    ->exists();
                if (! $ok) {
                    throw ValidationException::withMessages([
                        $fid => [__('accounting::lang.invalid_account')],
                    ]);
                }
            }
            $this->validateAccountPrimaryType($business_id, $validated['accumulated_depreciation_account_id'], 'asset', 'accumulated_depreciation_account_id');
            $this->validateAccountPrimaryType($business_id, $validated['depreciation_expense_account_id'], 'expenses', 'depreciation_expense_account_id');

            if ((float) $validated['cost'] <= 0) {
                throw ValidationException::withMessages([
                    'cost' => [__('accounting::lang.cost_must_be_positive_depreciable')],
                ]);
            }

            if ((float) $validated['salvage_value'] > (float) $validated['cost']) {
                throw ValidationException::withMessages([
                    'salvage_value' => [__('accounting::lang.salvage_cannot_exceed_cost')],
                ]);
            }
            $maxOpening = (float) $validated['cost'] - (float) $validated['salvage_value'];
            if ((float) $validated['opening_accumulated_depreciation'] > $maxOpening + 0.0001) {
                throw ValidationException::withMessages([
                    'opening_accumulated_depreciation' => [__('accounting::lang.opening_accum_exceeds_depreciable')],
                ]);
            }
        } else {
            $validated['accumulated_depreciation_account_id'] = null;
            $validated['depreciation_expense_account_id'] = null;
            $validated['useful_life_months'] = null;
            $validated['salvage_value'] = 0;
            $validated['opening_accumulated_depreciation'] = 0;
            $validated['depreciation_method'] = 'none';
        }

        $validated['status'] = 'active';

        $ac = trim((string) ($validated['asset_code'] ?? ''));
        if ($ac === '') {
            try {
                $validated['asset_code'] = $this->accountingUtil->generateNextFixedAssetCode($business_id);
            } catch (\RuntimeException $e) {
                throw ValidationException::withMessages(['asset_code' => [$e->getMessage()]]);
            }
        } else {
            $validated['asset_code'] = $ac;
        }

        return $validated;
    }
}
