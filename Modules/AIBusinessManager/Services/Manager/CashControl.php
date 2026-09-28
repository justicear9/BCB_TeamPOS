<?php

namespace Modules\AIBusinessManager\Services\Manager;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CashControl
{
    public function __construct(private ManagerScope $scope)
    {
    }

    /**
     * @return array<string, string>
     */
    private function methodLabels(): array
    {
        $labels = ['cash' => 'Cash', 'card' => 'Card', 'cheque' => 'Cheque', 'bank_transfer' => 'Bank transfer', 'other' => 'Other', 'advance' => 'Advance'];
        $custom = json_decode((string) DB::table('business')->where('id', $this->scope->businessId)->value('custom_labels'), true);
        foreach (($custom['payments'] ?? []) as $key => $label) {
            $labels[$key] = $label ?: $key;
        }

        return $labels;
    }

    /**
     * @return array<int, string>
     */
    private function userNames(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if ($ids === []) {
            return [];
        }

        return DB::table('users')
            ->whereIn('id', $ids)
            ->selectRaw('id, TRIM(CONCAT(COALESCE(surname, ""), " ", COALESCE(first_name, ""), " ", COALESCE(last_name, ""))) as name, username')
            ->get()
            ->mapWithKeys(fn ($u) => [(int) $u->id => trim((string) $u->name) !== '' ? trim((string) $u->name) : (string) $u->username])
            ->all();
    }

    private function between(Carbon $start, Carbon $end): array
    {
        return [$start->copy()->startOfDay()->toDateTimeString(), $end->copy()->endOfDay()->toDateTimeString()];
    }

    /**
     * Discounts, below-list prices, deleted and edited sales and returns per cashier, flagged against peers.
     *
     * @return array<string, mixed>
     */
    public function cashierExceptions(Carbon $start, Carbon $end): array
    {
        $scope = $this->scope;
        $range = $this->between($start, $end);
        $loc = fn ($q, string $col) => $scope->locationIds !== null ? $q->whereIn($col, $scope->locationIds ?: [0]) : $q;

        $sales = $loc(DB::table('transactions as t'), 't.location_id')
            ->where('t.business_id', $scope->businessId)
            ->where('t.type', 'sell')
            ->where('t.status', 'final')
            ->whereBetween('t.transaction_date', $range)
            ->groupBy('t.created_by')
            ->selectRaw('t.created_by, COUNT(*) as invoices, SUM(t.final_total) as revenue')
            ->selectRaw('SUM(CASE WHEN t.discount_type = "percentage" THEN t.total_before_tax * t.discount_amount / 100 ELSE COALESCE(t.discount_amount, 0) END) as invoice_discount')
            ->get()
            ->keyBy('created_by');

        $sellCol = Schema::hasColumn('variations', 'sell_price_inc_tax') ? 'v.sell_price_inc_tax' : 'v.default_sell_price';
        $below = $loc(DB::table('transaction_sell_lines as tsl'), 't.location_id')
            ->join('transactions as t', 't.id', '=', 'tsl.transaction_id')
            ->join('variations as v', 'v.id', '=', 'tsl.variation_id')
            ->where('t.business_id', $scope->businessId)
            ->where('t.type', 'sell')
            ->where('t.status', 'final')
            ->whereNull('tsl.parent_sell_line_id')
            ->whereBetween('t.transaction_date', $range)
            ->whereRaw('tsl.unit_price_inc_tax < '.$sellCol.' - 0.009')
            ->where($sellCol, '>', 0)
            ->groupBy('t.created_by')
            ->selectRaw('t.created_by, COUNT(*) as line_count, SUM(('.$sellCol.' - tsl.unit_price_inc_tax) * (tsl.quantity - COALESCE(tsl.quantity_returned, 0))) as given_away')
            ->get()
            ->keyBy('created_by');

        $returns = $loc(DB::table('transactions as t'), 't.location_id')
            ->where('t.business_id', $scope->businessId)
            ->where('t.type', 'sell_return')
            ->whereBetween('t.transaction_date', $range)
            ->groupBy('t.created_by')
            ->selectRaw('t.created_by, COUNT(*) as n, SUM(t.final_total) as amount')
            ->get()
            ->keyBy('created_by');

        $deleted = collect();
        $edited = collect();
        if ($scope->locationIds === null) {
            $deleted = DB::table('activity_log')
                ->where('business_id', $scope->businessId)
                ->where('description', 'sell_deleted')
                ->whereBetween('created_at', $range)
                ->select(['causer_id', 'properties'])
                ->get()
                ->groupBy('causer_id')
                ->map(fn ($rows) => [
                    'n' => $rows->count(),
                    'amount' => $rows->sum(fn ($r) => (float) (json_decode((string) $r->properties, true)['attributes']['final_total'] ?? 0)),
                ]);
            $edited = DB::table('activity_log')
                ->where('business_id', $scope->businessId)
                ->whereIn('description', ['edited', 'payment_edited'])
                ->where('subject_type', 'App\\Transaction')
                ->whereBetween('created_at', $range)
                ->groupBy('causer_id', 'description')
                ->selectRaw('causer_id, description, COUNT(*) as n')
                ->get()
                ->groupBy('causer_id');
        }

        $userIds = array_merge($sales->keys()->all(), $below->keys()->all(), $returns->keys()->all(), $deleted->keys()->all(), $edited->keys()->all());
        $names = $this->userNames($userIds);
        $rows = [];
        foreach (array_unique(array_map('intval', $userIds)) as $uid) {
            if ($uid === 0) {
                continue;
            }
            $s = $sales->get($uid);
            $invoices = (int) ($s->invoices ?? 0);
            $revenue = (float) ($s->revenue ?? 0);
            $giveaway = (float) ($below->get($uid)->given_away ?? 0) + (float) ($s->invoice_discount ?? 0);
            $del = $deleted->get($uid, ['n' => 0, 'amount' => 0.0]);
            $edits = $edited->get($uid, collect());
            $rows[] = [
                'user_id' => $uid,
                'cashier' => $names[$uid] ?? ('User #'.$uid),
                'invoices' => $invoices,
                'revenue' => $scope->money($revenue),
                'discounts_and_price_cuts' => $scope->money($giveaway),
                'price_cut_lines' => (int) ($below->get($uid)->line_count ?? 0),
                'giveaway_pct_of_sales' => $revenue > 0 ? round($giveaway / ($revenue + $giveaway) * 100, 2) : null,
                'returns' => (int) ($returns->get($uid)->n ?? 0),
                'returns_value' => $scope->money((float) ($returns->get($uid)->amount ?? 0)),
                'deleted_sales' => (int) $del['n'],
                'deleted_value' => $scope->money((float) $del['amount']),
                'deleted_per_100_invoices' => $invoices > 0 ? round($del['n'] / $invoices * 100, 1) : null,
                'sale_edits' => (int) ($edits->firstWhere('description', 'edited')->n ?? 0),
                'payment_edits' => (int) ($edits->firstWhere('description', 'payment_edited')->n ?? 0),
            ];
        }

        $median = fn (string $field) => Stats::quantile(array_values(array_filter(array_column(array_filter($rows, fn ($r) => $r['invoices'] >= 30), $field), fn ($v) => $v !== null)), 0.5);
        $medDeleted = $median('deleted_per_100_invoices');
        $medGive = $median('giveaway_pct_of_sales');
        foreach ($rows as &$row) {
            $flags = [];
            if ($row['invoices'] >= 30 && $row['deleted_per_100_invoices'] !== null && $row['deleted_per_100_invoices'] > max(2.0, 2 * $medDeleted)) {
                $flags[] = 'deletes '.$row['deleted_per_100_invoices'].' per 100 invoices (team median '.round($medDeleted, 1).')';
            } elseif ($row['deleted_sales'] >= 10) {
                $flags[] = 'deleted '.$row['deleted_sales'].' sales worth '.Md::money($row['deleted_value'], $scope);
            }
            if ($row['invoices'] >= 30 && $row['giveaway_pct_of_sales'] !== null && $row['giveaway_pct_of_sales'] > max(1.0, 2 * $medGive)) {
                $flags[] = 'discounts/price cuts '.$row['giveaway_pct_of_sales'].'% of sales (team median '.round($medGive, 2).'%)';
            }
            if ($row['payment_edits'] >= 10 && $row['payment_edits'] > $row['invoices'] * 0.05) {
                $flags[] = $row['payment_edits'].' payment edits';
            }
            $row['flags'] = $flags;
        }
        unset($row);
        usort($rows, fn ($a, $b) => [count($b['flags']), $b['deleted_value'] + $b['discounts_and_price_cuts']] <=> [count($a['flags']), $a['deleted_value'] + $a['discounts_and_price_cuts']]);

        $block = Md::table(
            ['Cashier', 'Invoices', 'Sales', 'Discounts & price cuts', 'Deleted sales', 'Returns', 'Edits (sale / payment)', 'Flags'],
            array_map(fn ($r) => [$r['cashier'], $r['invoices'], Md::money($r['revenue'], $scope), Md::money($r['discounts_and_price_cuts'], $scope).' ('.$r['price_cut_lines'].' lines)', $r['deleted_sales'].' / '.Md::money($r['deleted_value'], $scope), $r['returns'].' / '.Md::money($r['returns_value'], $scope), $r['sale_edits'].' / '.$r['payment_edits'], implode('; ', $r['flags']) ?: '—'], $rows),
            'Cashier exceptions, '.$start->format('j M').'–'.$end->format('j M Y')
        );

        return [
            'ok' => true,
            'range' => ['start' => $start->toDateString(), 'end' => $end->toDateString()],
            'cashiers' => $rows,
            'note' => 'Price cuts = line price below the product list price. Deleted sales and edits come from the TeamPOS activity log (by who did it; shown only to users with all locations). Flags compare each cashier with the team median. Flags are prompts to check, not proof of wrongdoing.',
            'verbatim_block' => $block,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function registerVariances(Carbon $start, Carbon $end): array
    {
        $scope = $this->scope;
        $range = $this->between($start, $end);
        $registers = DB::table('cash_registers as cr')
            ->leftJoin('cash_register_transactions as crt', 'crt.cash_register_id', '=', 'cr.id')
            ->where('cr.business_id', $scope->businessId)
            ->when($scope->locationIds !== null, fn ($q) => $q->whereIn('cr.location_id', $scope->locationIds ?: [0]))
            ->where(fn ($q) => $q->whereBetween('cr.closed_at', $range)->orWhere(fn ($o) => $o->where('cr.status', 'open')->where('cr.created_at', '<=', $range[1])))
            ->groupBy('cr.id', 'cr.location_id', 'cr.user_id', 'cr.status', 'cr.created_at', 'cr.closed_at', 'cr.closing_amount', 'cr.closing_note')
            ->select(['cr.id', 'cr.location_id', 'cr.user_id', 'cr.status', 'cr.created_at', 'cr.closed_at', 'cr.closing_amount', 'cr.closing_note'])
            ->selectRaw('SUM(CASE WHEN crt.pay_method = "cash" AND crt.type = "credit" THEN crt.amount WHEN crt.pay_method = "cash" AND crt.type = "debit" THEN -crt.amount ELSE 0 END) as expected_cash')
            ->selectRaw('SUM(CASE WHEN crt.pay_method <> "cash" AND crt.type = "credit" THEN crt.amount WHEN crt.pay_method <> "cash" AND crt.type = "debit" THEN -crt.amount ELSE 0 END) as non_cash')
            ->orderBy('cr.created_at')
            ->get();
        $names = $this->userNames($registers->pluck('user_id')->all());
        $now = $scope->now();

        $sessions = [];
        $byUser = [];
        $open = [];
        foreach ($registers as $r) {
            $opened = Carbon::parse($r->created_at, $scope->timezone);
            if ($r->status === 'open') {
                $hours = $opened->diffInHours($now);
                if ($hours >= 20) {
                    $open[] = [
                        'register_id' => (int) $r->id,
                        'location' => $scope->locationNames[(int) $r->location_id] ?? ('#'.$r->location_id),
                        'cashier' => $names[(int) $r->user_id] ?? ('User #'.$r->user_id),
                        'opened' => $opened->toDateTimeString(),
                        'hours_open' => $hours,
                        'expected_cash' => $scope->money((float) $r->expected_cash),
                    ];
                }

                continue;
            }
            $diff = (float) $r->closing_amount - (float) $r->expected_cash;
            $closed = Carbon::parse($r->closed_at, $scope->timezone);
            $row = [
                'register_id' => (int) $r->id,
                'location' => $scope->locationNames[(int) $r->location_id] ?? ('#'.$r->location_id),
                'cashier' => $names[(int) $r->user_id] ?? ('User #'.$r->user_id),
                'opened' => $opened->toDateTimeString(),
                'closed' => $closed->toDateTimeString(),
                'hours' => round($opened->diffInMinutes($closed) / 60, 1),
                'expected_cash' => $scope->money((float) $r->expected_cash),
                'counted_cash' => $scope->money((float) $r->closing_amount),
                'difference' => $scope->money($diff),
                'non_cash' => $scope->money((float) $r->non_cash),
                'note' => $r->closing_note ? mb_substr((string) $r->closing_note, 0, 120) : null,
            ];
            $u = &$byUser[$row['cashier']];
            $u ??= ['cashier' => $row['cashier'], 'sessions' => 0, 'short' => 0.0, 'over' => 0.0, 'short_sessions' => 0];
            $u['sessions']++;
            if ($diff < -0.009) {
                $u['short'] += $diff;
                $u['short_sessions']++;
            } elseif ($diff > 0.009) {
                $u['over'] += $diff;
            }
            unset($u);
            if (abs($diff) >= 0.01 || $row['hours'] >= 20) {
                $sessions[] = $row;
            }
        }
        usort($sessions, fn ($a, $b) => $a['difference'] <=> $b['difference']);
        $closedCount = $registers->where('status', 'close')->count();

        $blocks = [];
        $blocks[] = Md::table(
            ['Register', 'Shop', 'Cashier', 'Closed', 'Expected cash', 'Counted', 'Difference', 'Hours open'],
            array_map(fn ($r) => ['#'.$r['register_id'], $r['location'], $r['cashier'], $r['closed'], Md::money($r['expected_cash'], $scope), Md::money($r['counted_cash'], $scope), Md::money($r['difference'], $scope), $r['hours']], array_slice($sessions, 0, 25)),
            'Register sessions with a cash difference or open 20+ hours, '.$start->format('j M').'–'.$end->format('j M Y')
        );
        if ($open !== []) {
            $blocks[] = Md::table(['Register', 'Shop', 'Cashier', 'Opened', 'Hours open', 'Cash expected'], array_map(fn ($r) => ['#'.$r['register_id'], $r['location'], $r['cashier'], $r['opened'], $r['hours_open'], Md::money($r['expected_cash'], $scope)], $open), 'Registers still open');
        }

        return [
            'ok' => true,
            'range' => ['start' => $start->toDateString(), 'end' => $end->toDateString()],
            'closed_sessions' => $closedCount,
            'sessions_with_difference' => count(array_filter($sessions, fn ($s) => abs($s['difference']) >= 0.01)),
            'total_short' => $scope->money(array_sum(array_column($byUser, 'short'))),
            'total_over' => $scope->money(array_sum(array_column($byUser, 'over'))),
            'by_cashier' => array_values($byUser),
            'sessions' => array_slice($sessions, 0, 40),
            'still_open' => $open,
            'note' => 'Expected cash = opening float + cash sales − cash refunds recorded on the register. Difference = counted closing cash − expected (negative = short). TeamPOS pre-fills the closing amount, so a zero difference can also mean nobody counted.',
            'verbatim_block' => implode("\n\n", $blocks),
        ];
    }

    /**
     * Payments received per day, shop and method, for matching against MoMo / bank statements.
     *
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public function paymentReconciliation(Carbon $start, Carbon $end, array $args = []): array
    {
        $scope = $this->scope;
        $labels = $this->methodLabels();
        $method = isset($args['method']) ? trim((string) $args['method']) : '';
        $methodKey = null;
        if ($method !== '') {
            foreach ($labels as $key => $label) {
                if (strcasecmp($key, $method) === 0 || stripos($label, $method) !== false) {
                    $methodKey = $key;
                    break;
                }
            }
        }

        $rows = DB::table('transaction_payments as tp')
            ->join('transactions as t', 't.id', '=', 'tp.transaction_id')
            ->where('t.business_id', $scope->businessId)
            ->whereIn('t.type', ['sell', 'sell_return'])
            ->when($scope->locationIds !== null, fn ($q) => $q->whereIn('t.location_id', $scope->locationIds ?: [0]))
            ->when($methodKey !== null, fn ($q) => $q->where('tp.method', $methodKey))
            ->whereBetween('tp.paid_on', $this->between($start, $end))
            ->groupBy(DB::raw('DATE(tp.paid_on)'), 't.location_id', 'tp.method')
            ->selectRaw('DATE(tp.paid_on) as day, t.location_id, tp.method')
            ->selectRaw('SUM(CASE WHEN tp.is_return = 1 OR t.type = "sell_return" THEN -tp.amount ELSE tp.amount END) as amount, COUNT(*) as n')
            ->orderBy('day')
            ->get();

        $due = DB::table('transactions as t')
            ->where('t.business_id', $scope->businessId)
            ->where('t.type', 'sell')
            ->where('t.status', 'final')
            ->whereIn('t.payment_status', ['due', 'partial'])
            ->when($scope->locationIds !== null, fn ($q) => $q->whereIn('t.location_id', $scope->locationIds ?: [0]))
            ->whereBetween('t.transaction_date', $this->between($start, $end))
            ->leftJoin(DB::raw('(SELECT transaction_id, SUM(IF(is_return = 1, -amount, amount)) as paid FROM transaction_payments GROUP BY transaction_id) as p'), 'p.transaction_id', '=', 't.id')
            ->selectRaw('COUNT(*) as n, SUM(t.final_total - COALESCE(p.paid, 0)) as outstanding')
            ->first();

        $grid = [];
        $totals = [];
        foreach ($rows as $r) {
            $label = $labels[$r->method] ?? $r->method;
            $key = $r->day.'|'.$r->location_id;
            $grid[$key]['day'] = $r->day;
            $grid[$key]['location'] = $scope->locationNames[(int) $r->location_id] ?? ('#'.$r->location_id);
            $grid[$key]['methods'][$label] = ($grid[$key]['methods'][$label] ?? 0.0) + (float) $r->amount;
            $grid[$key]['counts'][$label] = ($grid[$key]['counts'][$label] ?? 0) + (int) $r->n;
            $totals[$label] = ($totals[$label] ?? 0.0) + (float) $r->amount;
        }
        $methodCols = array_keys($totals);
        $table = [];
        foreach ($grid as $g) {
            $cells = [Carbon::parse($g['day'])->format('D j M'), $g['location']];
            foreach ($methodCols as $m) {
                $cells[] = isset($g['methods'][$m]) ? Md::money($g['methods'][$m], $scope).' ('.$g['counts'][$m].')' : '—';
            }
            $cells[] = Md::money(array_sum($g['methods']), $scope);
            $table[] = $cells;
        }
        $totalCells = ['**Total**', ''];
        foreach ($methodCols as $m) {
            $totalCells[] = '**'.Md::money($totals[$m], $scope).'**';
        }
        $totalCells[] = '**'.Md::money(array_sum($totals), $scope).'**';
        $table[] = $totalCells;

        return [
            'ok' => true,
            'range' => ['start' => $start->toDateString(), 'end' => $end->toDateString()],
            'method_filter' => $methodKey !== null ? ($labels[$methodKey] ?? $methodKey) : null,
            'totals_by_method' => array_map(fn ($v) => $scope->money($v), $totals),
            'days' => array_values(array_map(fn ($g) => ['day' => $g['day'], 'location' => $g['location'], 'methods' => array_map(fn ($v) => $scope->money($v), $g['methods'])], $grid)),
            'unpaid_invoices' => (int) ($due->n ?? 0),
            'unpaid_amount' => $scope->money((float) ($due->outstanding ?? 0)),
            'note' => 'Payments by the date they were received (paid_on), refunds subtracted, count of payments in brackets. Match the MoMo column against the MTN statement day by day. Unpaid = invoices in the range still due or part-paid.',
            'verbatim_block' => Md::table(array_merge(['Day', 'Shop'], $methodCols, ['Total']), $table, 'Payments received, '.$start->format('j M').'–'.$end->format('j M Y')),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function staffProductivity(Carbon $start, Carbon $end): array
    {
        $scope = $this->scope;
        $range = $this->between($start, $end);
        $sales = DB::table('transactions as t')
            ->where('t.business_id', $scope->businessId)
            ->where('t.type', 'sell')
            ->where('t.status', 'final')
            ->when($scope->locationIds !== null, fn ($q) => $q->whereIn('t.location_id', $scope->locationIds ?: [0]))
            ->whereBetween('t.transaction_date', $range)
            ->groupBy('t.created_by')
            ->selectRaw('t.created_by, COUNT(*) as invoices, SUM(t.final_total) as revenue, COUNT(DISTINCT DATE(t.transaction_date)) as days, GROUP_CONCAT(DISTINCT t.location_id) as locations')
            ->get()
            ->keyBy('created_by');

        $sessions = DB::table('cash_registers')
            ->where('business_id', $scope->businessId)
            ->where('status', 'close')
            ->when($scope->locationIds !== null, fn ($q) => $q->whereIn('location_id', $scope->locationIds ?: [0]))
            ->whereBetween('created_at', $range)
            ->select(['user_id', 'created_at', 'closed_at'])
            ->get()
            ->groupBy('user_id')
            ->map(fn ($rows) => $rows->sum(fn ($r) => min(16.0, max(0.0, Carbon::parse($r->created_at)->diffInMinutes(Carbon::parse($r->closed_at)) / 60))));

        $attendance = collect();
        if (Schema::hasTable('essentials_attendances')) {
            $attendance = DB::table('essentials_attendances')
                ->where('business_id', $scope->businessId)
                ->whereBetween('clock_in_time', $range)
                ->whereNotNull('clock_out_time')
                ->selectRaw('user_id, SUM(TIMESTAMPDIFF(MINUTE, clock_in_time, clock_out_time)) / 60 as hours')
                ->groupBy('user_id')
                ->pluck('hours', 'user_id');
        }

        $names = $this->userNames($sales->keys()->all());
        $rows = [];
        foreach ($sales as $uid => $s) {
            $registerHours = (float) ($sessions->get($uid) ?? 0);
            $clockHours = (float) ($attendance->get($uid) ?? 0);
            $hours = $clockHours > 0 ? $clockHours : $registerHours;
            $rows[] = [
                'staff' => $names[(int) $uid] ?? ('User #'.$uid),
                'shops' => implode(', ', array_map(fn ($id) => $scope->locationNames[(int) $id] ?? ('#'.$id), explode(',', (string) $s->locations))),
                'days_selling' => (int) $s->days,
                'invoices' => (int) $s->invoices,
                'revenue' => $scope->money((float) $s->revenue),
                'avg_invoice' => $scope->money((float) $s->revenue / max(1, (int) $s->invoices)),
                'hours' => round($hours, 1),
                'hours_source' => $clockHours > 0 ? 'clock-in' : ($registerHours > 0 ? 'register open time' : null),
                'revenue_per_hour' => $hours >= 1 ? $scope->money((float) $s->revenue / $hours) : null,
                'revenue_per_day' => $scope->money((float) $s->revenue / max(1, (int) $s->days)),
            ];
        }
        usort($rows, fn ($a, $b) => $b['revenue'] <=> $a['revenue']);

        return [
            'ok' => true,
            'range' => ['start' => $start->toDateString(), 'end' => $end->toDateString()],
            'staff' => $rows,
            'note' => 'Hours use clock-in records when present, otherwise register open time (capped at 16h a session). At shops that key the day\'s sales at night, register time is not shift time, so compare revenue per selling day instead.',
            'verbatim_block' => Md::table(
                ['Staff', 'Shops', 'Days', 'Invoices', 'Sales', 'Avg invoice', 'Sales per day', 'Hours', 'Sales per hour'],
                array_map(fn ($r) => [$r['staff'], $r['shops'], $r['days_selling'], $r['invoices'], Md::money($r['revenue'], $scope), Md::money($r['avg_invoice'], $scope), Md::money($r['revenue_per_day'], $scope), $r['hours'] ?: '—', Md::money($r['revenue_per_hour'], $scope)], $rows),
                'Staff sales, '.$start->format('j M').'–'.$end->format('j M Y')
            ),
        ];
    }
}
