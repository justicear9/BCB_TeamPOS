<?php

namespace Modules\AIBusinessManager\Services\Concerns;

use App\Business;
use App\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

trait ContactAndCatalogTools
{
    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected function contactSearch(array $args, int $businessId, User $user): array
    {
        $q = trim((string) ($args['query'] ?? ''));
        if ($q === '' || mb_strlen($q) < 2) {
            return ['ok' => false, 'error' => 'query_too_short'];
        }

        $type = strtolower((string) ($args['contact_type'] ?? 'any'));
        if (! in_array($type, ['any', 'customer', 'supplier', 'both'], true)) {
            $type = 'any';
        }

        $max = (int) config('aibusinessmanager.tool_contact_search_limit', 25);
        $limit = isset($args['limit']) ? (int) $args['limit'] : $max;
        $limit = max(1, min($max, $limit));

        $like = '%'.addcslashes($q, '%_\\').'%';

        $b = Business::with('currency')->find($businessId);
        if (! $b) {
            return ['ok' => false, 'error' => 'business_not_found'];
        }

        $query = DB::table('contacts')
            ->where('business_id', $businessId)
            ->whereNull('deleted_at')
            ->where(function ($w) use ($like) {
                $w->where('name', 'like', $like)
                    ->orWhere('supplier_business_name', 'like', $like)
                    ->orWhere('email', 'like', $like)
                    ->orWhere('mobile', 'like', $like)
                    ->orWhere('contact_id', 'like', $like);
            });

        if ($type === 'customer') {
            $query->whereIn('type', ['customer', 'both']);
        } elseif ($type === 'supplier') {
            $query->whereIn('type', ['supplier', 'both']);
        }

        $redact = (bool) config('aibusinessmanager.redact_contact_pii', false);

        $rows = $query->orderBy('name')
            ->limit($limit)
            ->select('id', 'name', 'supplier_business_name', 'type', 'mobile', 'email', 'contact_id')
            ->get();

        return [
            'ok' => true,
            'rows' => $rows->map(function ($r) use ($redact) {
                $display = (string) ($r->name ?? '');
                if ($display === '' && ! empty($r->supplier_business_name)) {
                    $display = (string) $r->supplier_business_name;
                }

                return [
                    'contact_id' => (int) $r->id,
                    'display_name' => $display,
                    'type' => (string) $r->type,
                    'contact_ref' => (string) ($r->contact_id ?? ''),
                    'mobile' => $redact ? null : ($r->mobile !== null && $r->mobile !== '' ? (string) $r->mobile : null),
                    'email' => $redact ? null : ($r->email !== null && $r->email !== '' ? (string) $r->email : null),
                ];
            })->values()->all(),
            'caveat' => 'POS contact list search — balances use contact_outstanding or ageing tools. May differ from Accounting module AR/AP.',
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected function contactOutstanding(array $args, int $businessId, User $user): array
    {
        $contact_id = isset($args['contact_id']) ? (int) $args['contact_id'] : 0;
        if ($contact_id <= 0) {
            return ['ok' => false, 'error' => 'missing_contact_id'];
        }

        $c = DB::table('contacts')
            ->where('business_id', $businessId)
            ->whereNull('deleted_at')
            ->where('id', $contact_id)
            ->select('id', 'name', 'supplier_business_name', 'type')
            ->first();

        if (! $c) {
            return ['ok' => false, 'error' => 'contact_not_found'];
        }

        $b = Business::with('currency')->find($businessId);
        $precision = (int) ($b->currency_precision ?? 2);
        $symbol = $b && $b->currency ? $b->currency->symbol : '';

        $display = (string) ($c->name ?? '');
        if ($display === '' && ! empty($c->supplier_business_name)) {
            $display = (string) $c->supplier_business_name;
        }

        $row = DB::table('contacts')
            ->leftJoin('transactions AS t', 'contacts.id', '=', 't.contact_id')
            ->where('contacts.id', $contact_id)
            ->where('contacts.business_id', $businessId)
            ->select(
                DB::raw("SUM(IF(t.type = 'purchase', final_total, 0)) as total_purchase"),
                DB::raw("SUM(IF(t.type = 'sell' AND t.status = 'final', final_total, 0)) as total_invoice"),
                DB::raw("SUM(IF(t.type = 'purchase', (SELECT SUM(amount) FROM transaction_payments WHERE transaction_payments.transaction_id=t.id), 0)) as purchase_paid"),
                DB::raw("SUM(IF(t.type = 'sell' AND t.status = 'final', (SELECT SUM(IF(is_return = 1,-1*amount,amount)) FROM transaction_payments WHERE transaction_payments.transaction_id=t.id), 0)) as invoice_received"),
                DB::raw("SUM(IF(t.type = 'opening_balance', final_total, 0)) as opening_balance"),
                DB::raw("SUM(IF(t.type = 'opening_balance', (SELECT SUM(IF(is_return = 1,-1*amount,amount)) FROM transaction_payments WHERE transaction_payments.transaction_id=t.id), 0)) as opening_balance_paid"),
                DB::raw("SUM(IF(t.type = 'sell_return', final_total, 0)) as total_sell_return"),
                DB::raw("SUM(IF(t.type = 'sell_return', (SELECT SUM(amount) FROM transaction_payments WHERE transaction_payments.transaction_id=t.id), 0)) as sell_return_paid"),
                DB::raw("SUM(IF(t.type = 'ledger_discount', final_total, 0)) as total_ledger_discount"),
                DB::raw("SUM(IF(t.type = 'purchase_return', final_total, 0)) as total_purchase_return"),
                DB::raw("SUM(IF(t.type = 'purchase_return', (SELECT SUM(amount) FROM transaction_payments WHERE transaction_payments.transaction_id=t.id), 0)) as purchase_return_paid")
            )
            ->first();

        $total_invoice = (float) ($row->total_invoice ?? 0);
        $invoice_received = (float) ($row->invoice_received ?? 0);
        $total_ledger_discount = (float) ($row->total_ledger_discount ?? 0);
        $total_sell_return = (float) ($row->total_sell_return ?? 0);
        $sell_return_paid = (float) ($row->sell_return_paid ?? 0);
        $sell_due = $total_invoice - $invoice_received - $total_ledger_discount - $total_sell_return + $sell_return_paid;

        $total_purchase = (float) ($row->total_purchase ?? 0);
        $purchase_paid = (float) ($row->purchase_paid ?? 0);
        $total_purchase_return = (float) ($row->total_purchase_return ?? 0);
        $purchase_return_paid = (float) ($row->purchase_return_paid ?? 0);
        $purchase_due = $total_purchase - $purchase_paid - $total_purchase_return + $purchase_return_paid;

        $opening_balance = (float) ($row->opening_balance ?? 0);
        $opening_balance_paid = (float) ($row->opening_balance_paid ?? 0);
        $opening_due = $opening_balance - $opening_balance_paid;

        return [
            'ok' => true,
            'contact_id' => $contact_id,
            'display_name' => $display,
            'contact_type' => (string) $c->type,
            'currency_symbol' => $symbol,
            'sell_invoice_total' => round($total_invoice, $precision),
            'sell_payments_applied' => round($invoice_received, $precision),
            'ledger_discount_total' => round($total_ledger_discount, $precision),
            'sell_return_total' => round($total_sell_return, $precision),
            'sell_return_paid' => round($sell_return_paid, $precision),
            'sell_net_due' => round($sell_due, $precision),
            'purchase_total' => round($total_purchase, $precision),
            'purchase_paid' => round($purchase_paid, $precision),
            'purchase_return_total' => round($total_purchase_return, $precision),
            'purchase_return_paid' => round($purchase_return_paid, $precision),
            'purchase_net_due' => round($purchase_due, $precision),
            'opening_balance' => round($opening_balance, $precision),
            'opening_balance_paid' => round($opening_balance_paid, $precision),
            'opening_net_due' => round($opening_due, $precision),
            'caveat' => 'TeamPOS-style contact totals (same building blocks as the contact list). Not identical to Accounting module statutory AR/AP when that module maps journals differently.',
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected function receivablesAgeing(array $args, int $businessId, User $user): array
    {
        return $this->invoiceAgeing($args, $businessId, $user, 'sell', 'final', ['customer', 'both']);
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected function payablesAgeing(array $args, int $businessId, User $user): array
    {
        return $this->invoiceAgeing($args, $businessId, $user, 'purchase', 'received', ['supplier', 'both']);
    }

    /**
     * @param  array<int, string>  $contact_types
     * @return array<string, mixed>
     */
    protected function invoiceAgeing(array $args, int $businessId, User $user, string $txn_type, string $status, array $contact_types): array
    {
        $b = Business::with('currency')->find($businessId);
        if (! $b) {
            return ['ok' => false, 'error' => 'business_not_found'];
        }
        $precision = (int) ($b->currency_precision ?? 2);
        $symbol = $b->currency ? $b->currency->symbol : '';

        $permitted = $user->permitted_locations();
        $location_ids = null;
        if ($permitted !== 'all') {
            $location_ids = is_array($permitted) ? $permitted : [];
        }
        if (isset($args['location_id']) && is_numeric($args['location_id'])) {
            $lid = (int) $args['location_id'];
            if ($permitted === 'all' || (is_array($permitted) && in_array($lid, $permitted, true))) {
                $location_ids = [$lid];
            }
        }

        $maxAge = (int) config('aibusinessmanager.tool_ageing_detail_limit', 60);
        $limit = isset($args['limit']) ? (int) $args['limit'] : min(40, $maxAge);
        $limit = max(1, min($maxAge, $limit));

        $paid_sql = '(SELECT COALESCE(SUM(IF(tp.is_return = 1, -tp.amount, tp.amount)), 0) FROM transaction_payments tp WHERE tp.transaction_id = t.id)';
        $balance_sql = '(t.final_total - COALESCE('.$paid_sql.', 0))';

        $q = DB::table('transactions as t')
            ->join('contacts as c', 'c.id', '=', 't.contact_id')
            ->whereNull('c.deleted_at')
            ->where('t.business_id', $businessId)
            ->where('t.type', $txn_type)
            ->where('t.status', $status)
            ->whereIn('c.type', $contact_types)
            ->when($location_ids !== null, fn ($qq) => $qq->whereIn('t.location_id', $location_ids))
            ->whereRaw($balance_sql.' > 0.009');

        $agg = (clone $q)
            ->selectRaw('COUNT(*) as open_count')
            ->selectRaw('SUM(CASE WHEN DATEDIFF(CURDATE(), DATE(t.transaction_date)) <= 30 THEN '.$balance_sql.' ELSE 0 END) as b0_30')
            ->selectRaw('SUM(CASE WHEN DATEDIFF(CURDATE(), DATE(t.transaction_date)) BETWEEN 31 AND 60 THEN '.$balance_sql.' ELSE 0 END) as b31_60')
            ->selectRaw('SUM(CASE WHEN DATEDIFF(CURDATE(), DATE(t.transaction_date)) BETWEEN 61 AND 90 THEN '.$balance_sql.' ELSE 0 END) as b61_90')
            ->selectRaw('SUM(CASE WHEN DATEDIFF(CURDATE(), DATE(t.transaction_date)) >= 91 THEN '.$balance_sql.' ELSE 0 END) as b91_plus')
            ->first();

        $rows = (clone $q)
            ->orderByDesc(DB::raw($balance_sql))
            ->limit($limit)
            ->selectRaw('t.id as transaction_id')
            ->selectRaw('t.invoice_no')
            ->selectRaw('t.ref_no')
            ->selectRaw('DATE(t.transaction_date) as invoice_date')
            ->selectRaw('c.id as contact_id')
            ->selectRaw('COALESCE(NULLIF(TRIM(c.name), ""), NULLIF(TRIM(c.supplier_business_name), ""), CONCAT("Contact #", c.id)) as contact_name')
            ->selectRaw('t.final_total as invoice_total')
            ->selectRaw($paid_sql.' as amount_paid')
            ->selectRaw($balance_sql.' as balance_due')
            ->selectRaw(
                'DATEDIFF(CURDATE(), DATE(t.transaction_date)) as days_since_invoice'
            )
            ->get();

        $basis = $txn_type === 'sell' ? 'receivables_pos' : 'payables_pos';

        return [
            'ok' => true,
            'basis' => $basis,
            'currency_symbol' => $symbol,
            'bucket_totals' => [
                'current_0_30' => round((float) ($agg->b0_30 ?? 0), $precision),
                'days_31_60' => round((float) ($agg->b31_60 ?? 0), $precision),
                'days_61_90' => round((float) ($agg->b61_90 ?? 0), $precision),
                'days_91_plus' => round((float) ($agg->b91_plus ?? 0), $precision),
            ],
            'open_invoice_count' => (int) ($agg->open_count ?? 0),
            'sample_rows' => $rows->map(fn ($r) => [
                'transaction_id' => (int) $r->transaction_id,
                'invoice_no' => $r->invoice_no !== null ? (string) $r->invoice_no : null,
                'ref_no' => $r->ref_no !== null ? (string) $r->ref_no : null,
                'invoice_date' => (string) $r->invoice_date,
                'contact_id' => (int) $r->contact_id,
                'contact_name' => (string) $r->contact_name,
                'balance_due' => round((float) $r->balance_due, $precision),
                'days_since_invoice' => (int) $r->days_since_invoice,
            ])->values()->all(),
            'caveat' => 'Aged from invoice transaction_date in whole days (CURDATE). Pay-term due-date shifts are not applied in this bucket — use Accounting AR/AP ageing for statutory due-date rules when that module is in use.',
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected function productSearch(array $args, int $businessId, User $user): array
    {
        $q = trim((string) ($args['query'] ?? ''));
        if ($q === '' || mb_strlen($q) < 2) {
            return ['ok' => false, 'error' => 'query_too_short'];
        }

        $maxPs = (int) config('aibusinessmanager.tool_product_search_limit', 40);
        $limit = isset($args['limit']) ? (int) $args['limit'] : $maxPs;
        $limit = max(1, min($maxPs, $limit));

        $like = '%'.addcslashes($q, '%_\\').'%';

        $permitted = $user->permitted_locations();
        $location_ids = null;
        if ($permitted !== 'all') {
            $location_ids = is_array($permitted) ? $permitted : [];
        }
        if (isset($args['location_id']) && is_numeric($args['location_id'])) {
            $lid = (int) $args['location_id'];
            if ($permitted === 'all' || (is_array($permitted) && in_array($lid, $permitted, true))) {
                $location_ids = [$lid];
            }
        }

        $rows = DB::table('products as p')
            ->join('variations as v', 'v.product_id', '=', 'p.id')
            ->where('p.business_id', $businessId)
            ->whereNull('v.deleted_at')
            ->where(function ($w) use ($like) {
                $w->where('p.name', 'like', $like)
                    ->orWhere('p.sku', 'like', $like)
                    ->orWhere('v.name', 'like', $like)
                    ->orWhere('v.sub_sku', 'like', $like);
            })
            ->orderBy('p.name')
            ->orderBy('v.name')
            ->limit($limit)
            ->selectRaw('p.id as product_id')
            ->selectRaw('p.name as product_name')
            ->selectRaw('p.type as product_type')
            ->selectRaw('p.is_inactive')
            ->selectRaw('v.id as variation_id')
            ->selectRaw('v.name as variation_name')
            ->selectRaw('v.sub_sku')
            ->selectRaw('p.sku as product_sku')
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $qty = null;
            if ($location_ids !== null && Schema::hasTable('variation_location_details')) {
                $sum = DB::table('variation_location_details')
                    ->where('variation_id', $r->variation_id)
                    ->when($location_ids !== null, fn ($qq) => $qq->whereIn('location_id', $location_ids))
                    ->sum('qty_available');
                $qty = $this->roundQuantity((float) $sum);
            }

            $out[] = [
                'product_id' => (int) $r->product_id,
                'product_name' => (string) $r->product_name,
                'product_type' => (string) $r->product_type,
                'is_inactive' => (bool) $r->is_inactive,
                'variation_id' => (int) $r->variation_id,
                'variation_name' => (string) $r->variation_name,
                'sub_sku' => $r->sub_sku !== null ? (string) $r->sub_sku : '',
                'product_sku' => (string) $r->product_sku,
                'qty_available_selected_locations' => $qty,
            ];
        }

        return [
            'ok' => true,
            'rows' => $out,
        ];
    }
}
