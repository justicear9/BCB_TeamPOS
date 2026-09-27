<?php

namespace Modules\Cashier\Entities;

use Illuminate\Database\Eloquent\Model;

class CashierClientRef extends Model
{
    protected $table = 'cashier_client_refs';

    protected $guarded = ['id'];
}
