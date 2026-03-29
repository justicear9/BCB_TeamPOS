<?php

namespace Modules\Accounting\Entities;

use Illuminate\Database\Eloquent\Model;

class AccountingAccTransMapping extends Model
{
    protected $fillable = [];

    public function fixedAsset()
    {
        return $this->belongsTo(AccountingFixedAsset::class, 'fixed_asset_id');
    }
}
