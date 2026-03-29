<?php

namespace Modules\Accounting\Entities;

use App\BusinessLocation;
use Illuminate\Database\Eloquent\Model;

class AccountingFixedAsset extends Model
{
    protected $guarded = [];

    protected $casts = [
        'acquisition_date' => 'date',
        'disposed_at' => 'date',
        'cost' => 'decimal:4',
        'salvage_value' => 'decimal:4',
        'opening_accumulated_depreciation' => 'decimal:4',
        'accumulated_depreciation_posted' => 'decimal:4',
        'is_depreciable' => 'boolean',
    ];

    public function assetAccount()
    {
        return $this->belongsTo(AccountingAccount::class, 'asset_account_id');
    }

    public function accumulatedDepreciationAccount()
    {
        return $this->belongsTo(AccountingAccount::class, 'accumulated_depreciation_account_id');
    }

    public function depreciationExpenseAccount()
    {
        return $this->belongsTo(AccountingAccount::class, 'depreciation_expense_account_id');
    }

    public function location()
    {
        return $this->belongsTo(BusinessLocation::class, 'location_id');
    }

    public function acquisitionMapping()
    {
        return $this->belongsTo(AccountingAccTransMapping::class, 'acquisition_mapping_id');
    }

    public function disposalMapping()
    {
        return $this->belongsTo(AccountingAccTransMapping::class, 'disposal_mapping_id');
    }

    public function depreciationMappings()
    {
        return $this->hasMany(AccountingAccTransMapping::class, 'fixed_asset_id')
            ->where('type', 'fixed_asset_depreciation')
            ->orderByDesc('operation_date');
    }

    /**
     * Opening balance + amounts posted by this system's depreciation runs.
     */
    public function totalAccumulatedDepreciation(): float
    {
        return (float) $this->opening_accumulated_depreciation + (float) $this->accumulated_depreciation_posted;
    }

    public function netBookValue(): float
    {
        return (float) $this->cost - $this->totalAccumulatedDepreciation();
    }

    /**
     * Remaining depreciable base (straight-line cap) after opening + posted depreciation runs.
     */
    public function remainingDepreciableBase(): float
    {
        $cap = (float) $this->cost - (float) $this->salvage_value;

        return max(0.0, $cap - (float) $this->opening_accumulated_depreciation - (float) $this->accumulated_depreciation_posted);
    }

    public function monthlyStraightLineAmount(): float
    {
        if (! $this->is_depreciable || $this->depreciation_method === 'none' || ! $this->useful_life_months) {
            return 0.0;
        }

        $months = max(1, (int) $this->useful_life_months);
        $depreciable = (float) $this->cost - (float) $this->salvage_value;

        return $depreciable / $months;
    }
}
