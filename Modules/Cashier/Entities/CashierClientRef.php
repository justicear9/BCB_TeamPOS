<?php

namespace Modules\Cashier\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;

class CashierClientRef extends Model
{
    protected $table = 'cashier_client_refs';

    protected $guarded = ['id'];

    /**
     * Two requests with the same client_uuid can race past the replay check.
     * The unique index stops the second one; callers then replay the first.
     */
    public static function isDuplicate(QueryException $e): bool
    {
        return ($e->errorInfo[0] ?? null) === '23000'
            && str_contains($e->getMessage(), 'cashier_client_refs');
    }
}
