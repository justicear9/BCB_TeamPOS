<?php

namespace Modules\CampaignSms\Entities;

use Illuminate\Database\Eloquent\Model;

class SmsCampaign extends Model
{
    protected $table = 'sms_campaigns';

    protected $guarded = ['id'];

    public function recipients()
    {
        return $this->hasMany(SmsCampaignRecipient::class, 'sms_campaign_id');
    }

    public function business()
    {
        return $this->belongsTo(\App\Business::class, 'business_id');
    }
}
