<?php

namespace Modules\AIBusinessManager\Services;

use App\Business;
use App\Product;
use App\User;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\AIBusinessManager\Support\GhanaPublicHolidays;

class BusinessInsightContextService
{
    /**
     * Build a text block injected into the model system prompt (business-grounded).
     */
    public function buildSnapshot(int $business_id, User $user): string
    {
        $business = Business::with(['currency', 'locations'])->find($business_id);
        if (! $business) {
            return 'Business not found.';
        }

        $currency = $business->currency;
        $symbol = $currency ? $currency->symbol : '';
        $precision = (int) ($business->currency_precision ?? 2);

        $permitted = $user->permitted_locations();
        $location_ids = null;
        if ($permitted !== 'all') {
            $location_ids = is_array($permitted) ? $permitted : [];
        }

        $locations = $business->locations()
            ->when($location_ids !== null, fn ($q) => $q->whereIn('id', $location_ids))
            ->get(['name', 'landmark', 'country', 'state', 'city', 'zip_code', 'mobile', 'email', 'website']);

        $txn_base = $this->sellTransactionsQuery($business_id, $location_ids);

        $now = Carbon::now();
        $last_calendar_year = (int) $now->year - 1;
        $prior_calendar_year = (int) $now->year - 2;

        $lines = [];
        $lines[] = '=== BUSINESS PROFILE ===';
        $lines[] = 'Name: '.$business->name;
        $lines[] = 'Accounting method: '.$business->accounting_method;
        $lines[] = 'Sell price tax: '.$business->sell_price_tax;
        $lines[] = 'Timezone: '.$business->time_zone;
        $lines[] = 'Fiscal year starts in month: '.(int) $business->fy_start_month;
        $lines[] = 'Currency: '.($currency ? $currency->code.' ('.$symbol.')' : 'unknown');
        $lines[] = 'Lot tracking enabled: '.($business->enable_lot_number ? 'yes' : 'no');
        $lines[] = 'Product expiry tracking: '.($business->enable_product_expiry ? 'yes' : 'no');
        $lines[] = __('aibusinessmanager::lang.preamble_eli_identity');

        $this->appendMerchantAiContext($business_id, $lines);

        $lines[] = '';
        $lines[] = '=== LOCATIONS (visible to this user) ===';
        foreach ($locations as $loc) {
            $parts = array_filter([
                $loc->name,
                trim(implode(', ', array_filter([$loc->city, $loc->state, $loc->country]))),
                $loc->mobile ? 'Phone: '.$loc->mobile : null,
                $loc->email ? 'Email: '.$loc->email : null,
                $loc->website ? 'Website: '.$loc->website : null,
            ]);
            $lines[] = '- '.implode(' | ', $parts);
        }

        $this->appendCalendarContext($business, $locations, $lines);

        $bounds = (clone $txn_base)->selectRaw('MIN(transaction_date) as first_dt, MAX(transaction_date) as last_dt')->first();
        $lines[] = '';
        $lines[] = '=== SALES DATA COVERAGE (final sell invoices, user-visible locations) ===';
        if ($bounds && $bounds->first_dt) {
            $lines[] = 'Earliest sale in dataset: '.$bounds->first_dt;
            $lines[] = 'Latest sale in dataset: '.$bounds->last_dt;
        } else {
            $lines[] = 'No final sell transactions found for this business/location scope.';
        }

        $lines[] = '';
        $lines[] = '=== ROLLING SALES AGGREGATES ===';

        foreach ([
            'Last 7 days' => [$now->copy()->subDays(7), $now],
            'Last 30 days' => [$now->copy()->subDays(30), $now],
            'Last 90 days' => [$now->copy()->subDays(90), $now],
        ] as $label => $range) {
            $q = clone $txn_base;
            $sum = (float) $q->whereBetween('transaction_date', [$range[0], $range[1]])
                ->sum('final_total');
            $count = (int) (clone $txn_base)->whereBetween('transaction_date', [$range[0], $range[1]])->count();
            $lines[] = sprintf('%s: %s invoices, total %s %s', $label, $count, $symbol, number_format($sum, $precision));
        }

        $lines[] = '';
        $lines[] = '=== REVENUE BY CALENDAR YEAR (totals) ===';
        $by_year = (clone $txn_base)
            ->selectRaw('YEAR(transaction_date) as yr')
            ->selectRaw('SUM(final_total) as revenue')
            ->selectRaw('COUNT(*) as invoice_count')
            ->groupBy(DB::raw('YEAR(transaction_date)'))
            ->orderBy('yr')
            ->get();
        $by_year_slice = $by_year->count() > 14 ? $by_year->slice(-14)->values() : $by_year;
        foreach ($by_year_slice as $row) {
            $lines[] = sprintf(
                '- %d: %s %s across %d invoices',
                (int) $row->yr,
                $symbol,
                number_format((float) $row->revenue, $precision),
                (int) $row->invoice_count
            );
        }
        if ($by_year->count() > 14) {
            $lines[] = '(Older years omitted; only the most recent 14 calendar years are listed above.)';
        }

        $lines[] = '';
        $lines[] = '=== MONTHLY REVENUE (last 24 calendar months) ===';
        $monthly_24 = (clone $txn_base)
            ->where('transaction_date', '>=', $now->copy()->subMonths(24)->startOfMonth())
            ->selectRaw("DATE_FORMAT(transaction_date, '%Y-%m') as ym")
            ->selectRaw('SUM(final_total) as revenue')
            ->selectRaw('COUNT(*) as invoice_count')
            ->groupBy(DB::raw("DATE_FORMAT(transaction_date, '%Y-%m')"))
            ->orderBy('ym')
            ->get();
        foreach ($monthly_24 as $row) {
            $lines[] = sprintf(
                '- %s: %s %s (%d invoices)',
                $row->ym,
                $symbol,
                number_format((float) $row->revenue, $precision),
                (int) $row->invoice_count
            );
        }

        $lines[] = '';
        $lines[] = '=== LAST COMPLETE CALENDAR YEAR ('.$last_calendar_year.') — DETAIL ===';
        $this->appendYearDetailSection($lines, $business_id, $location_ids, $txn_base, $symbol, $precision, $last_calendar_year);

        $lines[] = '';
        $lines[] = '=== PRIOR CALENDAR YEAR ('.$prior_calendar_year.') — SUMMARY ===';
        $this->appendYearSummaryOnly($lines, $txn_base, $symbol, $precision, $prior_calendar_year);

        $lines[] = '';
        $lines[] = '=== MONTHLY REVENUE (last ~6 months, quick view) ===';
        $monthly = (clone $txn_base)
            ->where('transaction_date', '>=', $now->copy()->subMonths(6))
            ->selectRaw("DATE_FORMAT(transaction_date, '%Y-%m') as ym")
            ->selectRaw('SUM(final_total) as revenue')
            ->selectRaw('COUNT(*) as invoice_count')
            ->groupBy(DB::raw("DATE_FORMAT(transaction_date, '%Y-%m')"))
            ->orderBy('ym')
            ->get();
        foreach ($monthly as $row) {
            $lines[] = sprintf(
                '- %s: %s %s (%d invoices)',
                $row->ym,
                $symbol,
                number_format((float) $row->revenue, $precision),
                (int) $row->invoice_count
            );
        }

        $top_products = DB::table('transaction_sell_lines as tsl')
            ->join('transactions as t', 't.id', '=', 'tsl.transaction_id')
            ->join('products as p', 'p.id', '=', 'tsl.product_id')
            ->where('t.business_id', $business_id)
            ->where('t.type', 'sell')
            ->where('t.status', 'final')
            ->where('t.transaction_date', '>=', $now->copy()->subDays(90))
            ->when($location_ids !== null, fn ($q) => $q->whereIn('t.location_id', $location_ids))
            ->groupBy('p.id', 'p.name')
            ->orderByRaw('SUM(tsl.quantity * COALESCE(tsl.unit_price_inc_tax, tsl.unit_price, 0)) DESC')
            ->limit(15)
            ->selectRaw('p.name as product_name')
            ->selectRaw('SUM(tsl.quantity) as qty_sold')
            ->selectRaw('SUM(tsl.quantity * COALESCE(tsl.unit_price_inc_tax, tsl.unit_price, 0)) as revenue')
            ->get();

        $lines[] = '';
        $lines[] = 'Top selling products by revenue (last 90 days, approx):';
        foreach ($top_products as $tp) {
            $lines[] = sprintf(
                '- %s | qty %s | revenue %s %s',
                $tp->product_name,
                rtrim(rtrim(number_format((float) $tp->qty_sold, 4), '0'), '.'),
                $symbol,
                number_format((float) $tp->revenue, $precision)
            );
        }

        $category_mix = DB::table('products')
            ->leftJoin('categories as c', 'c.id', '=', 'products.category_id')
            ->where('products.business_id', $business_id)
            ->where('products.is_inactive', 0)
            ->groupBy(DB::raw('COALESCE(NULLIF(c.name, ""), "Uncategorized")'))
            ->selectRaw('COALESCE(NULLIF(c.name, ""), "Uncategorized") as category_name')
            ->selectRaw('COUNT(*) as product_count')
            ->orderByDesc('product_count')
            ->limit(30)
            ->get();

        $lines[] = '';
        $lines[] = 'Active products by category (counts):';
        foreach ($category_mix as $cm) {
            $lines[] = sprintf('- %s: %d SKU(s)', $cm->category_name, (int) $cm->product_count);
        }

        $limit = (int) config('aibusinessmanager.product_sample_limit', 200);
        $products = Product::where('business_id', $business_id)
            ->Active()
            ->ProductForSales()
            ->with(['category:id,name'])
            ->select(['id', 'name', 'type', 'category_id'])
            ->orderBy('name')
            ->limit($limit)
            ->get();

        $lines[] = '';
        $lines[] = 'Product catalogue sample (up to '.$limit.' names; infer retail vertical from wording):';
        foreach ($products as $p) {
            $cat = $p->category ? $p->category->name : 'Uncategorized';
            $lines[] = '- '.$p->name.' ['.$cat.'] ('.$p->type.')';
        }

        $lines[] = '';
        $lines[] = '=== INSTRUCTIONS FOR MODEL ===';
        $lines[] = 'The blocks above include multi-year and monthly sales computed from this business’s database (within the user’s permitted locations).';
        $lines[] = 'Use these figures for YoY comparisons, seasonality hints, and rough forecasts. Label uncertainty when extrapolating beyond the data range.';
        $lines[] = 'Infer likely retail vertical from categories/product names only as a hypothesis.';
        $lines[] = 'Do not claim a metric is unavailable if it appears in an earlier section of this snapshot.';
        $lines[] = 'TeamPOS is POS/inventory software; suggest built-in reports only for drill-downs not represented here (e.g. line-level tax, payments mix).';

        return implode("\n", $lines);
    }

    /**
     * Short static context for tool-based chats (identity, locations, sale date range).
     */
    public function buildToolPreamble(int $business_id, User $user, ?string $report_page_context = null): string
    {
        $business = Business::with(['currency', 'locations'])->find($business_id);
        if (! $business) {
            return 'Business not found.';
        }

        $currency = $business->currency;
        $symbol = $currency ? $currency->symbol : '';

        $permitted = $user->permitted_locations();
        $location_ids = null;
        if ($permitted !== 'all') {
            $location_ids = is_array($permitted) ? $permitted : [];
        }

        $locations = $business->locations()
            ->when($location_ids !== null, fn ($q) => $q->whereIn('id', $location_ids))
            ->get(['name', 'landmark', 'country', 'state', 'city', 'zip_code', 'mobile', 'email', 'website']);

        $txn_base = $this->sellTransactionsQuery($business_id, $location_ids);

        $lines = [];
        $lines[] = '=== BUSINESS PROFILE ===';
        $lines[] = 'Name: '.$business->name;
        $lines[] = 'Timezone: '.$business->time_zone;
        $lines[] = 'Fiscal year starts in month: '.(int) $business->fy_start_month;
        $lines[] = 'Currency: '.($currency ? $currency->code.' ('.$symbol.')' : 'unknown');
        $lines[] = __('aibusinessmanager::lang.preamble_eli_identity');

        $this->appendMerchantAiContext($business_id, $lines);

        $lines[] = '';
        $lines[] = '=== LOCATIONS (visible to this user) ===';
        foreach ($locations as $loc) {
            $parts = array_filter([
                $loc->name,
                trim(implode(', ', array_filter([$loc->city, $loc->state, $loc->country]))),
                $loc->mobile ? 'Phone: '.$loc->mobile : null,
                $loc->email ? 'Email: '.$loc->email : null,
                $loc->website ? 'Website: '.$loc->website : null,
            ]);
            $lines[] = '- '.implode(' | ', $parts);
        }

        $this->appendCalendarContext($business, $locations, $lines);

        $bounds = (clone $txn_base)->selectRaw('MIN(transaction_date) as first_dt, MAX(transaction_date) as last_dt')->first();
        $lines[] = '';
        $lines[] = '=== SALES DATA COVERAGE (final sell invoices, user-visible locations) ===';
        if ($bounds && $bounds->first_dt) {
            $lines[] = 'Earliest sale in dataset: '.$bounds->first_dt;
            $lines[] = 'Latest sale in dataset: '.$bounds->last_dt;
        } else {
            $lines[] = 'No final sell transactions found for this business/location scope.';
        }

        $lines[] = '';
        $lines[] = '=== TOOL USAGE ===';
        $lines[] = 'Knowledge base = this business’s live TeamPOS data (read-only). Prefer deriving answers from tools. product_location_metrics: unit_query loaves ≡ Pc/Pcs. Do not invent figures. Prefer merchant-business topics aligned with OWNER-PROVIDED CONTEXT industry when shown; gently redirect unrelated conversation.';

        $out = implode("\n", $lines);
        if (is_string($report_page_context) && trim($report_page_context) !== '') {
            $out .= "\n\n".trim($report_page_context);
        }

        return $out;
    }

    /**
     * Industry and free-form notes saved in Eli assistant settings (per business).
     *
     * @param  array<int, string>  $lines
     */
    protected function appendMerchantAiContext(int $business_id, array &$lines): void
    {
        if (! Schema::hasTable('ai_business_manager_preferences')) {
            return;
        }
        if (! Schema::hasColumn('ai_business_manager_preferences', 'business_industry')) {
            return;
        }

        $row = DB::table('ai_business_manager_preferences')->where('business_id', $business_id)->first();
        if (! $row) {
            return;
        }

        $industry = trim((string) ($row->business_industry ?? ''));
        $notes = trim((string) ($row->context_notes ?? ''));

        if ($industry === '' && $notes === '') {
            return;
        }

        $lines[] = '';
        $lines[] = '=== OWNER-PROVIDED CONTEXT ===';
        if ($industry !== '') {
            $lines[] = 'Industry / vertical (merchant-provided): '.$industry;
        }
        if ($notes !== '') {
            $lines[] = 'Additional instructions / context (merchant-provided):';
            $lines[] = $notes;
        }
    }

    /**
     * Ghana public holidays when the business is in Ghana, plus a warning
     * when a location name looks like a campus outlet whose term dates are not stored.
     *
     * @param  \Illuminate\Support\Collection<int, mixed>  $locations
     * @param  array<int, string>  $lines
     */
    protected function appendCalendarContext(Business $business, $locations, array &$lines): void
    {
        $country = null;
        $campusNames = [];
        foreach ($locations as $loc) {
            $locCountry = trim((string) ($loc->country ?? ''));
            if ($country === null && preg_match('/ghana/i', $locCountry) === 1) {
                $country = $locCountry;
            }
            $name = trim((string) ($loc->name ?? ''));
            if ($name !== '' && preg_match('/upsa|hostel|campus/i', $name) === 1) {
                $campusNames[] = $name;
            }
        }

        if (GhanaPublicHolidays::applies((string) $business->time_zone, $country)) {
            $tz = $business->time_zone ?: 'Africa/Accra';
            $holidays = GhanaPublicHolidays::around(Carbon::now($tz));
            $lines[] = '';
            $lines[] = '=== PUBLIC HOLIDAYS (Ghana) ===';
            if ($holidays === []) {
                $lines[] = 'No listed public holiday in the last 14 days or the next 120 days.';
            }
            foreach ($holidays as $holiday) {
                $lines[] = '- '.$holiday['date'].' '.$holiday['name'].' ('.$holiday['when'].')';
            }
            $lines[] = GhanaPublicHolidays::caveat();
        }

        if ($campusNames !== []) {
            $lines[] = '';
            $lines[] = '=== CAMPUS / HOSTEL OUTLET ===';
            $lines[] = 'Location names that look like a campus or hostel: '.implode(', ', $campusNames).'.';
            $lines[] = 'Academic term dates are not stored in TeamPOS. Do not invent semester, exam, or vacation dates. Say campus sales can fall when students are away, and ask the merchant for those dates.';
        }
    }

    public function sellTransactionsQuery(int $business_id, ?array $location_ids): Builder
    {
        $q = DB::table('transactions')
            ->where('business_id', $business_id)
            ->where('type', 'sell')
            ->where('status', 'final');

        if ($location_ids !== null) {
            $q->whereIn('location_id', $location_ids);
        }

        return $q;
    }

    /**
     * @param  array<int, string>  $lines
     */
    protected function appendYearSummaryOnly(array &$lines, Builder $txn_base, string $symbol, int $precision, int $year): void
    {
        $row = (clone $txn_base)
            ->whereYear('transaction_date', $year)
            ->selectRaw('SUM(final_total) as revenue')
            ->selectRaw('COUNT(*) as invoice_count')
            ->first();

        if (! $row || (float) $row->revenue <= 0) {
            $lines[] = '- No final sell revenue recorded for '.$year.'.';

            return;
        }

        $lines[] = sprintf(
            '- Total %s %s across %d invoices',
            $symbol,
            number_format((float) $row->revenue, $precision),
            (int) $row->invoice_count
        );
    }

    /**
     * @param  array<int, string>  $lines
     */
    protected function appendYearDetailSection(array &$lines, int $business_id, ?array $location_ids, Builder $txn_base, string $symbol, int $precision, int $year): void
    {
        $row = (clone $txn_base)
            ->whereYear('transaction_date', $year)
            ->selectRaw('SUM(final_total) as revenue')
            ->selectRaw('COUNT(*) as invoice_count')
            ->first();

        if (! $row || (float) ($row->revenue ?? 0) <= 0) {
            $lines[] = 'No final sell revenue recorded for '.$year.'.';

            return;
        }

        $lines[] = sprintf(
            'Year total: %s %s across %d invoices',
            $symbol,
            number_format((float) $row->revenue, $precision),
            (int) $row->invoice_count
        );

        $lines[] = 'Monthly breakdown:';
        $months = (clone $txn_base)
            ->whereYear('transaction_date', $year)
            ->selectRaw("DATE_FORMAT(transaction_date, '%Y-%m') as ym")
            ->selectRaw('SUM(final_total) as revenue')
            ->selectRaw('COUNT(*) as invoice_count')
            ->groupBy(DB::raw("DATE_FORMAT(transaction_date, '%Y-%m')"))
            ->orderBy('ym')
            ->get();
        foreach ($months as $m) {
            $lines[] = sprintf(
                '- %s: %s %s (%d invoices)',
                $m->ym,
                $symbol,
                number_format((float) $m->revenue, $precision),
                (int) $m->invoice_count
            );
        }

        $lines[] = 'Top products by revenue in '.$year.' (approx, from sell lines):';
        $top = DB::table('transaction_sell_lines as tsl')
            ->join('transactions as t', 't.id', '=', 'tsl.transaction_id')
            ->join('products as p', 'p.id', '=', 'tsl.product_id')
            ->where('t.business_id', $business_id)
            ->where('t.type', 'sell')
            ->where('t.status', 'final')
            ->whereYear('t.transaction_date', $year)
            ->when($location_ids !== null, fn ($q) => $q->whereIn('t.location_id', $location_ids))
            ->groupBy('p.id', 'p.name')
            ->orderByRaw('SUM(tsl.quantity * COALESCE(tsl.unit_price_inc_tax, tsl.unit_price, 0)) DESC')
            ->limit(15)
            ->selectRaw('p.name as product_name')
            ->selectRaw('SUM(tsl.quantity * COALESCE(tsl.unit_price_inc_tax, tsl.unit_price, 0)) as revenue')
            ->get();

        foreach ($top as $tp) {
            $lines[] = sprintf('- %s — %s %s', $tp->product_name, $symbol, number_format((float) $tp->revenue, $precision));
        }

        $lines[] = 'Top categories by revenue in '.$year.' (approx):';
        $cats = DB::table('transaction_sell_lines as tsl')
            ->join('transactions as t', 't.id', '=', 'tsl.transaction_id')
            ->join('products as p', 'p.id', '=', 'tsl.product_id')
            ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
            ->where('t.business_id', $business_id)
            ->where('t.type', 'sell')
            ->where('t.status', 'final')
            ->whereYear('t.transaction_date', $year)
            ->when($location_ids !== null, fn ($q) => $q->whereIn('t.location_id', $location_ids))
            ->groupBy(DB::raw('COALESCE(NULLIF(c.name, ""), "Uncategorized")'))
            ->orderByRaw('SUM(tsl.quantity * COALESCE(tsl.unit_price_inc_tax, tsl.unit_price, 0)) DESC')
            ->limit(10)
            ->selectRaw('COALESCE(NULLIF(c.name, ""), "Uncategorized") as category_name')
            ->selectRaw('SUM(tsl.quantity * COALESCE(tsl.unit_price_inc_tax, tsl.unit_price, 0)) as revenue')
            ->get();
        foreach ($cats as $c) {
            $lines[] = sprintf('- %s — %s %s', $c->category_name, $symbol, number_format((float) $c->revenue, $precision));
        }

        $lines[] = 'Revenue by location in '.$year.' (invoice totals):';
        $locs = DB::table('transactions as t')
            ->join('business_locations as bl', 'bl.id', '=', 't.location_id')
            ->where('t.business_id', $business_id)
            ->where('t.type', 'sell')
            ->where('t.status', 'final')
            ->whereYear('t.transaction_date', $year)
            ->when($location_ids !== null, fn ($q) => $q->whereIn('t.location_id', $location_ids))
            ->groupBy('bl.id', 'bl.name')
            ->orderByDesc(DB::raw('SUM(t.final_total)'))
            ->selectRaw('bl.name as location_name')
            ->selectRaw('SUM(t.final_total) as revenue')
            ->selectRaw('COUNT(*) as invoice_count')
            ->limit(15)
            ->get();
        foreach ($locs as $loc) {
            $lines[] = sprintf(
                '- %s: %s %s (%d invoices)',
                $loc->location_name,
                $symbol,
                number_format((float) $loc->revenue, $precision),
                (int) $loc->invoice_count
            );
        }
    }
}
