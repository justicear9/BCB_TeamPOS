<?php

namespace Modules\AIBusinessManager\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Detects TeamPOS / Accounting / Account report pages for Eli UI hints and chat context.
 */
class ReportPageContextService
{
    /**
     * @return array{type: string, family: string, slug: string, title: string, path: string}|null
     */
    public function resolve(Request $request): ?array
    {
        $segments = $request->segments();

        if (($segments[0] ?? '') === 'reports') {
            return $this->businessReportsContext($request, $segments);
        }

        if (($segments[0] ?? '') === 'accounting' && ($segments[1] ?? '') === 'reports') {
            return $this->accountingReportsContext($request, $segments);
        }

        if (($segments[0] ?? '') === 'account') {
            return $this->accountModuleReportsContext($request, $segments);
        }

        if (($segments[0] ?? '') === 'inventory-reporting' && ($segments[1] ?? '') === 'reports') {
            return $this->inventoryReportingContext($request, $segments);
        }

        return null;
    }

    /**
     * Resolve report context for POST /chat using Referer or JSON page_context.path (same-origin hints).
     *
     * @return array{type: string, family: string, slug: string, title: string, path: string}|null
     */
    public function resolveFromChatRequest(Request $request): ?array
    {
        // Prefer JSON body from the page that rendered the widget (matches server-side report detection).
        $pc = $request->input('page_context');
        if (is_array($pc) && ($pc['type'] ?? '') === 'report') {
            $path = Str::before(trim((string) ($pc['path'] ?? '')), '?');
            $ctx = $this->resolveFromPath($path);
            if ($ctx !== null) {
                return $ctx;
            }
        }

        $referer = $request->headers->get('Referer');
        if (is_string($referer) && $referer !== '') {
            $path = parse_url($referer, PHP_URL_PATH);
            if (is_string($path) && $path !== '') {
                $ctx = $this->resolveFromPath($path);
                if ($ctx !== null) {
                    return $ctx;
                }
            }
        }

        $sticky = session('aibm_last_report_context');
        if (is_array($sticky) && ($sticky['type'] ?? '') === 'report') {
            return $sticky;
        }

        return null;
    }

    /**
     * @return array{type: string, family: string, slug: string, title: string, path: string}|null
     */
    public function resolveFromPath(string $path): ?array
    {
        $path = trim($path);
        $path = trim($path, '/');
        if ($path === '') {
            return null;
        }

        return $this->resolve(Request::create('/'.$path, 'GET'));
    }

    /**
     * @param  array{type: string, family: string, slug: string, title: string, path: string}  $ctx
     */
    public function formatModelBlock(array $ctx): string
    {
        $title = (string) ($ctx['title'] ?? '');
        $family = (string) ($ctx['family'] ?? '');
        $slug = (string) ($ctx['slug'] ?? '');
        $path = (string) ($ctx['path'] ?? '');

        $lines = [
            '=== CURRENT REPORT PAGE (TeamPOS UI) ===',
            'The merchant has THIS TeamPOS report screen open when they sent this message.',
            'Report title: '.$title,
            'Report family / slug: '.$family.' / '.$slug,
            'URL path: /'.$path,
            '',
            'PRIORITY RULE:',
            '- Questions about margin %, profit %, totals, columns, or “why is this number…” refer to THIS REPORT’S on-screen metrics and methodology unless the user clearly asks for separate company-wide aggregates.',
            '- Do NOT substitute unrelated tool outputs (e.g. rolling sales vs purchase totals, expense totals) as if they were the same as a percentage shown on this report—those are different lenses.',
            '- Eli tools do not replicate every Stock Report cell or formula; say what tools can confirm vs what is defined only in the report UI.',
            '',
            'Do not assume on-screen date filters, locations, or grouping unless the user states them.',
        ];

        if ($slug === 'stock-report') {
            $lines[] = '';
            $lines[] = 'Stock Report note: On-screen margin % is typically gross-margin style on stock/sales-related columns for this report—not overall net profit margin for the whole business. Explain that distinction when relevant.';
        }

        if ($slug === 'customer-supplier') {
            $lines[] = '';
            $lines[] = 'Customer / supplier report: balances and activity are statement-style in the UI. Eli `contact_outstanding` gives TeamPOS-style totals for one contact_id; `receivables_ageing` / `payables_ageing` are POS invoice-date buckets, not Accounting GL ageing.';
        }

        if ($slug === 'sell-payment-report' || $slug === 'purchase-payment-report') {
            $lines[] = '';
            $lines[] = 'Payment report: lists payments applied to invoices in the UI filters. For a single invoice breakdown use `transaction_detail` (payments + lines). `sale_payment_mix` is aggregate mix by method, not this grid.';
        }

        if ($slug === 'register-report') {
            $lines[] = '';
            $lines[] = 'Register report: cash register session totals and denominations as configured in TeamPOS. Eli does not replay Z-report layout; use aggregates or the on-screen PDF/export when the user needs exact register columns.';
        }

        if ($family === 'accounting_reports' && in_array($slug, ['account-receivable-ageing-report', 'account-payable-ageing-report'], true)) {
            $lines[] = '';
            $lines[] = 'Accounting ageing: due-date buckets from pay terms vs today. Prefer `accounting_ar_ageing_summary` / `accounting_ap_ageing_summary` tools for the same definitions—not `receivables_ageing` / `payables_ageing` (invoice-date POS buckets).';
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<int, string>  $segments
     * @return array{type: string, family: string, slug: string, title: string, path: string}
     */
    protected function businessReportsContext(Request $request, array $segments): array
    {
        $slug = (string) ($segments[1] ?? 'reports');
        $path = Str::before($request->path(), '?');

        $lang_key = $this->businessReportSlugToLangKey[$slug] ?? null;
        $title = $lang_key ? __($lang_key) : Str::headline(str_replace('-', ' ', $slug));

        return [
            'type' => 'report',
            'family' => 'business_reports',
            'slug' => $slug,
            'title' => $title,
            'path' => $path,
        ];
    }

    /**
     * @param  array<int, string>  $segments
     * @return array{type: string, family: string, slug: string, title: string, path: string}
     */
    protected function accountingReportsContext(Request $request, array $segments): array
    {
        $path = Str::before($request->path(), '?');
        $sub = (string) ($segments[2] ?? '');
        if ($sub === '') {
            return [
                'type' => 'report',
                'family' => 'accounting_reports',
                'slug' => 'index',
                'title' => __('accounting::lang.reports'),
                'path' => $path,
            ];
        }

        $slug = $sub;
        $lang_key = $this->accountingReportSlugToLangKey[$slug] ?? null;
        $title = $lang_key ? __($lang_key) : Str::headline(str_replace('-', ' ', $slug));

        return [
            'type' => 'report',
            'family' => 'accounting_reports',
            'slug' => $slug,
            'title' => $title,
            'path' => $path,
        ];
    }

    /**
     * @param  array<int, string>  $segments
     * @return array{type: string, family: string, slug: string, title: string, path: string}|null
     */
    protected function accountModuleReportsContext(Request $request, array $segments): ?array
    {
        $slug = (string) ($segments[1] ?? '');
        $report_slugs = ['balance-sheet', 'trial-balance', 'payment-account-report', 'cash-flow'];
        if (! in_array($slug, $report_slugs, true)) {
            return null;
        }

        $path = Str::before($request->path(), '?');
        $lang_key = $this->accountModuleSlugToLangKey[$slug] ?? null;
        $title = $lang_key ? __($lang_key) : Str::headline(str_replace('-', ' ', $slug));

        return [
            'type' => 'report',
            'family' => 'account_reports',
            'slug' => $slug,
            'title' => $title,
            'path' => $path,
        ];
    }

    /**
     * @param  array<int, string>  $segments
     * @return array{type: string, family: string, slug: string, title: string, path: string}
     */
    protected function inventoryReportingContext(Request $request, array $segments): array
    {
        $path = Str::before($request->path(), '?');
        $slug = (string) ($segments[2] ?? 'reports');
        $lang_key = $this->inventoryReportingSlugToLangKey[$slug] ?? null;
        $title = $lang_key ? __($lang_key) : Str::headline(str_replace('-', ' ', $slug));

        return [
            'type' => 'report',
            'family' => 'inventory_reporting',
            'slug' => $slug,
            'title' => $title,
            'path' => $path,
        ];
    }

    /** @var array<string, string> slug => __('report.key') or other lang file */
    protected array $businessReportSlugToLangKey = [
        'profit-loss' => 'report.profit_loss',
        'purchase-sell' => 'report.purchase_sell_report',
        'tax-report' => 'report.tax_report',
        'tax-details' => 'report.tax_report',
        'customer-supplier' => 'report.contacts',
        'customer-group' => 'lang_v1.customer_groups_report',
        'stock-report' => 'report.stock_report',
        'stock-details' => 'report.stock_report',
        'trending-products' => 'report.trending_products',
        'expense-report' => 'report.expense_report',
        'stock-adjustment-report' => 'report.stock_adjustment_report',
        'sc-report' => 'lang_v1.stock_adjustment_report',
        'stock-control-report' => 'lang_v1.stock_control_report',
        'register-report' => 'report.register_report',
        'sales-representative-report' => 'report.sales_representative',
        'sales-representative-total-expense' => 'report.sales_representative',
        'sales-representative-total-sell' => 'report.sales_representative',
        'sales-representative-total-commission' => 'report.sales_representative',
        'stock-expiry' => 'report.stock_expiry_report',
        'stock-transfer-report' => 'lang_v1.stock_transfer_report',
        'stock-as-at-date' => 'report.stock_as_at_date_report',
        'product-purchase-report' => 'lang_v1.product_purchase_report',
        'product-sell-report' => 'lang_v1.product_sell_report',
        'product-sell-report-with-purchase' => 'lang_v1.product_sell_report',
        'product-sell-grouped-by' => 'lang_v1.product_sell_report',
        'product-sell-grouped-report' => 'lang_v1.product_sell_report',
        'lot-report' => 'lang_v1.lot_report',
        'purchase-payment-report' => 'lang_v1.purchase_payment_report',
        'sell-payment-report' => 'lang_v1.sell_payment_report',
        'items-report' => 'lang_v1.items_report',
        'purchase-report' => 'lang_v1.purchase',
        'sale-report' => 'business.sale',
        'gst-purchase-report' => 'lang_v1.gst_purchase_report',
        'gst-sales-report' => 'lang_v1.gst_sales_report',
        'get-opening-stock' => 'report.opening_stock',
        'table-report' => 'restaurant.table_report',
        'service-staff-report' => 'restaurant.service_staff_report',
        'service-staff-line-orders' => 'restaurant.service_staff_report',
        'get-stock-by-sell-price' => 'report.stock_report',
        'product-stock-details' => 'report.stock_report',
        'adjust-product-stock' => 'report.stock_report',
        'get-profit' => 'report.profit_loss',
        'get-stock-value' => 'report.stock_report',
        'activity-log' => 'lang_v1.activity_log',
    ];

    /** @var array<string, string> */
    protected array $accountingReportSlugToLangKey = [
        'balance-sheet' => 'accounting::lang.balance_sheet',
        'profit-loss' => 'accounting::lang.profit_and_loss',
        'trial-balance' => 'accounting::lang.trial_balance',
        'cash-flow' => 'accounting::lang.cash_flow',
        'posted-journal' => 'accounting::lang.posted_journal_report',
        'account-receivable-ageing-report' => 'accounting::lang.account_recievable_ageing_report',
        'account-receivable-ageing-details' => 'accounting::lang.account_receivable_ageing_details',
        'account-payable-ageing-report' => 'accounting::lang.account_payable_ageing_report',
        'account-payable-ageing-details' => 'accounting::lang.account_payable_ageing_details',
    ];

    /** @var array<string, string> */
    protected array $accountModuleSlugToLangKey = [
        'balance-sheet' => 'account.balance_sheet',
        'trial-balance' => 'account.trial_balance',
        'payment-account-report' => 'account.payment_account_report',
        'cash-flow' => 'lang_v1.cash_flow',
    ];

    /** @var array<string, string> */
    protected array $inventoryReportingSlugToLangKey = [
        'ageing' => 'inventoryreporting::lang.report_ageing',
        'stock-as-at' => 'inventoryreporting::lang.report_stock_as_at',
    ];
}
