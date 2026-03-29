<?php

namespace Modules\Accounting\Entities;

use Illuminate\Database\Eloquent\Model;

class AccountingBankStatementLine extends Model
{
    protected $guarded = [];

    protected $casts = [
        'line_date' => 'date',
        'reconciled_at' => 'datetime',
    ];

    public function bankAccount()
    {
        return $this->belongsTo(AccountingBankAccount::class, 'bank_account_id');
    }

    public function matchedAat()
    {
        return $this->belongsTo(AccountingAccountsTransaction::class, 'matched_aat_id');
    }
}
