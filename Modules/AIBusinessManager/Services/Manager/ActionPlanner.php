<?php

namespace Modules\AIBusinessManager\Services\Manager;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ActionPlanner
{
    public function __construct(private ManagerScope $scope)
    {
    }

    /**
     * Ingredients to buy for the next N days of forecast baking.
     *
     * @return array<string, mixed>
     */
    public function purchaseSuggestion(int $days, float $safetyDays): array
    {
        $scope = $this->scope;
        $days = max(1, min(14, $days));
        $costing = new RecipeCosting($scope->businessId);
        $recipes = $costing->recipes();
        if ($recipes === []) {
            return ['ok' => false, 'error' => 'no_recipes'];
        }
        $forecaster = new DemandForecaster($scope, new ManagerStore($scope->businessId), $costing);

        $need = [];
        $bakeTotals = [];
        $tomorrow = $scope->today()->addDay();
        for ($i = 0; $i < $days; $i++) {
            $forecast = $forecaster->forecastDay($tomorrow->copy()->addDays($i));
            $plan = $forecaster->bakeryPlan($forecast, false);
            foreach ($plan['rows'] as $row) {
                $bakeTotals[$row['bakery_id']][$row['product']] = ($bakeTotals[$row['bakery_id']][$row['product']] ?? 0) + $row['bake_qty'];
                foreach ($recipes[$row['product_id']]['ingredients'] as $ing) {
                    $key = $row['bakery_id'].':'.$ing['product_id'];
                    $need[$key] ??= ['bakery_id' => $row['bakery_id'], 'ingredient' => $ing, 'qty' => 0.0];
                    $need[$key]['qty'] += $row['bake_qty'] * $ing['qty_per_unit'];
                }
            }
        }
        if ($need === []) {
            return ['ok' => true, 'rows' => [], 'note' => 'No baking forecast for the period.'];
        }

        $productIds = array_values(array_unique(array_map(fn ($n) => $n['ingredient']['product_id'], $need)));
        $stock = DB::table('variation_location_details')
            ->whereIn('product_id', $productIds)
            ->groupBy('location_id', 'product_id')
            ->selectRaw('location_id, product_id, SUM(qty_available) as qty')
            ->get()
            ->mapWithKeys(fn ($r) => [$r->location_id.':'.$r->product_id => (float) $r->qty]);

        $since = $scope->today()->subDays(28);
        $usage = DB::table('transaction_sell_lines as tsl')
            ->join('transactions as t', 't.id', '=', 'tsl.transaction_id')
            ->where('t.business_id', $scope->businessId)
            ->where('t.type', 'production_sell')
            ->where('t.status', 'final')
            ->whereIn('tsl.product_id', $productIds)
            ->where('t.transaction_date', '>=', $since->toDateTimeString())
            ->groupBy('t.location_id', 'tsl.product_id')
            ->selectRaw('t.location_id, tsl.product_id, SUM(tsl.quantity) / 28 as per_day')
            ->get()
            ->mapWithKeys(fn ($r) => [$r->location_id.':'.$r->product_id => (float) $r->per_day]);

        $lastBuy = DB::table('purchase_lines as pl')
            ->join('transactions as t', 't.id', '=', 'pl.transaction_id')
            ->leftJoin('contacts as c', 'c.id', '=', 't.contact_id')
            ->where('t.business_id', $scope->businessId)
            ->where('t.type', 'purchase')
            ->whereIn('pl.product_id', $productIds)
            ->orderBy('t.transaction_date')
            ->select(['pl.product_id', 't.transaction_date', 'pl.purchase_price_inc_tax', 'c.name', 'c.supplier_business_name'])
            ->get()
            ->keyBy('product_id');

        $rows = [];
        $total = 0.0;
        foreach ($need as $key => $n) {
            $ing = $n['ingredient'];
            $onHand = max(0.0, (float) ($stock[$key] ?? 0.0));
            $safety = $safetyDays * (float) ($usage[$key] ?? ($n['qty'] / $days));
            $order = max(0.0, $n['qty'] + $safety - $onHand);
            $cost = $order * $ing['unit_price'];
            $total += $cost;
            $buy = $lastBuy->get($ing['product_id']);
            $rows[] = [
                'bakery' => $scope->locationNames[$n['bakery_id']] ?? ('#'.$n['bakery_id']),
                'ingredient' => $ing['name'],
                'unit' => $ing['unit'],
                'needed' => round($n['qty'], 2),
                'safety_stock' => round($safety, 2),
                'on_hand' => round($onHand, 2),
                'days_of_cover' => $n['qty'] > 0 ? round($onHand / ($n['qty'] / $days), 1) : null,
                'order_qty' => round($order, 2),
                'unit_price' => $scope->money($ing['unit_price']),
                'est_cost' => $scope->money($cost),
                'last_supplier' => $buy ? trim((string) ($buy->supplier_business_name ?: $buy->name)) ?: null : null,
                'last_bought' => $buy ? substr((string) $buy->transaction_date, 0, 10) : null,
            ];
        }
        usort($rows, fn ($a, $b) => [$b['order_qty'] > 0, $b['est_cost']] <=> [$a['order_qty'] > 0, $a['est_cost']]);

        return [
            'ok' => true,
            'days' => $days,
            'safety_days' => $safetyDays,
            'bake_totals' => array_map(fn ($id, $products) => ['bakery' => $scope->locationNames[$id] ?? ('#'.$id), 'products' => $products], array_keys($bakeTotals), $bakeTotals),
            'rows' => $rows,
            'total_est_cost' => $scope->money($total),
            'note' => 'Needed = forecast bake plan for the next '.$days.' days × recipe quantities. Safety stock = '.$safetyDays.' days of recent average use. Order = needed + safety − on hand. This is a suggestion only; Eli does not create purchase orders.',
            'verbatim_block' => Md::table(
                ['Bakery', 'Ingredient', 'Needed', 'On hand', 'Days of cover', 'Order', 'Est. cost', 'Last supplier'],
                array_map(fn ($r) => [$r['bakery'], $r['ingredient'].' ('.$r['unit'].')', Md::qty($r['needed'], 2), Md::qty($r['on_hand'], 2), $r['days_of_cover'] ?? '—', $r['order_qty'] > 0 ? '**'.Md::qty($r['order_qty'], 2).'**' : '—', Md::money($r['est_cost'], $scope), $r['last_supplier'] ?? '—'], $rows),
                'Ingredients to buy for the next '.$days.' days (total '.Md::money($total, $scope).')'
            ),
        ];
    }

    /**
     * Before/after comparison around a decision date, with other shops as a control.
     *
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public function decisionImpact(array $args): array
    {
        $scope = $this->scope;
        $store = new ManagerStore($scope->businessId);
        $note = null;
        if (! empty($args['note_id'])) {
            $note = $store->note((int) $args['note_id']);
            if (! $note) {
                return ['ok' => false, 'error' => 'note_not_found'];
            }
        }
        $effective = $args['effective_on'] ?? ($note['effective_on'] ?? null) ?? ($note ? substr((string) $note['created_at'], 0, 10) : null);
        if (! $effective) {
            return ['ok' => false, 'error' => 'effective_on_required'];
        }
        $effective = Carbon::parse((string) $effective, $scope->timezone)->startOfDay();
        $locationId = isset($args['location_id']) ? (int) $args['location_id'] : ($note['location_id'] ?? null);
        if (! $locationId && ! empty($args['location_name'])) {
            $resolved = $scope->resolveLocationArgs($args);
            if (isset($resolved['error'])) {
                return $resolved['error'];
            }
            $locationId = $resolved['scope']->locationIds[0] ?? null;
        }
        if ($locationId && ! isset($scope->locationNames[(int) $locationId])) {
            return ['ok' => false, 'error' => 'location_forbidden_or_unknown'];
        }
        $productId = isset($args['product_id']) ? (int) $args['product_id'] : ($note['product_id'] ?? null);
        $nameQuery = trim((string) ($args['name_query'] ?? ''));
        if (! $productId && $nameQuery !== '') {
            $productId = DB::table('products')->where('business_id', $scope->businessId)->where('name', 'like', '%'.$nameQuery.'%')->orderByRaw('LENGTH(name)')->value('id');
        }

        $window = max(7, min(56, (int) ($args['window_days'] ?? 28)));
        $available = $effective->diffInDays($scope->today(), false);
        if ($available < 3) {
            return ['ok' => true, 'too_early' => true, 'note' => 'Only '.max(0, $available).' days since the decision. Check again after at least a week.'];
        }
        $afterDays = (int) min($window, $available);
        $afterDays = $afterDays >= 7 ? $afterDays - $afterDays % 7 : $afterDays;
        $beforeStart = $effective->copy()->subDays($afterDays);
        $afterEnd = $effective->copy()->addDays($afterDays - 1);

        $ledger = (new StockLedger($scope))->build($beforeStart, $afterEnd, $productId ? [(int) $productId] : null, $productId === null);
        $bucket = function (bool $target, bool $after) use ($ledger, $effective, $locationId) {
            $m = ['sold' => 0.0, 'revenue' => 0.0, 'leftover' => 0.0, 'sold_out' => 0, 'rows' => 0];
            foreach ($ledger['days'] as $date => $rows) {
                if (($date >= $effective->toDateString()) !== $after) {
                    continue;
                }
                foreach ($rows as $row) {
                    $isTarget = $locationId ? $row['location_id'] === (int) $locationId : true;
                    if ($isTarget !== $target) {
                        continue;
                    }
                    $m['sold'] += $row['sold'];
                    $m['revenue'] += $row['revenue'];
                    $m['leftover'] += $row['adjusted'] + $row['returned_after_trading'];
                    if ($row['sold'] > 0) {
                        $m['rows']++;
                        $m['sold_out'] += $row['sold_out'] ? 1 : 0;
                    }
                }
            }

            return $m;
        };
        $metrics = function (array $b) use ($afterDays, $scope) {
            return [
                'units_per_day' => round($b['sold'] / $afterDays, 1),
                'revenue_per_day' => $scope->money($b['revenue'] / $afterDays),
                'avg_price' => $b['sold'] > 0 ? $scope->money($b['revenue'] / $b['sold']) : null,
                'leftover_per_day' => round($b['leftover'] / $afterDays, 1),
                'sold_out_rate_pct' => $b['rows'] > 0 ? round($b['sold_out'] / $b['rows'] * 100) : null,
            ];
        };
        $before = $metrics($bucket(true, false));
        $after = $metrics($bucket(true, true));
        $change = fn ($a, $b) => ($a !== null && $b !== null && $a != 0) ? round(($b - $a) / abs($a) * 100, 1) : null;

        $control = null;
        if ($locationId) {
            $cb = $metrics($bucket(false, false));
            $ca = $metrics($bucket(false, true));
            $targetChange = $change($before['revenue_per_day'], $after['revenue_per_day']);
            $controlChange = $change($cb['revenue_per_day'], $ca['revenue_per_day']);
            $control = [
                'other_shops_before' => $cb,
                'other_shops_after' => $ca,
                'other_shops_revenue_change_pct' => $controlChange,
                'relative_effect_pct' => ($targetChange !== null && $controlChange !== null) ? round($targetChange - $controlChange, 1) : null,
            ];
        }

        $rows = [];
        foreach (['units_per_day' => 'Units per day', 'revenue_per_day' => 'Revenue per day', 'avg_price' => 'Average price', 'leftover_per_day' => 'Leftover / written off per day', 'sold_out_rate_pct' => 'Sold-out days %'] as $k => $label) {
            $money = in_array($k, ['revenue_per_day', 'avg_price'], true);
            $rows[] = [$label, $money ? Md::money($before[$k], $scope) : ($before[$k] ?? '—'), $money ? Md::money($after[$k], $scope) : ($after[$k] ?? '—'), Md::pct($change($before[$k], $after[$k]), 1)];
        }

        return [
            'ok' => true,
            'decision' => $note ? ['id' => $note['id'], 'title' => $note['title']] : null,
            'effective_on' => $effective->toDateString(),
            'location' => $locationId ? $scope->locationNames[(int) $locationId] : 'all shops',
            'product' => $productId ? ($ledger['products'][(int) $productId]['name'] ?? null) : 'all baked products',
            'window_days' => $afterDays,
            'before' => $before,
            'after' => $after,
            'control' => $control,
            'note' => 'Same number of days before and after (whole weeks when possible). relative_effect_pct = change at this shop minus change at other shops, which removes business-wide swings. Short windows are noisy.',
            'verbatim_block' => Md::table(['Measure', 'Before', 'After', 'Change'], $rows, 'Impact since '.$effective->format('j M Y').' ('.$afterDays.' days each side)'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function targetProgress(Carbon $month): array
    {
        $scope = $this->scope;
        $store = new ManagerStore($scope->businessId);
        $targets = $store->targets($month);
        $monthStart = $month->copy()->startOfMonth();
        $monthEnd = $month->copy()->endOfMonth();
        $today = $scope->today();
        $toDate = $today->lt($monthEnd) ? $today : $monthEnd;
        $daysLeft = $today->lt($monthEnd) ? $today->diffInDays($monthEnd) + 1 : 0;

        $daily = DB::table('transactions')
            ->where('business_id', $scope->businessId)
            ->where('type', 'sell')
            ->where('status', 'final')
            ->when($scope->locationIds !== null, fn ($q) => $q->whereIn('location_id', $scope->locationIds ?: [0]))
            ->whereBetween('transaction_date', [$monthStart->copy()->subDays(35)->toDateTimeString(), $toDate->copy()->endOfDay()->toDateTimeString()])
            ->groupBy('location_id', DB::raw('DATE(transaction_date)'))
            ->selectRaw('location_id, DATE(transaction_date) as day, SUM(final_total) as revenue, COUNT(*) as invoices')
            ->get();
        $byDay = [];
        foreach ($daily as $d) {
            $byDay[(int) $d->location_id][$d->day] = ['revenue' => (float) $d->revenue, 'invoices' => (int) $d->invoices];
        }

        $project = function (?int $locationId, string $metric) use ($byDay, $monthStart, $toDate, $today, $monthEnd) {
            $locs = $locationId ? [$locationId] : array_keys($byDay);
            $actual = 0.0;
            $projected = 0.0;
            foreach ($locs as $lid) {
                foreach ($byDay[$lid] ?? [] as $day => $v) {
                    if ($day >= $monthStart->toDateString() && $day <= $toDate->toDateString()) {
                        $actual += $v[$metric];
                    }
                }
                $cursor = $today->copy();
                while ($cursor->lte($monthEnd)) {
                    $same = [];
                    for ($w = 1; $w <= 4; $w++) {
                        $same[] = $byDay[$lid][$cursor->copy()->subWeeks($w)->toDateString()][$metric] ?? 0.0;
                    }
                    $projected += Stats::mean($same);
                    $cursor->addDay();
                }
            }

            return [$actual, $actual + $projected];
        };

        $rows = [];
        foreach ($targets as $t) {
            $lid = $t['location_id'] ? (int) $t['location_id'] : null;
            if ($lid && ! isset($scope->locationNames[$lid])) {
                continue;
            }
            $metric = (string) $t['metric'];
            $target = (float) $t['value'];
            if (in_array($metric, ['revenue', 'invoices'], true)) {
                [$actual, $projection] = $project($lid, $metric);
            } elseif ($metric === 'gross_profit') {
                $scoped = $lid ? $scope->narrowTo($lid) : $scope;
                $pnl = (new Economics($scoped))->locationPnl($monthStart, $toDate);
                $actual = array_sum(array_column($pnl['locations'] ?? [], 'gross_profit'));
                $elapsed = $monthStart->diffInDays($toDate) + 1;
                $projection = $actual / max(1, $elapsed) * $monthEnd->day;
            } else {
                $report = (new ProductionReport($lid ? $scope->narrowTo($lid) : $scope))->productionLedger([], $monthStart, $toDate);
                $shops = $report['shops'] ?? [];
                $sold = array_sum(array_column($shops, 'sold'));
                $supply = array_sum(array_map(fn ($s) => $s['opening'] + $s['produced'] + $s['received'], $shops));
                $written = array_sum(array_column($shops, 'written_off'));
                $actual = $metric === 'waste_pct'
                    ? ($supply > 0 ? $written / $supply * 100 : 0.0)
                    : ($supply > 0 ? $sold / $supply * 100 : 0.0);
                $projection = $actual;
            }
            $lowerIsBetter = $metric === 'waste_pct';
            $onTrack = $lowerIsBetter ? $projection <= $target : $projection >= $target;
            $rows[] = [
                'location' => $lid ? $scope->locationNames[$lid] : 'Whole business',
                'metric' => $metric,
                'target' => round($target, 2),
                'actual_to_date' => round($actual, 2),
                'projected_month_end' => round($projection, 2),
                'progress_pct' => $target != 0 ? round($actual / $target * 100, 1) : null,
                'on_track' => $onTrack,
                'needed_per_day' => (! $lowerIsBetter && $daysLeft > 0 && in_array($metric, ['revenue', 'invoices', 'gross_profit'], true)) ? round(max(0.0, $target - $actual) / $daysLeft, 2) : null,
            ];
        }

        $fmt = fn ($metric, $v) => in_array($metric, ['revenue', 'gross_profit'], true) ? Md::money($v, $scope) : (str_ends_with($metric, '_pct') ? Md::pct($v, 1) : Md::qty($v));

        return [
            'ok' => true,
            'month' => $monthStart->format('Y-m'),
            'days_left' => $daysLeft,
            'targets_set' => count($targets),
            'rows' => $rows,
            'note' => $targets === []
                ? 'No targets are set for this month. The owner can set them in Eli settings (revenue, gross profit, invoices, waste %, sell-through %) per shop or for the whole business.'
                : 'Projection = actual so far + for each remaining day the average of the last four same weekdays (revenue, invoices); gross profit projects the daily run rate; waste and sell-through show month-to-date.',
            'verbatim_block' => $rows === [] ? '' : Md::table(
                ['Shop', 'Target', 'Goal', 'So far', 'Projected', 'Needed per day', 'On track'],
                array_map(fn ($r) => [$r['location'], str_replace('_', ' ', $r['metric']), $fmt($r['metric'], $r['target']), $fmt($r['metric'], $r['actual_to_date']), $fmt($r['metric'], $r['projected_month_end']), $r['needed_per_day'] !== null ? $fmt($r['metric'], $r['needed_per_day']) : '—', $r['on_track'] ? 'yes' : '**no**'], $rows),
                'Targets for '.$monthStart->format('F Y')
            ),
        ];
    }

    /**
     * Named customer accounts: trend, lapse, payment speed and credit exposure.
     *
     * @return array<string, mixed>
     */
    public function customerAccountHealth(): array
    {
        $scope = $this->scope;
        $today = $scope->today();
        $recentStart = $today->copy()->subDays(90);
        $priorStart = $today->copy()->subDays(180);

        $rows = DB::table('transactions as t')
            ->join('contacts as c', 'c.id', '=', 't.contact_id')
            ->where('t.business_id', $scope->businessId)
            ->where('t.type', 'sell')
            ->where('t.status', 'final')
            ->where('c.is_default', 0)
            ->when($scope->locationIds !== null, fn ($q) => $q->whereIn('t.location_id', $scope->locationIds ?: [0]))
            ->where('t.transaction_date', '>=', $priorStart->toDateTimeString())
            ->leftJoin(DB::raw('(SELECT transaction_id, SUM(IF(is_return = 1, -amount, amount)) as paid, MAX(paid_on) as last_paid FROM transaction_payments GROUP BY transaction_id) as p'), 'p.transaction_id', '=', 't.id')
            ->select(['c.id', 'c.name', 'c.supplier_business_name', 't.transaction_date', 't.final_total', 't.payment_status', 'p.paid', 'p.last_paid'])
            ->orderBy('t.transaction_date')
            ->get()
            ->groupBy('id');

        $out = [];
        foreach ($rows as $contactId => $invoices) {
            $name = trim((string) ($invoices[0]->supplier_business_name ?: $invoices[0]->name));
            $recent = $invoices->filter(fn ($i) => $i->transaction_date >= $recentStart->toDateTimeString());
            $prior = $invoices->filter(fn ($i) => $i->transaction_date < $recentStart->toDateTimeString());
            $recentRevenue = (float) $recent->sum('final_total');
            $priorRevenue = (float) $prior->sum('final_total');
            $dates = $invoices->map(fn ($i) => substr((string) $i->transaction_date, 0, 10))->unique()->values();
            $gaps = [];
            for ($i = 1; $i < $dates->count(); $i++) {
                $gaps[] = Carbon::parse($dates[$i - 1])->diffInDays(Carbon::parse($dates[$i]));
            }
            $usualGap = $gaps !== [] ? Stats::quantile($gaps, 0.5) : null;
            $lastOrder = Carbon::parse($dates->last(), $scope->timezone);
            $daysSince = $lastOrder->diffInDays($today);
            $payDays = [];
            $outstanding = 0.0;
            $oldestUnpaid = null;
            foreach ($invoices as $inv) {
                $due = (float) $inv->final_total - (float) ($inv->paid ?? 0);
                if ($due > 0.009) {
                    $outstanding += $due;
                    $oldestUnpaid ??= substr((string) $inv->transaction_date, 0, 10);
                } elseif ($inv->last_paid) {
                    $payDays[] = max(0, Carbon::parse($inv->transaction_date)->startOfDay()->diffInDays(Carbon::parse($inv->last_paid)->startOfDay(), false));
                }
            }
            $avgPay = $payDays !== [] ? Stats::mean($payDays) : null;
            $monthly = ($recentRevenue + $priorRevenue) / 6;
            $flags = [];
            if ($priorRevenue > 0 && $recentRevenue < $priorRevenue * 0.7) {
                $flags[] = 'buying '.round((1 - $recentRevenue / $priorRevenue) * 100).'% less than the previous 90 days';
            }
            if ($usualGap !== null && $daysSince > max(14, 2.5 * $usualGap)) {
                $flags[] = 'no order for '.$daysSince.' days (usually every '.round($usualGap).')';
            }
            if ($avgPay !== null && $avgPay > 14) {
                $flags[] = 'pays after '.round($avgPay).' days on average';
            }
            if ($oldestUnpaid !== null && Carbon::parse($oldestUnpaid)->diffInDays($today) > 30) {
                $flags[] = 'unpaid invoice from '.$oldestUnpaid;
            }
            if ($outstanding > 0 && $monthly > 0 && $outstanding > 2 * $monthly) {
                $flags[] = 'owes more than two months of purchases';
            }
            $out[] = [
                'customer' => $name !== '' ? $name : ('Contact #'.$contactId),
                'revenue_last_90' => $scope->money($recentRevenue),
                'revenue_prior_90' => $scope->money($priorRevenue),
                'change_pct' => $priorRevenue > 0 ? round(($recentRevenue - $priorRevenue) / $priorRevenue * 100, 1) : null,
                'orders_last_90' => $recent->count(),
                'last_order' => $lastOrder->toDateString(),
                'days_since_last_order' => $daysSince,
                'usual_days_between_orders' => $usualGap !== null ? round($usualGap, 1) : null,
                'avg_days_to_pay' => $avgPay !== null ? round($avgPay, 1) : null,
                'outstanding' => $scope->money($outstanding),
                'flags' => $flags,
            ];
        }
        usort($out, fn ($a, $b) => [count($b['flags']), $b['revenue_last_90'] + $b['revenue_prior_90']] <=> [count($a['flags']), $a['revenue_last_90'] + $a['revenue_prior_90']]);

        return [
            'ok' => true,
            'customers' => array_slice($out, 0, 30),
            'note' => 'Named customers only (walk-in excluded), last 180 days. Flags: falling orders, lapsed buying, slow payment, old unpaid invoices, high balance.',
            'verbatim_block' => Md::table(
                ['Customer', 'Last 90 days', 'Previous 90', 'Change', 'Last order', 'Avg days to pay', 'Owes', 'Flags'],
                array_map(fn ($r) => [$r['customer'], Md::money($r['revenue_last_90'], $scope), Md::money($r['revenue_prior_90'], $scope), Md::pct($r['change_pct'], 1), $r['last_order'], $r['avg_days_to_pay'] ?? '—', Md::money($r['outstanding'], $scope), implode('; ', $r['flags']) ?: '—'], array_slice($out, 0, 20)),
                'Customer accounts'
            ),
        ];
    }
}
