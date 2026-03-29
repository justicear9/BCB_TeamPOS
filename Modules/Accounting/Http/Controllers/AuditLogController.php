<?php

namespace Modules\Accounting\Http\Controllers;

use App\Utils\ModuleUtil;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Accounting\Entities\AccountingAuditLog;
use Modules\Accounting\Entities\AccountingAccount;

class AuditLogController extends Controller
{
    public function __construct(protected ModuleUtil $moduleUtil)
    {
    }

    public function index(Request $request)
    {
        $business_id = (int) $request->session()->get('user.business_id');

        if (! (auth()->user()->can('superadmin') ||
            $this->moduleUtil->hasThePermissionInSubscription($business_id, 'accounting_module')) ||
            ! auth()->user()->can('accounting.view_audit_log')) {
            abort(403, 'Unauthorized action.');
        }

        $query = AccountingAuditLog::where('business_id', $business_id)->orderByDesc('id');

        if ($request->filled('action')) {
            $query->where('action', $request->input('action'));
        }

        if ($request->filled('from_date')) {
            $query->whereDate('created_at', '>=', $request->input('from_date'));
        }

        if ($request->filled('to_date')) {
            $query->whereDate('created_at', '<=', $request->input('to_date'));
        }

        $logs = $query->paginate(50)->withQueryString();

        return view('accounting::audit_log.index', compact('logs'));
    }
}
