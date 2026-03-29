<?php

namespace Modules\InventoryReporting\Entities;

use Illuminate\Database\Eloquent\Model;

class InventoryReportingLocationSetting extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'business_id' => 'integer',
        'location_id' => 'integer',
        'inventory_adjustment_offset_account_id' => 'integer',
    ];
}
