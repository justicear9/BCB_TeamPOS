<?php

namespace Modules\Accounting\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Entities\AccountingAccountsTransaction;
use Modules\Accounting\Entities\AccountingAccTransMapping;
use Modules\Accounting\Entities\AccountingFixedAsset;

class FixedAssetDepreciationService
{
    /**
     * @return array{posted: int, skipped: int, errors: array<int, string>}
     */
    public function runMonth(
        int $businessId,
        int $userId,
        string $periodYm,
        \DateTimeInterface $operationDate
    ): array {
        $posted = 0;
        $skipped = 0;
        $errors = [];

        $assets = AccountingFixedAsset::where('business_id', $businessId)
            ->where('status', 'active')
            ->where('is_depreciable', true)
            ->where('depreciation_method', 'straight_line')
            ->get();

        foreach ($assets as $asset) {
            try {
                if (! $asset->depreciation_expense_account_id || ! $asset->accumulated_depreciation_account_id) {
                    $skipped++;

                    continue;
                }

                $acqYm = Carbon::parse($asset->acquisition_date)->format('Y-m');
                if ($periodYm < $acqYm) {
                    $skipped++;

                    continue;
                }

                $exists = AccountingAccTransMapping::where('business_id', $businessId)
                    ->where('type', 'fixed_asset_depreciation')
                    ->where('fixed_asset_id', $asset->id)
                    ->where('depreciation_period', $periodYm)
                    ->exists();

                if ($exists) {
                    $skipped++;

                    continue;
                }

                $remainingDepreciable = $asset->remainingDepreciableBase();
                if ($remainingDepreciable <= 0) {
                    $asset->status = 'fully_depreciated';
                    $asset->save();
                    $skipped++;

                    continue;
                }

                $monthly = $asset->monthlyStraightLineAmount();
                $ratio = 1.0;
                if ($periodYm === $acqYm) {
                    $acq = Carbon::parse($asset->acquisition_date)->startOfDay();
                    $periodEnd = Carbon::createFromFormat('Y-m', $periodYm)->endOfMonth();
                    $daysInMonth = (int) $acq->daysInMonth;
                    $daysFromAcq = (int) $acq->diffInDays($periodEnd) + 1;
                    $daysFromAcq = max(0, min($daysInMonth, $daysFromAcq));
                    $ratio = $daysInMonth > 0 ? ($daysFromAcq / $daysInMonth) : 0.0;
                }

                $baseAmount = $monthly * $ratio;
                $amount = round(min($baseAmount, $remainingDepreciable), 4);
                if ($amount <= 0) {
                    $skipped++;

                    continue;
                }

                DB::beginTransaction();

                $refNo = 'FAD-'.$businessId.'-'.$asset->id.'-'.$periodYm;

                $mapping = new AccountingAccTransMapping();
                $mapping->business_id = $businessId;
                $mapping->ref_no = $refNo;
                $mapping->type = 'fixed_asset_depreciation';
                $mapping->created_by = $userId;
                $mapping->operation_date = $operationDate;
                $mapping->note = __('accounting::lang.fixed_asset_depreciation_note', ['name' => $asset->name, 'period' => $periodYm]);
                $mapping->fixed_asset_id = $asset->id;
                $mapping->depreciation_period = $periodYm;
                $mapping->save();

                $locationId = $asset->location_id;

                $expense = new AccountingAccountsTransaction();
                $expense->acc_trans_mapping_id = $mapping->id;
                $expense->accounting_account_id = $asset->depreciation_expense_account_id;
                $expense->amount = $amount;
                $expense->type = 'debit';
                $expense->sub_type = 'fixed_asset_depreciation';
                $expense->created_by = $userId;
                $expense->operation_date = $operationDate;
                $expense->location_id = $locationId;
                $expense->save();

                $accum = new AccountingAccountsTransaction();
                $accum->acc_trans_mapping_id = $mapping->id;
                $accum->accounting_account_id = $asset->accumulated_depreciation_account_id;
                $accum->amount = $amount;
                $accum->type = 'credit';
                $accum->sub_type = 'fixed_asset_depreciation';
                $accum->created_by = $userId;
                $accum->operation_date = $operationDate;
                $accum->location_id = $locationId;
                $accum->save();

                $asset->accumulated_depreciation_posted = (float) $asset->accumulated_depreciation_posted + $amount;
                if ($asset->netBookValue() <= (float) $asset->salvage_value + 0.0001) {
                    $asset->status = 'fully_depreciated';
                }
                $asset->save();

                DB::commit();

                AccountingAuditService::log(
                    $businessId,
                    $userId,
                    'fixed_asset.depreciation_posted',
                    AccountingFixedAsset::class,
                    (int) $asset->id,
                    null,
                    ['period' => $periodYm, 'amount' => $amount, 'mapping_id' => $mapping->id]
                );

                $posted++;
            } catch (\Throwable $e) {
                DB::rollBack();
                $errors[] = $asset->name.': '.$e->getMessage();
            }
        }

        return compact('posted', 'skipped', 'errors');
    }
}
