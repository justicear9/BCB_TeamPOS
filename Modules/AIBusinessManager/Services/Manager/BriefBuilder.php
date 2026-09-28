<?php

namespace Modules\AIBusinessManager\Services\Manager;

use App\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Morning brief (yesterday's results, today's plan, watch-outs) and intraday alerts.
 */
class BriefBuilder
{
    public function __construct(private ManagerScope $scope)
    {
    }

    public static function heading(Carbon $day): string
    {
        return '**Morning brief — '.$day->format('l j F').'**';
    }

    /**
     * Post today's brief into the user's Eli chat once per day.
     */
    public static function deliverTo(int $businessId, User $user): void
    {
        if (! config('aibusinessmanager.daily_brief_enabled', true) || ! Schema::hasTable('ai_business_manager_messages')) {
            return;
        }
        try {
            $scope = ManagerScope::forUser($user, $businessId);
            if (! $scope) {
                return;
            }
            $today = $scope->today();
            $marker = self::heading($today);
            $delivered = DB::table('ai_business_manager_messages')
                ->where('business_id', $businessId)
                ->where('user_id', $user->id)
                ->where('role', 'assistant')
                ->where('created_at', '>=', now()->subHours(36))
                ->where('content', 'like', $marker.'%')
                ->exists();
            if ($delivered) {
                return;
            }
            $content = (new self($scope))->briefFor($today, $scope->locationIds === null ? 'daily' : 'user:'.$user->id);
            if ($content === null) {
                return;
            }
            DB::table('ai_business_manager_messages')->insert([
                'business_id' => $businessId,
                'user_id' => $user->id,
                'role' => 'assistant',
                'content' => $content,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('AIBusinessManager: daily brief failed: '.$e->getMessage());
        }
    }

    /**
     * Cached brief for the day (built on first request or by the scheduled command).
     */
    public function briefFor(Carbon $day, string $kind = 'daily', bool $refresh = false): ?string
    {
        $store = new ManagerStore($this->scope->businessId);
        if (! $refresh) {
            $saved = $store->brief($day, $kind);
            if ($saved) {
                return (string) $saved['content'];
            }
        }
        $built = $this->build($day);
        if ($built === null) {
            return null;
        }
        $store->saveBrief($day, $built['content'], $built['payload'], $kind);

        return $built['content'];
    }

    /**
     * @return array{content: string, payload: array<string, mixed>}|null
     */
    public function build(Carbon $day): ?array
    {
        $scope = $this->scope;
        $yesterday = $day->copy()->subDay();
        $parts = [self::heading($day)];
        $payload = [];

        $results = $this->dayResults($yesterday);
        if ($results['rows'] === []) {
            $parts[] = 'No sales were recorded yesterday.';
        } else {
            $total = array_sum(array_column($results['rows'], 'revenue'));
            $expected = array_sum(array_column($results['rows'], 'expected'));
            $change = $expected > 0 ? ($total - $expected) / $expected * 100 : null;
            $parts[] = 'Yesterday ('.$yesterday->format('D j M').') sales were **'.Md::money($total, $scope).'**'
                .($change !== null ? ', '.($change >= 0 ? '+' : '').Md::pct($change).' against a normal '.$yesterday->englishDayOfWeek.' ('.Md::money($expected, $scope).').' : '.');
            $parts[] = Md::table(
                ['Shop', 'Sales', 'Normal '.$yesterday->format('D'), 'Change', 'Bread sold', 'Sold out', 'Written off'],
                array_map(fn ($r) => [$r['location'], Md::money($r['revenue'], $scope), Md::money($r['expected'], $scope), Md::pct($r['change_pct']), Md::qty($r['units']), $r['sold_out'] ?: '—', Md::qty($r['written_off'])], $results['rows'])
            );
            $payload['yesterday'] = $results;
        }

        try {
            $forecaster = new DemandForecaster($scope, new ManagerStore($scope->businessId), new RecipeCosting($scope->businessId));
            $forecast = $forecaster->forecastDay($day);
            $plan = $forecaster->bakeryPlan($forecast, false);
            $byShop = [];
            foreach ($forecast['rows'] as $r) {
                if ($r['closed'] || $r['p50'] < 1) {
                    continue;
                }
                $byShop[$r['location']]['expected'] = ($byShop[$r['location']]['expected'] ?? 0) + $r['p50'];
                $byShop[$r['location']]['send'] = ($byShop[$r['location']]['send'] ?? 0) + $r['recommended_qty'];
                $byShop[$r['location']]['items'][] = $r['product'].' '.$r['recommended_qty'];
            }
            if ($byShop !== []) {
                $parts[] = '**Today\'s plan** (forecast from sales history, sold-out days corrected):';
                $parts[] = Md::table(
                    ['Shop', 'Expected sales (units)', 'Send', 'By product'],
                    array_map(fn ($name, $s) => [$name, Md::qty($s['expected']), $s['send'], implode(', ', array_slice($s['items'], 0, 6))], array_keys($byShop), $byShop)
                );
            }
            $bake = array_values(array_filter($plan['rows'] ?? [], fn ($r) => $r['bake_qty'] > 0));
            if ($bake !== [] && $scope->locationIds === null) {
                $parts[] = Md::table(
                    ['Bakery', 'Product', 'Bake', 'Batches'],
                    array_map(fn ($r) => [$r['bakery'], $r['product'], $r['bake_qty'], $r['batches'] ?? '—'], $bake)
                );
            }
            $notes = array_values(array_unique(array_merge($forecast['notes'], ...array_map(fn ($r) => $r['notes'], array_filter($forecast['rows'], fn ($r) => $r['p50'] >= 5)))));
            if ($notes !== []) {
                $parts[] = implode("\n", array_map(fn ($n) => '- '.$n, array_slice($notes, 0, 5)));
            }
            $payload['forecast_rows'] = count($forecast['rows']);
        } catch (\Throwable $e) {
            Log::warning('AIBusinessManager: brief forecast failed: '.$e->getMessage());
        }

        $watch = $this->watchOuts($day);
        if ($watch !== []) {
            $parts[] = '**Watch-outs**';
            $parts[] = implode("\n", array_map(fn ($w) => '- '.$w, $watch));
        }
        $payload['watch'] = $watch;

        $parts[] = '_Ask me for the full bake plan, the stock ledger, cashier exceptions or profit by shop._';

        return ['content' => implode("\n\n", $parts), 'payload' => $payload];
    }

    /**
     * Raise alerts for today (live-entry shops trading slowly, early sell-outs, registers left open).
     *
     * @return list<array<string, mixed>>
     */
    public function checkAlerts(): array
    {
        $scope = $this->scope;
        $store = new ManagerStore($scope->businessId);
        $now = $scope->now();
        $today = $scope->today();
        $raised = [];

        $ledger = new StockLedger($scope);
        $data = $ledger->build($today->copy()->subDays(35), $today, null, true);
        $live = array_keys(array_filter($data['live_entry']));
        $hourNow = $now->hour + $now->minute / 60;

        foreach ($live as $locationId) {
            $soFar = 0.0;
            $comps = [];
            $cut = $now->format('H:i:s');
            $rows = DB::table('transactions')
                ->where('business_id', $scope->businessId)
                ->where('location_id', $locationId)
                ->where('type', 'sell')
                ->where('status', 'final')
                ->where('transaction_date', '>=', $today->copy()->subWeeks(4)->toDateTimeString())
                ->whereRaw('TIME(transaction_date) <= ?', [$cut])
                ->whereRaw('DAYOFWEEK(transaction_date) = ?', [$today->dayOfWeek + 1])
                ->groupBy(DB::raw('DATE(transaction_date)'))
                ->selectRaw('DATE(transaction_date) as day, SUM(final_total) as revenue')
                ->pluck('revenue', 'day');
            foreach ($rows as $day => $revenue) {
                if ($day === $today->toDateString()) {
                    $soFar = (float) $revenue;
                } else {
                    $comps[] = (float) $revenue;
                }
            }
            $usualClose = $data['usual_close'][$locationId][$today->dayOfWeek] ?? null;
            if (count($comps) >= 2 && $hourNow >= 9 && ($usualClose === null || $hourNow <= $usualClose)) {
                $expected = Stats::mean($comps);
                if ($expected > 50 && $soFar < 0.7 * $expected) {
                    $name = $scope->locationNames[$locationId] ?? ('#'.$locationId);
                    $message = $name.' has sold '.Md::money($soFar, $scope).' by '.$now->format('H:i').', '.round((1 - $soFar / $expected) * 100).'% below a normal '.$today->englishDayOfWeek.' at this time ('.Md::money($expected, $scope).').';
                    if ($store->raiseAlert($today, $locationId, 'slow_trading', 'warn', $message)) {
                        $raised[] = ['code' => 'slow_trading', 'message' => $message];
                    }
                }
            }

            foreach ($data['days'][$today->toDateString()] ?? [] as $row) {
                if ($row['location_id'] !== $locationId || ! $row['sold_out'] || $row['sold'] < 5) {
                    continue;
                }
                if ($usualClose !== null && $row['last_sale_hour'] !== null && $usualClose - $row['last_sale_hour'] >= 1.5 && $hourNow - $row['last_sale_hour'] >= 0.25) {
                    $name = $scope->locationNames[$locationId] ?? ('#'.$locationId);
                    $product = $data['products'][$row['product_id']]['name'] ?? 'a product';
                    $message = $name.' sold out of '.$product.' at '.$row['last_sale_time'].' ('.round($row['sold']).' sold); it usually trades until '.sprintf('%02d:%02d', (int) $usualClose, (int) round(fmod($usualClose, 1) * 60)).'.';
                    if ($store->raiseAlert($today, $locationId, 'sold_out_'.$row['product_id'], 'info', $message)) {
                        $raised[] = ['code' => 'sold_out', 'message' => $message];
                    }
                }
            }
        }

        $stale = DB::table('cash_registers')
            ->where('business_id', $scope->businessId)
            ->where('status', 'open')
            ->where('created_at', '<', $now->copy()->subHours(20)->toDateTimeString())
            ->when($scope->locationIds !== null, fn ($q) => $q->whereIn('location_id', $scope->locationIds ?: [0]))
            ->count();
        if ($stale > 0) {
            $message = $stale.' cash register'.($stale === 1 ? ' has' : 's have').' been open for more than 20 hours.';
            if ($store->raiseAlert($today, null, 'stale_registers', 'warn', $message)) {
                $raised[] = ['code' => 'stale_registers', 'message' => $message];
            }
        }

        return $raised;
    }

    /**
     * @return array{rows: list<array<string, mixed>>}
     */
    private function dayResults(Carbon $day): array
    {
        $scope = $this->scope;
        $sales = DB::table('transactions')
            ->where('business_id', $scope->businessId)
            ->where('type', 'sell')
            ->where('status', 'final')
            ->when($scope->locationIds !== null, fn ($q) => $q->whereIn('location_id', $scope->locationIds ?: [0]))
            ->where('transaction_date', '>=', $day->copy()->subWeeks(4)->toDateTimeString())
            ->where('transaction_date', '<=', $day->copy()->endOfDay()->toDateTimeString())
            ->whereRaw('DAYOFWEEK(transaction_date) = ?', [$day->dayOfWeek + 1])
            ->groupBy('location_id', DB::raw('DATE(transaction_date)'))
            ->selectRaw('location_id, DATE(transaction_date) as d, SUM(final_total) as revenue')
            ->get();
        $actual = [];
        $history = [];
        foreach ($sales as $s) {
            if ($s->d === $day->toDateString()) {
                $actual[(int) $s->location_id] = (float) $s->revenue;
            } else {
                $history[(int) $s->location_id][] = (float) $s->revenue;
            }
        }

        $ledger = (new StockLedger($scope))->build($day, $day, null, true);
        $units = [];
        $soldOut = [];
        $written = [];
        foreach ($ledger['days'][$day->toDateString()] ?? [] as $row) {
            $units[$row['location_id']] = ($units[$row['location_id']] ?? 0) + $row['sold'];
            $written[$row['location_id']] = ($written[$row['location_id']] ?? 0) + $row['adjusted'];
            if ($row['sold_out'] && $row['sold'] >= 3) {
                $soldOut[$row['location_id']][] = ($ledger['products'][$row['product_id']]['name'] ?? '?').($row['sold_out_hours_early'] ? ' at '.$row['last_sale_time'] : '');
            }
        }

        $rows = [];
        foreach (array_unique(array_merge(array_keys($actual), array_keys($history))) as $lid) {
            $revenue = $actual[$lid] ?? 0.0;
            $expected = isset($history[$lid]) ? Stats::mean($history[$lid]) : 0.0;
            if ($revenue <= 0 && $expected <= 0) {
                continue;
            }
            $rows[] = [
                'location' => $scope->locationNames[$lid] ?? ('#'.$lid),
                'revenue' => $scope->money($revenue),
                'expected' => $scope->money($expected),
                'change_pct' => $expected > 0 ? round(($revenue - $expected) / $expected * 100) : null,
                'units' => round($units[$lid] ?? 0),
                'sold_out' => implode(', ', $soldOut[$lid] ?? []),
                'written_off' => round($written[$lid] ?? 0),
            ];
        }
        usort($rows, fn ($a, $b) => $b['revenue'] <=> $a['revenue']);

        return ['rows' => $rows];
    }

    /**
     * @return list<string>
     */
    private function watchOuts(Carbon $day): array
    {
        $scope = $this->scope;
        $out = [];
        $yesterday = $day->copy()->subDay();

        $cash = (new CashControl($scope))->registerVariances($yesterday, $yesterday);
        foreach ($cash['sessions'] ?? [] as $s) {
            if ($s['difference'] <= -1) {
                $out[] = $s['location'].' register #'.$s['register_id'].' ('.$s['cashier'].') closed '.Md::money(abs($s['difference']), $scope).' short.';
            }
        }
        foreach ($cash['still_open'] ?? [] as $s) {
            $out[] = 'Register #'.$s['register_id'].' at '.$s['location'].' has been open '.$s['hours_open'].' hours.';
        }

        $deleted = DB::table('activity_log')
            ->where('business_id', $scope->businessId)
            ->where('description', 'sell_deleted')
            ->whereBetween('created_at', [$yesterday->copy()->startOfDay()->toDateTimeString(), $yesterday->copy()->endOfDay()->toDateTimeString()])
            ->count();
        if ($deleted >= 5 && $scope->locationIds === null) {
            $out[] = $deleted.' sales were deleted yesterday. Ask me for cashier exceptions to see who and how much.';
        }

        $store = new ManagerStore($scope->businessId);
        foreach ($store->notes('task', 'open', 20) as $task) {
            if ($task['due_on'] && $task['due_on'] <= $day->toDateString()) {
                $out[] = 'Task due: '.$task['title'].' (due '.$task['due_on'].').';
            }
        }

        $progress = (new ActionPlanner($scope))->targetProgress($day);
        foreach ($progress['rows'] ?? [] as $r) {
            if (! $r['on_track']) {
                $out[] = $r['location'].' is off track for its '.str_replace('_', ' ', $r['metric']).' target this month (projected '.round($r['projected_month_end']).' against '.round($r['target']).').';
            }
        }

        foreach ($store->alerts($yesterday, true) as $alert) {
            if ($alert['code'] !== 'stale_registers') {
                $out[] = $alert['message'];
            }
        }

        return array_slice($out, 0, 10);
    }
}
