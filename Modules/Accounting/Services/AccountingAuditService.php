<?php

namespace Modules\Accounting\Services;

use Illuminate\Support\Facades\Request;
use Modules\Accounting\Entities\AccountingAuditLog;

class AccountingAuditService
{
    public static function log(
        int $businessId,
        ?int $userId,
        string $action,
        ?string $auditableType,
        ?int $auditableId,
        $before = null,
        $after = null
    ): void {
        AccountingAuditLog::create([
            'business_id' => $businessId,
            'user_id' => $userId,
            'action' => $action,
            'auditable_type' => $auditableType,
            'auditable_id' => $auditableId,
            'before' => $before,
            'after' => $after,
            'ip_address' => Request::ip(),
            'created_at' => now(),
        ]);
    }
}
