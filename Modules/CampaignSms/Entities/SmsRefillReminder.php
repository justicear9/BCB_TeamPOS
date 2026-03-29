<?php

namespace Modules\CampaignSms\Entities;

use Illuminate\Database\Eloquent\Model;

class SmsRefillReminder extends Model
{
    protected $table = 'sms_refill_reminders';

    protected $guarded = ['id'];

    protected $casts = [
        'next_run_at' => 'datetime',
        'last_sent_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    public function business()
    {
        return $this->belongsTo(\App\Business::class, 'business_id');
    }

    public function contact()
    {
        return $this->belongsTo(\App\Contact::class, 'contact_id');
    }

    public function product()
    {
        return $this->belongsTo(\App\Product::class, 'product_id');
    }
}
