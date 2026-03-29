<?php

namespace Modules\Accounting\Services;

use Illuminate\Support\Facades\DB;
use Modules\Accounting\Entities\AccountingAccountsTransaction;
use Modules\Accounting\Entities\AccountingAccTransMapping;
use Modules\Accounting\Entities\AccountingAccount;
use Modules\Accounting\Entities\AccountingFixedAsset;
use Modules\Accounting\Utils\AccountingUtil;

class FixedAssetDisposalService
{
    public function __construct(protected AccountingUtil $accountingUtil)
    {
    }

    /**
     * @throws \RuntimeException
     */
    public function post(
        AccountingFixedAsset $asset,
        int $businessId,
        int $userId,
        float $proceeds,
        int $proceedsAccountId,
        int $gainLossAccountId,
        \DateTimeInterface $operationDate
    ): AccountingAccTransMapping {
        if ($asset->business_id !== $businessId) {
            throw new \RuntimeException('Invalid asset.');
        }
        if ($asset->status === 'disposed') {
            throw new \RuntimeException(__('accounting::lang.asset_already_disposed'));
        }

        $this->accountingUtil->assertOperationDateNotLocked($businessId, $operationDate);

        foreach ([$proceedsAccountId, $gainLossAccountId, $asset->asset_account_id] as $aid) {
            $ok = AccountingAccount::where('business_id', $businessId)
                ->where('id', $aid)
                ->where('status', 'active')
                ->exists();
            if (! $ok) {
                throw new \RuntimeException(__('accounting::lang.invalid_account'));
            }
        }

        $C = round((float) $asset->cost, 4);
        $A = round($asset->totalAccumulatedDepreciation(), 4);
        if ($A > 0 && $asset->is_depreciable && empty($asset->accumulated_depreciation_account_id)) {
            throw new \RuntimeException(__('accounting::lang.missing_accum_account'));
        }
        $P = round($proceeds, 4);

        $plug = round($P + $A - $C, 4);

        DB::beginTransaction();

        try {
            $refNo = 'FADIS-'.$businessId.'-'.$asset->id.'-'.time();

            $mapping = new AccountingAccTransMapping();
            $mapping->business_id = $businessId;
            $mapping->ref_no = $refNo;
            $mapping->type = 'fixed_asset_disposal';
            $mapping->created_by = $userId;
            $mapping->operation_date = $operationDate;
            $mapping->note = __('accounting::lang.fixed_asset_disposal_note', ['name' => $asset->name]);
            $mapping->fixed_asset_id = $asset->id;
            $mapping->save();

            $locationId = $asset->location_id;

            if ($A > 0 && $asset->accumulated_depreciation_account_id) {
                $drAccum = new AccountingAccountsTransaction();
                $drAccum->acc_trans_mapping_id = $mapping->id;
                $drAccum->accounting_account_id = $asset->accumulated_depreciation_account_id;
                $drAccum->amount = $A;
                $drAccum->type = 'debit';
                $drAccum->sub_type = 'fixed_asset_disposal';
                $drAccum->created_by = $userId;
                $drAccum->operation_date = $operationDate;
                $drAccum->location_id = $locationId;
                $drAccum->save();
            }

            if ($P > 0) {
                $drCash = new AccountingAccountsTransaction();
                $drCash->acc_trans_mapping_id = $mapping->id;
                $drCash->accounting_account_id = $proceedsAccountId;
                $drCash->amount = $P;
                $drCash->type = 'debit';
                $drCash->sub_type = 'fixed_asset_disposal';
                $drCash->created_by = $userId;
                $drCash->operation_date = $operationDate;
                $drCash->location_id = $locationId;
                $drCash->save();
            }

            $crAsset = new AccountingAccountsTransaction();
            $crAsset->acc_trans_mapping_id = $mapping->id;
            $crAsset->accounting_account_id = $asset->asset_account_id;
            $crAsset->amount = $C;
            $crAsset->type = 'credit';
            $crAsset->sub_type = 'fixed_asset_disposal';
            $crAsset->created_by = $userId;
            $crAsset->operation_date = $operationDate;
            $crAsset->location_id = $locationId;
            $crAsset->save();

            if (abs($plug) > 0.0001) {
                $gl = new AccountingAccountsTransaction();
                $gl->acc_trans_mapping_id = $mapping->id;
                $gl->accounting_account_id = $gainLossAccountId;
                $gl->amount = abs($plug);
                if ($plug >= 0) {
                    $gl->type = 'credit';
                } else {
                    $gl->type = 'debit';
                }
                $gl->sub_type = 'fixed_asset_disposal';
                $gl->created_by = $userId;
                $gl->operation_date = $operationDate;
                $gl->location_id = $locationId;
                $gl->save();
            }

            $asset->status = 'disposed';
            $asset->disposed_at = $operationDate;
            $asset->disposal_mapping_id = $mapping->id;
            $asset->save();

            DB::commit();

            AccountingAuditService::log(
                $businessId,
                $userId,
                'fixed_asset.disposal_posted',
                AccountingFixedAsset::class,
                (int) $asset->id,
                null,
                ['mapping_id' => $mapping->id, 'proceeds' => $P]
            );

            return $mapping;
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }
}
