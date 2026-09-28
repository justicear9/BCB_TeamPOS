<?php

namespace Modules\AIBusinessManager\Services\Manager;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\AIBusinessManager\Support\GhanaPublicHolidays;

/**
 * Daily demand forecast per shop and product.
 *
 * 1. Demand history comes from the stock ledger. Days that ran out are corrected: at shops that key sales
 *    live, sales are scaled up by the share of the day that was left; elsewhere the day is treated as capped
 *    and filled with the typical uncapped same-weekday demand (never below what was sold).
 * 2. Six simple models are backtested one day ahead over the last four weeks; the lowest WAPE wins per series.
 * 3. The winner's historic error ratios give the p10 / p50 / p90 range.
 * 4. Holidays and owner calendar events adjust the forecast.
 * 5. The recommended quantity is the newsvendor quantile: (price - cost) / (price - leftover value).
 */
class DemandForecaster
{
    public const MODELS = ['last_week', 'weekday_mean_4', 'weekday_median_6', 'ewma_weekday', 'holt_weekday', 'blend'];

    private int $historyDays;

    private int $backtestDays;

    private float $leftoverValuePct;

    private ?array $productIds;

    private bool $allProducts;

    private Carbon $today;

    /** @var array<string, array<string, mixed>> key => series meta */
    private array $series = [];

    /** @var array<int, array<string, float>> location_id => date => total demand (open days) */
    private array $locationTotals = [];

    /** @var array<int, array<string, bool>> location_id => date => open */
    private array $openDays = [];

    /** @var array<int, array<int, float>> location_id => hour => cumulative share of the day's sales */
    private array $hourlyProfile = [];

    /** @var array<string, mixed> */
    private array $ledger = [];

    private bool $prepared = false;

    /**
     * @param  array<string, mixed>  $options
     */
    public function __construct(
        private ManagerScope $scope,
        private ManagerStore $store,
        private RecipeCosting $costing,
        array $options = [],
    ) {
        $this->historyDays = max(42, min(365, (int) ($options['history_days'] ?? 119)));
        $this->backtestDays = max(7, min(56, (int) ($options['backtest_days'] ?? 28)));
        $this->leftoverValuePct = max(0.0, min(90.0, (float) ($options['leftover_value_pct'] ?? config('aibusinessmanager.forecast_leftover_value_pct', 25))));
        $this->productIds = $options['product_ids'] ?? null;
        $this->allProducts = (bool) ($options['all_products'] ?? false);
        $this->today = $scope->today();
    }

    public function leftoverValuePct(): float
    {
        return $this->leftoverValuePct;
    }

    /**
     * @return array<string, mixed>
     */
    public function ledgerData(): array
    {
        $this->prepare();

        return $this->ledger;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function seriesMeta(): array
    {
        $this->prepare();

        return $this->series;
    }

    public function prepare(): void
    {
        if ($this->prepared) {
            return;
        }
        $this->prepared = true;

        $start = $this->today->copy()->subDays($this->historyDays);
        $end = $this->today->copy()->subDay();
        $ledger = new StockLedger($this->scope);
        $this->ledger = $ledger->build($start, $this->today, $this->productIds, ! $this->allProducts);
        $series = $ledger->series($this->ledger, $start, $end);

        foreach ($series as $rows) {
            foreach ($rows as $date => $row) {
                if ($row['sold'] > 0) {
                    $this->openDays[$row['location_id']][$date] = true;
                }
            }
        }
        $this->hourlyProfile = $this->buildHourlyProfile($start, $end);

        $recentFrom = $this->today->copy()->subDays(28)->toDateString();
        foreach ($series as $key => $rows) {
            $recentSold = 0.0;
            $recentRevenue = 0.0;
            foreach ($rows as $date => $row) {
                if ($date >= $recentFrom) {
                    $recentSold += $row['sold'];
                    $recentRevenue += $row['revenue'];
                }
            }
            if ($recentSold <= 0) {
                continue;
            }
            [$locationId, $productId] = array_map('intval', explode(':', $key));
            $this->series[$key] = $this->demandSeries($locationId, $productId, $rows) + [
                'location_id' => $locationId,
                'product_id' => $productId,
                'recent_sold' => $recentSold,
                'recent_price' => $recentRevenue / $recentSold,
            ];
        }

        foreach ($this->series as $meta) {
            foreach ($meta['history'] as $point) {
                $this->locationTotals[$meta['location_id']][$point['date']] = ($this->locationTotals[$meta['location_id']][$point['date']] ?? 0.0) + $point['demand'];
            }
        }
        foreach ($this->locationTotals as &$totals) {
            ksort($totals);
        }
        unset($totals);
    }

    /**
     * Forecast every active shop/product series for one day.
     *
     * @return array{target_date: string, rows: list<array<string, mixed>>, notes: list<string>}
     */
    public function forecastDay(Carbon $target, bool $save = true): array
    {
        $this->prepare();
        $target = $target->copy()->setTimezone($this->scope->timezone)->startOfDay();
        $events = $this->store->calendarEvents($target, $target);
        $pastEvents = $this->store->calendarEvents($this->today->copy()->subDays($this->historyDays), $this->today);
        $holiday = $this->holidayOn($target);

        $rows = [];
        $locationFactors = [];
        foreach ($this->series as $key => $meta) {
            $locationId = $meta['location_id'];
            if (! isset($locationFactors[$locationId])) {
                $locationFactors[$locationId] = $this->locationFactors($locationId, $target, $holiday, $events, $pastEvents);
            }
            $rows[] = $this->forecastSeries($key, $meta, $target, $locationFactors[$locationId]);
        }

        usort($rows, fn ($a, $b) => [$a['location_id'], -$a['p50']] <=> [$b['location_id'], -$b['p50']]);

        if ($save && $target->gt($this->today)) {
            $this->store->saveForecasts(array_map(fn ($r) => $r + ['target_date' => $target->toDateString()], $rows), $this->today);
        }

        $notes = [];
        if ($holiday !== null) {
            $notes[] = $target->toDateString().' is '.$holiday.'.';
        }
        foreach ($events as $event) {
            $notes[] = 'Calendar: '.$event['name'].' ('.$event['starts_on'].' to '.$event['ends_on'].').';
        }

        return ['target_date' => $target->toDateString(), 'rows' => $rows, 'notes' => $notes];
    }

    /**
     * Turn shop forecasts into production per bakery using the share of each shop's supply that came
     * from each production location over the last 28 days.
     *
     * @param  array{target_date: string, rows: list<array<string, mixed>>}  $forecast
     * @return array<string, mixed>
     */
    public function bakeryPlan(array $forecast, bool $countLeftovers): array
    {
        $since = $this->today->copy()->subDays(28);
        $ledger = new StockLedger($this->scope);
        $bakeries = array_values(array_intersect($ledger->productionLocationIds($since), $this->scope->visibleLocationIds()));
        if ($bakeries === []) {
            return ['bakeries' => [], 'rows' => [], 'note' => 'No production recorded in the last 28 days.'];
        }
        $shares = $this->supplyShares($since, $bakeries);
        $recipes = $this->costing->recipes();
        $current = $countLeftovers ? $this->currentStockByKey() : [];

        $plan = [];
        foreach ($forecast['rows'] as $row) {
            $productId = $row['product_id'];
            if (! isset($recipes[$productId])) {
                continue;
            }
            $locationId = $row['location_id'];
            $need = (float) $row['recommended_qty'];
            $leftover = max(0.0, (float) ($current[$locationId.':'.$productId] ?? 0.0));
            $net = max(0.0, $need - $leftover);
            if (in_array($locationId, $bakeries, true)) {
                $split = [$locationId => 1.0];
            } else {
                $split = $shares[$locationId][$productId] ?? $shares[$locationId]['*'] ?? [$bakeries[0] => 1.0];
            }
            foreach ($split as $bakeryId => $share) {
                $slot = &$plan[$bakeryId.':'.$productId];
                $slot ??= ['bakery_id' => (int) $bakeryId, 'product_id' => $productId, 'need' => 0.0, 'leftover' => 0.0, 'bake' => 0.0, 'destinations' => []];
                $slot['need'] += $need * $share;
                $slot['leftover'] += min($need, $leftover) * $share;
                $slot['bake'] += $net * $share;
                $slot['destinations'][$locationId] = ($slot['destinations'][$locationId] ?? 0.0) + $net * $share;
                unset($slot);
            }
        }

        $rows = [];
        foreach ($plan as $slot) {
            $recipe = $recipes[$slot['product_id']];
            $bake = (int) ceil($slot['bake'] - 0.01);
            $batches = $recipe['yield'] > 0 ? (int) ceil($bake / $recipe['yield']) : null;
            $rows[] = [
                'bakery_id' => $slot['bakery_id'],
                'bakery' => $this->scope->locationNames[$slot['bakery_id']] ?? ('Location #'.$slot['bakery_id']),
                'product_id' => $slot['product_id'],
                'product' => $recipe['name'],
                'shop_need' => (int) ceil($slot['need'] - 0.01),
                'leftover_used' => (int) floor($slot['leftover']),
                'bake_qty' => $bake,
                'batch_size' => $recipe['yield'],
                'batches' => $batches,
                'batch_qty' => $batches !== null ? (int) round($batches * $recipe['yield']) : null,
                'send_to' => collect($slot['destinations'])
                    ->filter(fn ($q) => $q >= 0.5)
                    ->map(fn ($q, $lid) => ($this->scope->locationNames[$lid] ?? ('#'.$lid)).' '.(int) round($q))
                    ->values()
                    ->all(),
                'ingredient_cost' => $this->scope->money($bake * $recipe['ingredient_cost_per_unit']),
            ];
        }
        usort($rows, fn ($a, $b) => [$a['bakery'], -$a['bake_qty']] <=> [$b['bakery'], -$b['bake_qty']]);

        return ['bakeries' => $bakeries, 'rows' => $rows];
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $factors
     * @return array<string, mixed>
     */
    private function forecastSeries(string $key, array $meta, Carbon $target, array $factors): array
    {
        $history = $meta['history'];
        $backtest = $this->backtest($history);
        $model = $backtest['best'];
        $base = $this->predict($history, $target)[$model] ?? 0.0;

        $ratios = $backtest['ratios'];
        if (count($ratios) >= 8) {
            $q10 = Stats::quantile($ratios, 0.1);
            $q50 = max(0.85, min(1.15, Stats::quantile($ratios, 0.5)));
            $q90 = Stats::quantile($ratios, 0.9);
            $q10 = min($q10, $q50);
            $q90 = max($q90, $q50);
        } else {
            [$q10, $q50, $q90] = [0.75, 1.0, 1.3];
        }

        $factor = $factors['closed'] ? 0.0 : $factors['factor'];
        $price = (float) $meta['recent_price'];
        $cost = $this->costing->unitCost($meta['product_id']) ?? $this->fallbackCost($meta['product_id']);
        $salvage = $price * $this->leftoverValuePct / 100;
        $lossMaker = $cost !== null && $price > 0 && $cost >= $price;
        if ($cost === null || $price <= $salvage) {
            $ratio = 0.5;
        } elseif ($lossMaker) {
            $ratio = 0.5;
        } else {
            $ratio = max(0.5, min(0.95, ($price - $cost) / ($price - $salvage)));
        }
        $qService = max($q50, count($ratios) >= 8 ? Stats::quantile($ratios, $ratio) : $this->defaultQuantile($ratio));

        $recent = array_slice($history, -28);
        $soldOutDays = count(array_filter($recent, fn ($p) => $p['sold_out']));
        $cappedDays = count(array_filter($recent, fn ($p) => $p['capped']));

        $notes = $factors['notes'];
        $label = ($this->ledger['products'][$meta['product_id']]['name'] ?? 'This product').' at '.($this->scope->locationNames[$meta['location_id']] ?? 'this shop');
        if ($lossMaker) {
            $notes[] = $label.': cost is at or above price, so baking more loses money.';
        }
        if ($recent !== [] && $cappedDays / count($recent) >= 0.5) {
            $notes[] = $label.' sold out on '.$cappedDays.' of the last '.count($recent).' trading days, so true demand is likely higher than sales show. Try 10% more for a week and watch leftovers.';
        }

        return [
            'location_id' => $meta['location_id'],
            'location' => $this->scope->locationNames[$meta['location_id']] ?? ('Location #'.$meta['location_id']),
            'product_id' => $meta['product_id'],
            'product' => $this->ledger['products'][$meta['product_id']]['name'] ?? ('Product #'.$meta['product_id']),
            'unit' => $this->ledger['products'][$meta['product_id']]['unit'] ?? 'unit',
            'model' => $model,
            'backtest_wape_pct' => Stats::pct($backtest['wape']),
            'backtest_bias_pct' => Stats::pct($backtest['bias']),
            'backtest_days' => $backtest['n'],
            'base' => round($base, 1),
            'factor' => round($factor, 3),
            'p10' => round(max(0.0, $base * $q10 * $factor), 1),
            'p50' => round(max(0.0, $base * $q50 * $factor), 1),
            'p90' => round(max(0.0, $base * $q90 * $factor), 1),
            'recommended_qty' => (int) ceil(max(0.0, $base * $qService * $factor) - 0.01),
            'service_level_pct' => round($ratio * 100),
            'unit_price' => $this->scope->money($price),
            'unit_cost' => $cost !== null ? $this->scope->money($cost) : null,
            'loss_maker' => $lossMaker,
            'sold_out_days_28' => $soldOutDays,
            'capped_days_28' => $cappedDays,
            'trading_days_28' => count($recent),
            'closed' => $factors['closed'],
            'notes' => $notes,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $history
     * @return array{best: string, wape: ?float, bias: ?float, n: int, ratios: list<float>, by_model: array<string, ?float>}
     */
    public function backtest(array $history): array
    {
        $n = count($history);
        $pairs = array_fill_keys(self::MODELS, []);
        $startIndex = max(21, $n - $this->backtestDays);
        for ($i = $startIndex; $i < $n; $i++) {
            $past = array_slice($history, 0, $i);
            $point = $history[$i];
            $preds = $this->predict($past, Carbon::parse($point['date'], $this->scope->timezone));
            foreach ($preds as $model => $value) {
                $pairs[$model][] = [$point['demand'], $value];
            }
        }

        $byModel = [];
        foreach ($pairs as $model => $list) {
            $byModel[$model] = count($list) >= 8 ? Stats::wape($list) : null;
        }
        $candidates = array_filter($byModel, fn ($w) => $w !== null);
        $best = $candidates === [] ? 'blend' : array_keys($candidates, min($candidates), true)[0];
        $chosen = $pairs[$best];
        $ratios = [];
        foreach ($chosen as [$actual, $forecast]) {
            if ($forecast > 0) {
                $ratios[] = $actual / $forecast;
            }
        }

        return [
            'best' => $best,
            'wape' => $byModel[$best] ?? null,
            'bias' => count($chosen) >= 8 ? Stats::bias($chosen) : null,
            'n' => count($chosen),
            'ratios' => $ratios,
            'by_model' => $byModel,
        ];
    }

    /**
     * One forecast per model for $target from open-day history strictly before it.
     *
     * @param  list<array<string, mixed>>  $history
     * @return array<string, float>
     */
    public function predict(array $history, Carbon $target): array
    {
        if ($history === []) {
            return array_fill_keys(self::MODELS, 0.0);
        }
        $weekday = (int) $target->dayOfWeek;
        $sameWeekday = array_values(array_filter($history, fn ($p) => $p['weekday'] === $weekday));
        $sameValues = array_map(fn ($p) => $p['demand'], $sameWeekday);
        $allValues = array_map(fn ($p) => $p['demand'], $history);
        $fallback = Stats::mean(array_slice($allValues, -7));

        $lastWeekDate = $target->copy()->subDays(7)->toDateString();
        $last = end($sameWeekday);
        $lastWeek = ($last && $last['date'] === $lastWeekDate) ? $last['demand'] : ($sameValues !== [] ? Stats::mean(array_slice($sameValues, -2)) : $fallback);
        $mean4 = $sameValues !== [] ? Stats::mean(array_slice($sameValues, -4)) : $fallback;
        $median6 = $sameValues !== [] ? Stats::quantile(array_slice($sameValues, -6), 0.5) : $fallback;

        $windowStart = $target->copy()->subDays(84)->toDateString();
        $window = array_values(array_filter($history, fn ($p) => $p['date'] >= $windowStart));
        if ($window === []) {
            $window = array_slice($history, -14);
        }
        $overall = Stats::mean(array_map(fn ($p) => $p['demand'], $window));
        $index = $this->weekdayIndex($window, $overall);
        $idx = fn (int $wd) => $index[$wd] ?? 1.0;

        $level = null;
        $holtLevel = null;
        $trend = 0.0;
        foreach ($window as $p) {
            $x = $p['demand'] / max(0.2, $idx($p['weekday']));
            $level = $level === null ? $x : 0.15 * $x + 0.85 * $level;
            if ($holtLevel === null) {
                $holtLevel = $x;
            } else {
                $prev = $holtLevel;
                $holtLevel = 0.2 * $x + 0.8 * ($holtLevel + 0.9 * $trend);
                $trend = 0.05 * ($holtLevel - $prev) + 0.95 * 0.9 * $trend;
            }
        }
        $lastDate = Carbon::parse(end($window)['date'], $this->scope->timezone);
        $steps = max(1, min(7, (int) $lastDate->diffInDays($target)));
        $damped = 0.0;
        for ($h = 1; $h <= $steps; $h++) {
            $damped += 0.9 ** $h;
        }
        $ewma = ($level ?? $overall) * $idx($weekday);
        $holt = (($holtLevel ?? $overall) + $damped * $trend) * $idx($weekday);

        return array_map(fn ($v) => max(0.0, (float) $v), [
            'last_week' => $lastWeek,
            'weekday_mean_4' => $mean4,
            'weekday_median_6' => $median6,
            'ewma_weekday' => $ewma,
            'holt_weekday' => $holt,
            'blend' => ($mean4 + $ewma) / 2,
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $window
     * @return array<int, float>
     */
    private function weekdayIndex(array $window, float $overall): array
    {
        if ($overall <= 0) {
            return [];
        }
        $sum = [];
        $count = [];
        foreach ($window as $p) {
            $sum[$p['weekday']] = ($sum[$p['weekday']] ?? 0.0) + $p['demand'];
            $count[$p['weekday']] = ($count[$p['weekday']] ?? 0) + 1;
        }
        $index = [];
        foreach ($sum as $wd => $total) {
            $index[$wd] = (($total + 4 * $overall) / ($count[$wd] + 4)) / $overall;
        }

        return $index;
    }

    /**
     * @param  array<string, array<string, mixed>>  $rows  date => ledger row
     * @return array{history: list<array<string, mixed>>}
     */
    private function demandSeries(int $locationId, int $productId, array $rows): array
    {
        $points = [];
        foreach ($rows as $date => $row) {
            if (! isset($this->openDays[$locationId][$date])) {
                continue;
            }
            $sold = (float) $row['sold'];
            $demand = $sold;
            $capped = false;
            $corrected = false;
            if ($row['sold_out']) {
                if ($row['live_entry'] && $row['sold_out_hours_early'] !== null && $row['sold_out_hours_early'] >= 0.5 && $row['last_sale_hour'] !== null) {
                    $share = $this->cumulativeShare($locationId, (float) $row['last_sale_hour']);
                    $demand = $sold / max(0.4, $share);
                    $corrected = true;
                } else {
                    $capped = true;
                }
            }
            $points[] = [
                'date' => $date,
                'weekday' => (int) Carbon::parse($date, $this->scope->timezone)->dayOfWeek,
                'sold' => $sold,
                'demand' => $demand,
                'sold_out' => (bool) $row['sold_out'],
                'capped' => $capped,
                'corrected' => $corrected,
                'available' => (float) $row['available'],
            ];
        }

        foreach ($points as $i => $point) {
            if (! $point['capped']) {
                continue;
            }
            $comps = [];
            for ($j = $i - 1; $j >= 0 && count($comps) < 6; $j--) {
                if ($points[$j]['weekday'] === $point['weekday'] && ! $points[$j]['capped']) {
                    $comps[] = $points[$j]['demand'];
                }
            }
            if (count($comps) < 2) {
                for ($j = $i + 1; $j < count($points) && count($comps) < 4; $j++) {
                    if ($points[$j]['weekday'] === $point['weekday'] && ! $points[$j]['capped']) {
                        $comps[] = $points[$j]['demand'];
                    }
                }
            }
            if (count($comps) >= 2) {
                $points[$i]['demand'] = max($point['sold'], Stats::quantile($comps, 0.5));
            }
        }

        return ['history' => $points];
    }

    private function cumulativeShare(int $locationId, float $hour): float
    {
        $profile = $this->hourlyProfile[$locationId] ?? null;
        if ($profile === null) {
            return 1.0;
        }
        $h = (int) floor($hour);
        $before = $profile[$h - 1] ?? ($h <= 0 ? 0.0 : $this->shareBefore($profile, $h));
        $at = $profile[$h] ?? $before;

        return $before + ($at - $before) * ($hour - $h);
    }

    /**
     * @param  array<int, float>  $profile
     */
    private function shareBefore(array $profile, int $hour): float
    {
        $value = 0.0;
        foreach ($profile as $h => $share) {
            if ($h < $hour) {
                $value = $share;
            }
        }

        return $value;
    }

    /**
     * @return array<int, array<int, float>>
     */
    private function buildHourlyProfile(Carbon $start, Carbon $end): array
    {
        $live = array_keys(array_filter($this->ledger['live_entry'] ?? []));
        if ($live === []) {
            return [];
        }
        $rows = DB::table('transaction_sell_lines as tsl')
            ->join('transactions as t', 't.id', '=', 'tsl.transaction_id')
            ->where('t.business_id', $this->scope->businessId)
            ->where('t.type', 'sell')
            ->where('t.status', 'final')
            ->whereNull('tsl.parent_sell_line_id')
            ->whereIn('t.location_id', $live)
            ->whereBetween('t.transaction_date', [$start->toDateTimeString(), $end->copy()->endOfDay()->toDateTimeString()])
            ->groupBy('t.location_id', DB::raw('HOUR(t.transaction_date)'))
            ->selectRaw('t.location_id, HOUR(t.transaction_date) as h, SUM(tsl.quantity - COALESCE(tsl.quantity_returned, 0)) as qty')
            ->get();
        $byLocation = [];
        foreach ($rows as $r) {
            $byLocation[(int) $r->location_id][(int) $r->h] = (float) $r->qty;
        }
        $out = [];
        foreach ($byLocation as $locationId => $hours) {
            ksort($hours);
            $total = array_sum($hours);
            $running = 0.0;
            foreach (range(0, 23) as $h) {
                $running += $hours[$h] ?? 0.0;
                $out[$locationId][$h] = $total > 0 ? $running / $total : 1.0;
            }
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $events
     * @param  list<array<string, mixed>>  $pastEvents
     * @return array{factor: float, closed: bool, notes: list<string>}
     */
    private function locationFactors(int $locationId, Carbon $target, ?string $holiday, array $events, array $pastEvents): array
    {
        $factor = 1.0;
        $notes = [];
        $weekday = (int) $target->dayOfWeek;

        $recentSame = [];
        for ($i = 1; $i <= 6; $i++) {
            $recentSame[] = isset($this->openDays[$locationId][$target->copy()->subWeeks($i)->toDateString()]);
        }
        $closed = count(array_filter($recentSame)) < 3;
        if ($closed) {
            $notes[] = 'Shop usually does not trade on '.$target->englishDayOfWeek.'.';
        }

        if ($holiday !== null) {
            $learned = $this->learnedRatio($locationId, fn (string $date) => $this->holidayOn(Carbon::parse($date, $this->scope->timezone)) !== null, true);
            if ($learned !== null) {
                $factor *= $learned['ratio'];
                $notes[] = $holiday.': past holidays ran at '.round($learned['ratio'] * 100).'% of a normal '.$target->englishDayOfWeek.' ('.$learned['n'].' seen).';
            } else {
                $factor *= 0.7;
                $notes[] = $holiday.': no holiday history yet; assumed 70% of normal.';
            }
        }

        foreach ($events as $event) {
            if ($event['location_id'] !== null && (int) $event['location_id'] !== $locationId) {
                continue;
            }
            if ($event['effect_pct'] !== null) {
                $factor *= max(0.0, 1 + (float) $event['effect_pct'] / 100);
                $notes[] = $event['name'].': owner set '.((float) $event['effect_pct'] >= 0 ? '+' : '').(float) $event['effect_pct'].'%.';
            } elseif ($event['kind'] === 'closure') {
                $closed = true;
                $notes[] = $event['name'].': closed.';
            } else {
                $name = mb_strtolower($event['name']);
                $windows = array_filter($pastEvents, fn ($e) => mb_strtolower($e['name']) === $name
                    && $e['starts_on'] < $this->today->toDateString()
                    && ($e['location_id'] === null || (int) $e['location_id'] === $locationId));
                $learned = $windows === [] ? null : $this->learnedRatio($locationId, function (string $date) use ($windows) {
                    foreach ($windows as $w) {
                        if ($date >= $w['starts_on'] && $date <= $w['ends_on']) {
                            return true;
                        }
                    }

                    return false;
                }, false);
                if ($learned !== null && $learned['n'] >= 3) {
                    $factor *= $learned['ratio'];
                    $notes[] = $event['name'].': learned '.round(($learned['ratio'] - 1) * 100).'% from '.$learned['n'].' past days.';
                } else {
                    $notes[] = $event['name'].': no effect learned yet; set an expected % in Eli settings.';
                }
            }
        }

        return ['factor' => $factor, 'closed' => $closed, 'notes' => $notes];
    }

    /**
     * Mean of actual / expected location demand on history days that match $predicate,
     * where expected is the mean of the previous four same-weekday open days.
     *
     * @return array{ratio: float, n: int}|null
     */
    private function learnedRatio(int $locationId, callable $predicate, bool $includeClosed): ?array
    {
        $totals = $this->locationTotals[$locationId] ?? [];
        if ($totals === []) {
            return null;
        }
        $dates = array_keys($totals);
        $first = Carbon::parse($dates[0], $this->scope->timezone);
        $matchRatios = [];
        $otherRatios = [];
        $cursor = $first->copy()->addDays(28);
        $end = $this->today->copy()->subDay();
        while ($cursor->lte($end)) {
            $date = $cursor->toDateString();
            $isOpen = isset($totals[$date]);
            $matches = $predicate($date);
            if ($isOpen || ($matches && $includeClosed)) {
                $comps = [];
                for ($w = 1; $w <= 8 && count($comps) < 4; $w++) {
                    $d = $cursor->copy()->subWeeks($w)->toDateString();
                    if (isset($totals[$d]) && ! $predicate($d)) {
                        $comps[] = $totals[$d];
                    }
                }
                if (count($comps) >= 2) {
                    $expected = Stats::mean($comps);
                    if ($expected > 0) {
                        $ratio = ($totals[$date] ?? 0.0) / $expected;
                        if ($matches) {
                            $matchRatios[] = $ratio;
                        } else {
                            $otherRatios[] = $ratio;
                        }
                    }
                }
            }
            $cursor->addDay();
        }
        if ($matchRatios === []) {
            return null;
        }
        $baseline = $otherRatios !== [] ? Stats::mean($otherRatios) : 1.0;

        return ['ratio' => max(0.0, min(3.0, Stats::mean($matchRatios) / max(0.2, $baseline))), 'n' => count($matchRatios)];
    }

    private function holidayOn(Carbon $date): ?string
    {
        foreach (GhanaPublicHolidays::forYear((int) $date->year) as $holiday) {
            if ($holiday['date'] === $date->toDateString()) {
                return $holiday['name'];
            }
        }

        return null;
    }

    private function defaultQuantile(float $ratio): float
    {
        return 1 + 0.25 * (($ratio - 0.5) / 0.4) * 1.28;
    }

    private function fallbackCost(int $productId): ?float
    {
        $cost = DB::table('variations')->where('product_id', $productId)->avg('dpp_inc_tax');

        return $cost !== null && (float) $cost > 0 ? (float) $cost : null;
    }

    /**
     * @return array<string, float>
     */
    private function currentStockByKey(): array
    {
        return DB::table('variation_location_details as vld')
            ->join('products as p', 'p.id', '=', 'vld.product_id')
            ->where('p.business_id', $this->scope->businessId)
            ->groupBy('vld.location_id', 'vld.product_id')
            ->selectRaw('vld.location_id, vld.product_id, SUM(vld.qty_available) as qty')
            ->get()
            ->mapWithKeys(fn ($r) => [((int) $r->location_id).':'.((int) $r->product_id) => (float) $r->qty])
            ->all();
    }

    /**
     * shop location_id => product_id|'*' => bakery location_id => share.
     *
     * @param  list<int>  $bakeries
     * @return array<int, array<int|string, array<int, float>>>
     */
    private function supplyShares(Carbon $since, array $bakeries): array
    {
        $rows = DB::table('transactions as t_out')
            ->join('transactions as t_in', 't_in.transfer_parent_id', '=', 't_out.id')
            ->join('transaction_sell_lines as tsl', 'tsl.transaction_id', '=', 't_out.id')
            ->where('t_out.business_id', $this->scope->businessId)
            ->where('t_out.type', 'sell_transfer')
            ->where('t_in.type', 'purchase_transfer')
            ->where('t_out.transaction_date', '>=', $since->toDateTimeString())
            ->whereIn('t_out.location_id', $bakeries)
            ->groupBy('t_in.location_id', 'tsl.product_id', 't_out.location_id')
            ->selectRaw('t_in.location_id as shop, tsl.product_id, t_out.location_id as bakery, SUM(tsl.quantity) as qty')
            ->get();
        $raw = [];
        foreach ($rows as $r) {
            $shop = (int) $r->shop;
            if (in_array($shop, $bakeries, true)) {
                continue;
            }
            $raw[$shop][(int) $r->product_id][(int) $r->bakery] = (float) $r->qty;
            $raw[$shop]['*'][(int) $r->bakery] = ($raw[$shop]['*'][(int) $r->bakery] ?? 0.0) + (float) $r->qty;
        }
        $out = [];
        foreach ($raw as $shop => $products) {
            foreach ($products as $productId => $byBakery) {
                $total = array_sum($byBakery);
                if ($total <= 0) {
                    continue;
                }
                foreach ($byBakery as $bakeryId => $qty) {
                    $out[$shop][$productId][$bakeryId] = $qty / $total;
                }
            }
        }

        return $out;
    }
}
