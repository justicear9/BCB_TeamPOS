<?php

namespace Modules\AIBusinessManager\Services\Concerns;

use App\Business;
use App\User;
use App\Utils\ModuleUtil;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Accounting\Entities\AccountingAccount;
use Modules\Accounting\Services\FinancialStatementsService;
use Modules\Accounting\Utils\AccountingUtil;

trait AccountingSnapshotTools
{
    /**
     * @return array<string, mixed>|null Error payload or null if OK to proceed.
     */
    protected function accountingReadGuard(int $businessId, User $user): ?array
    {
        if (! Schema::hasTable('accounting_accounts') || ! Schema::hasTable('accounting_accounts_transactions')) {
            return ['ok' => false, 'error' => 'accounting_module_unavailable'];
        }

        $moduleUtil = app(ModuleUtil::class);
        $subscribed = $user->can('superadmin')
            || $moduleUtil->hasThePermissionInSubscription($businessId, 'accounting_module');
        if (! $subscribed) {
            return ['ok' => false, 'error' => 'accounting_module_unavailable'];
        }

        if (! $user->can('superadmin') && ! $user->can('accounting.view_reports')) {
            return ['ok' => false, 'error' => 'forbidden'];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected function accountingTrialBalanceSummary(array $args, int $businessId, User $user): array
    {
        $err = $this->accountingReadGuard($businessId, $user);
        if ($err !== null) {
            return $err;
        }

        $range = $this->parseDateRange($args, $businessId, $user);
        if (isset($range['ok']) && $range['ok'] === false) {
            return $range;
        }
        $start = $range['start']->format('Y-m-d');
        $end = $range['end']->format('Y-m-d');
        $precision = $range['precision'];

        $locCheck = $this->validateOptionalLocationForUser(
            isset($args['location_id']) ? (int) $args['location_id'] : null,
            $user
        );
        if ($locCheck['ok'] === false) {
            return $locCheck;
        }
        $location_id = $locCheck['location_id'];

        $maxAccounts = (int) config('aibusinessmanager.tool_accounting_tb_account_limit', 80);
        $limit = isset($args['limit']) ? (int) $args['limit'] : min(40, $maxAccounts);
        $limit = max(1, min($maxAccounts, $limit));

        $base = AccountingAccount::query()
            ->join('accounting_accounts_transactions as AAT', 'AAT.accounting_account_id', '=', 'accounting_accounts.id')
            ->where('accounting_accounts.business_id', $businessId)
            ->whereDate('AAT.operation_date', '>=', $start)
            ->whereDate('AAT.operation_date', '<=', $end)
            ->when($location_id !== null, fn ($q) => $q->where('AAT.location_id', $location_id));

        $totals = (clone $base)
            ->selectRaw("SUM(IF(AAT.type = 'credit', AAT.amount, 0)) as credit_balance")
            ->selectRaw("SUM(IF(AAT.type = 'debit', AAT.amount, 0)) as debit_balance")
            ->first();

        $rows = (clone $base)
            ->select(
                DB::raw("SUM(IF(AAT.type = 'credit', AAT.amount, 0)) as credit_balance"),
                DB::raw("SUM(IF(AAT.type = 'debit', AAT.amount, 0)) as debit_balance"),
                'accounting_accounts.id as account_id',
                'accounting_accounts.gl_code',
                'accounting_accounts.name'
            )
            ->groupBy('accounting_accounts.id', 'accounting_accounts.gl_code', 'accounting_accounts.name')
            ->orderBy('accounting_accounts.gl_code')
            ->orderBy('accounting_accounts.name')
            ->limit($limit)
            ->get();

        return [
            'ok' => true,
            'source' => 'accounting_module',
            'start_date' => $start,
            'end_date' => $end,
            'location_id' => $location_id,
            'grand_totals' => [
                'debit' => round((float) ($totals->debit_balance ?? 0), $precision),
                'credit' => round((float) ($totals->credit_balance ?? 0), $precision),
            ],
            'account_rows' => $rows->map(fn ($r) => [
                'account_id' => (int) $r->account_id,
                'gl_code' => (string) ($r->gl_code ?? ''),
                'name' => (string) ($r->name ?? ''),
                'debit' => round((float) ($r->debit_balance ?? 0), $precision),
                'credit' => round((float) ($r->credit_balance ?? 0), $precision),
            ])->values()->all(),
            'caveat' => 'Capped account list; grand_totals span all GL activity in range. Use full Accounting Trial Balance UI for export and drill-down.',
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected function accountingArAgeingSummary(array $args, int $businessId, User $user): array
    {
        return $this->accountingAgeingSummary($args, $businessId, $user, 'sell', 'ar');
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected function accountingApAgeingSummary(array $args, int $businessId, User $user): array
    {
        return $this->accountingAgeingSummary($args, $businessId, $user, 'purchase', 'ap');
    }

    /**
     * @return array<string, mixed>
     */
    protected function accountingAgeingSummary(array $args, int $businessId, User $user, string $txnType, string $label): array
    {
        $err = $this->accountingReadGuard($businessId, $user);
        if ($err !== null) {
            return $err;
        }

        $b = Business::with('currency')->find($businessId);
        if (! $b) {
            return ['ok' => false, 'error' => 'business_not_found'];
        }
        $precision = (int) ($b->currency_precision ?? 2);

        $locCheck = $this->validateOptionalLocationForUser(
            isset($args['location_id']) ? (int) $args['location_id'] : null,
            $user
        );
        if ($locCheck['ok'] === false) {
            return $locCheck;
        }
        $location_id = $locCheck['location_id'];
        $locationParam = $location_id !== null && $location_id > 0 ? $location_id : null;

        $maxContacts = (int) config('aibusinessmanager.tool_accounting_ageing_contact_limit', 60);
        $topN = isset($args['limit']) ? (int) $args['limit'] : min(25, $maxContacts);
        $topN = max(1, min($maxContacts, $topN));

        /** @var AccountingUtil $util */
        $util = app(AccountingUtil::class);
        $details = $util->getAgeingReport($businessId, $txnType, 'contact', $locationParam);

        $buckets = ['<1' => 0.0, '1_30' => 0.0, '31_60' => 0.0, '61_90' => 0.0, '>90' => 0.0];
        $grand = 0.0;
        $contacts = [];
        foreach ($details as $cid => $row) {
            if (! is_array($row)) {
                continue;
            }
            $buckets['<1'] += (float) ($row['<1'] ?? 0);
            $buckets['1_30'] += (float) ($row['1_30'] ?? 0);
            $buckets['31_60'] += (float) ($row['31_60'] ?? 0);
            $buckets['61_90'] += (float) ($row['61_90'] ?? 0);
            $buckets['>90'] += (float) ($row['>90'] ?? 0);
            $td = (float) ($row['total_due'] ?? 0);
            $grand += $td;
            $contacts[] = [
                'contact_id' => (int) $cid,
                'name' => (string) ($row['name'] ?? ''),
                'total_due' => $td,
            ];
        }

        usort($contacts, fn ($a, $b) => $b['total_due'] <=> $a['total_due']);
        $contacts = array_slice($contacts, 0, $topN);
        foreach ($contacts as &$c) {
            $c['total_due'] = round($c['total_due'], $precision);
        }
        unset($c);

        $bucketOut = [];
        foreach ($buckets as $k => $v) {
            $bucketOut[$k] = round($v, $precision);
        }

        return [
            'ok' => true,
            'source' => 'accounting_module',
            'kind' => $label,
            'location_id' => $locationParam,
            'contact_count_with_balance' => count($details),
            'bucket_totals' => $bucketOut,
            'grand_total_due' => round($grand, $precision),
            'top_contacts_by_due' => $contacts,
            'caveat' => 'Same ageing engine as Accounting AR/AP reports (due-date vs today). Not identical to POS `receivables_ageing` / `payables_ageing` which bucket from invoice date.',
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected function accountingBalanceSheetHeadlines(array $args, int $businessId, User $user): array
    {
        $err = $this->accountingReadGuard($businessId, $user);
        if ($err !== null) {
            return $err;
        }

        $range = $this->parseDateRange($args, $businessId, $user);
        if (isset($range['ok']) && $range['ok'] === false) {
            return $range;
        }
        $start = $range['start']->format('Y-m-d');
        $end = $range['end']->format('Y-m-d');
        $precision = $range['precision'];

        $locCheck = $this->validateOptionalLocationForUser(
            isset($args['location_id']) ? (int) $args['location_id'] : null,
            $user
        );
        if ($locCheck['ok'] === false) {
            return $locCheck;
        }
        $location_id = $locCheck['location_id'];

        /** @var AccountingUtil $accountingUtil */
        $accountingUtil = app(AccountingUtil::class);
        $balance_formula = $accountingUtil->balanceFormula();

        $base = function (array $primaryTypes) use ($businessId, $start, $end, $balance_formula, $location_id) {
            return AccountingAccount::join('accounting_accounts_transactions as AAT',
                'AAT.accounting_account_id', '=', 'accounting_accounts.id')
                ->join('accounting_account_types as AATP',
                    'AATP.id', '=', 'accounting_accounts.account_sub_type_id')
                ->whereDate('AAT.operation_date', '>=', $start)
                ->whereDate('AAT.operation_date', '<=', $end)
                ->when($location_id !== null, fn ($q) => $q->where('AAT.location_id', $location_id))
                ->select(
                    DB::raw($balance_formula),
                    'accounting_accounts.id as account_id',
                    'accounting_accounts.gl_code',
                    'accounting_accounts.name',
                    'AATP.name as sub_type'
                )
                ->where('accounting_accounts.business_id', $businessId)
                ->whereIn('accounting_accounts.account_primary_type', $primaryTypes)
                ->groupBy('accounting_accounts.id', 'accounting_accounts.gl_code', 'accounting_accounts.name', 'AATP.name');
        };

        $sumSection = function ($collection): float {
            $s = 0.0;
            foreach ($collection as $r) {
                $s += (float) ($r->balance ?? 0);
            }

            return $s;
        };

        $assets = $sumSection($base(['asset'])->get());
        $liabilities = $sumSection($base(['liability'])->get());
        $equities = $sumSection($base(['equity'])->get());

        return [
            'ok' => true,
            'source' => 'accounting_module',
            'start_date' => $start,
            'end_date' => $end,
            'location_id' => $location_id,
            'section_totals' => [
                'assets' => round($assets, $precision),
                'liabilities' => round($liabilities, $precision),
                'equity' => round($equities, $precision),
            ],
            'caveat' => 'Section sums from posted GL in range (same formula as Balance Sheet report). Not a full statement layout — use Accounting UI for notes and comparative columns.',
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected function accountingCashFlowHeadlines(array $args, int $businessId, User $user): array
    {
        $err = $this->accountingReadGuard($businessId, $user);
        if ($err !== null) {
            return $err;
        }

        $range = $this->parseDateRange($args, $businessId, $user);
        if (isset($range['ok']) && $range['ok'] === false) {
            return $range;
        }
        $start = $range['start']->format('Y-m-d');
        $end = $range['end']->format('Y-m-d');
        $precision = $range['precision'];

        $locCheck = $this->validateOptionalLocationForUser(
            isset($args['location_id']) ? (int) $args['location_id'] : null,
            $user
        );
        if ($locCheck['ok'] === false) {
            return $locCheck;
        }
        $location_id = $locCheck['location_id'];

        $maxRows = (int) config('aibusinessmanager.tool_accounting_cf_account_limit', 40);
        $limit = isset($args['limit']) ? (int) $args['limit'] : min(15, $maxRows);
        $limit = max(1, min($maxRows, $limit));

        /** @var FinancialStatementsService $fs */
        $fs = app(FinancialStatementsService::class);
        $rows = $fs->cashFlowDirectRows(
            $businessId,
            $start,
            $end,
            $location_id !== null && $location_id > 0 ? $location_id : null
        );

        $netTotal = 0.0;
        $outRows = [];
        foreach ($rows as $r) {
            $net = (float) ($r->net_cash ?? 0);
            $netTotal += $net;
            $outRows[] = [
                'account_id' => (int) ($r->id ?? 0),
                'gl_code' => (string) ($r->gl_code ?? ''),
                'name' => (string) ($r->name ?? ''),
                'net_cash' => round($net, $precision),
            ];
        }

        usort($outRows, fn ($a, $b) => abs($b['net_cash']) <=> abs($a['net_cash']));
        $outRows = array_slice($outRows, 0, $limit);

        return [
            'ok' => true,
            'source' => 'accounting_module',
            'start_date' => $start,
            'end_date' => $end,
            'location_id' => $location_id,
            'net_cash_all_flagged_accounts' => round($netTotal, $precision),
            'accounts_by_abs_net' => $outRows,
            'caveat' => 'Direct cash-flow style: net debits minus credits on accounts flagged is_cash_account in Accounting. Use Accounting Cash Flow report for full classification and PDF.',
        ];
    }
}
