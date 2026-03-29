<?php

namespace Modules\Accounting\Entities;

use Illuminate\Database\Eloquent\Model;

class AccountingBankAccount extends Model
{
    protected $guarded = [];

    public function glAccount()
    {
        return $this->belongsTo(AccountingAccount::class, 'accounting_account_id');
    }

    public function statementLines()
    {
        return $this->hasMany(AccountingBankStatementLine::class, 'bank_account_id');
    }
}
