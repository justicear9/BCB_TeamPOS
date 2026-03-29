<?php

namespace Modules\CampaignSms\Entities;

use Illuminate\Database\Eloquent\Model;

class SmsPosInvoiceRefillSnapshot extends Model
{
    protected $table = 'sms_pos_invoice_refill_snapshots';

    protected $guarded = ['id'];

    protected $casts = [
        'lines' => 'array',
    ];

    public function transaction()
    {
        return $this->belongsTo(\App\Transaction::class, 'transaction_id');
    }
}
