<?php

namespace Modules\Accounting\Services;

use Illuminate\Support\Facades\DB;
use Modules\Accounting\Entities\AccountingAccountsTransaction;
use Modules\Accounting\Entities\AccountingAccTransMapping;
use Modules\Accounting\Entities\AccountingAccount;
use Modules\Accounting\Entities\AccountingFixedAsset;
use Modules\Accounting\Utils\AccountingUtil;

class FixedAssetAcquisitionService
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
        int $counterAccountId,
        \DateTimeInterface $operationDate
    ): AccountingAccTransMapping {
        if ($asset->business_id !== $businessId) {
            throw new \RuntimeException('Invalid asset.');
        }
        if ($asset->acquisition_mapping_id) {
            throw new \RuntimeException(__('accounting::lang.acquisition_already_posted'));
        }
        if ($asset->status === 'disposed') {
            throw new \RuntimeException(__('accounting::lang.asset_disposed'));
        }

        $this->accountingUtil->assertOperationDateNotLocked($businessId, $operationDate);

        $counterOk = AccountingAccount::where('business_id', $businessId)
            ->where('id', $counterAccountId)
            ->where('status', 'active')
            ->exists();
        if (! $counterOk) {
            throw new \RuntimeException(__('accounting::lang.invalid_account'));
        }

        $amount = round((float) $asset->cost, 4);
        if ($amount <= 0) {
            throw new \RuntimeException(__('accounting::lang.cost_must_be_positive'));
        }

        DB::beginTransaction();

        try {
            $refNo = 'FAA-'.$businessId.'-'.$asset->id.'-'.time();

            $mapping = new AccountingAccTransMapping();
            $mapping->business_id = $businessId;
            $mapping->ref_no = $refNo;
            $mapping->type = 'fixed_asset_acquisition';
            $mapping->created_by = $userId;
            $mapping->operation_date = $operationDate;
            $mapping->note = __('accounting::lang.fixed_asset_acquisition_note', ['name' => $asset->name]);
            $mapping->fixed_asset_id = $asset->id;
            $mapping->save();

            $locationId = $asset->location_id;

            $dr = new AccountingAccountsTransaction();
            $dr->acc_trans_mapping_id = $mapping->id;
            $dr->accounting_account_id = $asset->asset_account_id;
            $dr->amount = $amount;
            $dr->type = 'debit';
            $dr->sub_type = 'fixed_asset_acquisition';
            $dr->created_by = $userId;
            $dr->operation_date = $operationDate;
            $dr->location_id = $locationId;
            $dr->save();

            $cr = new AccountingAccountsTransaction();
            $cr->acc_trans_mapping_id = $mapping->id;
            $cr->accounting_account_id = $counterAccountId;
            $cr->amount = $amount;
            $cr->type = 'credit';
            $cr->sub_type = 'fixed_asset_acquisition';
            $cr->created_by = $userId;
            $cr->operation_date = $operationDate;
            $cr->location_id = $locationId;
            $cr->save();

            $asset->acquisition_mapping_id = $mapping->id;
            $asset->save();

            DB::commit();

            AccountingAuditService::log(
                $businessId,
                $userId,
                'fixed_asset.acquisition_posted',
                AccountingFixedAsset::class,
                (int) $asset->id,
                null,
                ['mapping_id' => $mapping->id, 'amount' => $amount]
            );

            return $mapping;
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }
}
