<?php

namespace Modules\CampaignSms\Entities;

use Illuminate\Database\Eloquent\Model;

class SmsTokenBalance extends Model
{
    protected $table = 'sms_token_balances';

    protected $guarded = ['id'];

    public function business()
    {
        return $this->belongsTo(\App\Business::class, 'business_id');
    }
}
