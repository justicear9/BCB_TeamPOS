<?php

namespace Modules\CampaignSms\Entities;

use Illuminate\Database\Eloquent\Model;

class SmsCampaignSetting extends Model
{
    protected $table = 'sms_campaign_settings';

    protected $guarded = ['id'];

    public function business()
    {
        return $this->belongsTo(\App\Business::class, 'business_id');
    }
}
