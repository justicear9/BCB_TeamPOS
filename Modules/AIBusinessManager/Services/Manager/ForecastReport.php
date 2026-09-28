<?php

namespace Modules\AIBusinessManager\Services\Manager;

use Carbon\Carbon;

class ForecastReport
{
    public function __construct(private ManagerScope $scope)
    {
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public function demandForecast(array $args): array
    {
        $scope = $this->scope;
        try {
            $target = ! empty($args['target_date'])
                ? Carbon::parse((string) $args['target_date'], $scope->timezone)->startOfDay()
                : $scope->today()->addDay();
        } catch (\Throwable) {
            return ['ok' => false, 'error' => 'invalid_date_format'];
        }
        if ($target->lte($scope->today()->subDay())) {
            return ['ok' => false, 'error' => 'target_date_in_past', 'note' => 'Use forecast_accuracy for past days.'];
        }
        $days = max(1, min(7, (int) ($args['days'] ?? 1)));

        $focus = $scope->resolveLocationArgs($args);
        if (isset($focus['error'])) {
            return $focus['error'];
        }
        $focusIds = $focus['scope']->locationIds;
        $nameQuery = mb_strtolower(trim((string) ($args['name_query'] ?? '')));

        $options = [];
        if (isset($args['leftover_value_pct']) && is_numeric($args['leftover_value_pct'])) {
            $options['leftover_value_pct'] = (float) $args['leftover_value_pct'];
        }
        $forecaster = new DemandForecaster($scope, new ManagerStore($scope->businessId), new RecipeCosting($scope->businessId), $options);

        $now = $scope->now();
        $tomorrow = $scope->today()->addDay();
        $countLeftovers = array_key_exists('count_leftovers', $args)
            ? (bool) $args['count_leftovers']
            : ($target->equalTo($tomorrow) && $now->hour >= 18);

        $filter = function (array $row) use ($focusIds, $nameQuery) {
            if ($focusIds !== null && ! in_array($row['location_id'], $focusIds, true)) {
                return false;
            }

            return $nameQuery === '' || str_contains(mb_strtolower($row['product']), $nameQuery);
        };

        $perDay = [];
        $blocks = [];
        $first = null;
        for ($i = 0; $i < $days; $i++) {
            $date = $target->copy()->addDays($i);
            $forecast = $forecaster->forecastDay($date);
            $plan = $forecaster->bakeryPlan($forecast, $i === 0 && $countLeftovers);
            $rows = array_values(array_filter($forecast['rows'], $filter));
            $planRows = array_values(array_filter($plan['rows'], fn ($r) => ($nameQuery === '' || str_contains(mb_strtolower($r['product']), $nameQuery)) && $r['bake_qty'] > 0));
            if ($first === null) {
                $first = ['forecast' => $forecast, 'rows' => $rows, 'plan' => $planRows];
            }
            $perDay[] = [
                'date' => $date->toDateString(),
                'weekday' => $date->englishDayOfWeek,
                'expected_total' => round(array_sum(array_column($rows, 'p50'))),
                'recommended_total' => array_sum(array_column($rows, 'recommended_qty')),
                'bake_total' => array_sum(array_column($planRows, 'bake_qty')),
                'notes' => $forecast['notes'],
            ];
        }

        $rows = $first['rows'];
        $blocks[] = Md::table(
            ['Shop', 'Product', 'Expected', 'Likely range', 'Send', 'Backtest error', 'Price', 'Unit cost'],
            array_map(fn ($r) => [
                $r['location'],
                $r['product'],
                $r['closed'] ? 'closed' : Md::qty($r['p50']),
                Md::qty($r['p10']).'–'.Md::qty($r['p90']),
                $r['recommended_qty'],
                Md::pct($r['backtest_wape_pct']),
                Md::money($r['unit_price'], $scope),
                Md::money($r['unit_cost'], $scope),
            ], array_values(array_filter($rows, fn ($r) => $r['p90'] >= 1 || $r['recommended_qty'] > 0))),
            'Demand forecast for '.$target->format('D j M Y')
        );
        if ($first['plan'] !== [] && $focusIds === null) {
            $blocks[] = Md::table(
                ['Bakery', 'Product', 'Bake', 'Batches', 'Leftovers used', 'Send to'],
                array_map(fn ($r) => [
                    $r['bakery'],
                    $r['product'],
                    $r['bake_qty'],
                    $r['batches'] !== null ? $r['batches'].' × '.Md::qty($r['batch_size']).' = '.$r['batch_qty'] : '—',
                    $r['leftover_used'],
                    implode(', ', $r['send_to']),
                ], $first['plan']),
                'Bake plan for '.$target->format('D j M')
            );
        }
        if ($days > 1) {
            $blocks[] = Md::table(
                ['Date', 'Expected sales', 'Send to shops', 'Bake'],
                array_map(fn ($d) => [Carbon::parse($d['date'])->format('D j M'), Md::qty($d['expected_total']), $d['recommended_total'], $d['bake_total']], $perDay),
                'Next '.$days.' days'
            );
        }

        $compact = array_map(fn ($r) => array_intersect_key($r, array_flip([
            'location', 'product', 'unit', 'p10', 'p50', 'p90', 'recommended_qty', 'service_level_pct', 'model',
            'backtest_wape_pct', 'backtest_bias_pct', 'unit_price', 'unit_cost', 'loss_maker', 'capped_days_28', 'trading_days_28', 'closed', 'notes',
        ])), array_values(array_filter($rows, fn ($r) => $r['p90'] >= 1 || $r['recommended_qty'] > 0)));

        return [
            'ok' => true,
            'target_date' => $target->toDateString(),
            'weekday' => $target->englishDayOfWeek,
            'leftovers_counted' => $countLeftovers,
            'leftover_value_pct' => $forecaster->leftoverValuePct(),
            'days' => $perDay,
            'forecasts' => $compact,
            'bake_plan' => array_map(fn ($r) => array_diff_key($r, array_flip(['bakery_id', 'product_id'])), $first['plan']),
            'method' => 'Sales history since '.$scope->today()->subDays(119)->toDateString().' from the stock ledger. Sold-out days are corrected (live-entry shops: scaled by the share of the day left; batch-entry shops: filled with typical uncapped same-weekday demand). Six models were backtested one day ahead over the last 28 trading days per shop and product; the lowest error (WAPE) was chosen. Likely range = 10th–90th percentile of that model\'s past errors. Send = newsvendor quantity from price, recipe cost and leftover value ('.$forecaster->leftoverValuePct().'% of price). Holidays and owner calendar events adjust the forecast.',
            'note' => 'Quote Expected and Send per shop; Bake per bakery. Leftovers are only subtracted when leftovers_counted is true (after 18:00 for tomorrow). Mention notes (pay-day lift, sold-out warnings, loss makers). The tables are attached for the user.',
            'verbatim_block' => implode("\n\n", $blocks),
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public function forecastAccuracy(array $args): array
    {
        $scope = $this->scope;
        $end = ! empty($args['end_date']) ? Carbon::parse((string) $args['end_date'], $scope->timezone)->startOfDay() : $scope->today()->subDay();
        $start = ! empty($args['start_date']) ? Carbon::parse((string) $args['start_date'], $scope->timezone)->startOfDay() : $end->copy()->subDays(13);
        if ($end->gte($scope->today())) {
            $end = $scope->today()->subDay();
        }

        $store = new ManagerStore($scope->businessId);
        $saved = $store->savedForecasts($start, $end, $scope->locationIds);

        $forecaster = new DemandForecaster($scope, $store, new RecipeCosting($scope->businessId));
        $series = $forecaster->seriesMeta();
        $products = $forecaster->ledgerData()['products'];

        $liveRows = [];
        if ($saved !== []) {
            $ledger = new StockLedger($scope);
            $data = $ledger->build($start, $end, null, true);
            $pairs = [];
            $covered = 0;
            $byLocation = [];
            foreach ($saved as $f) {
                $row = $data['days'][$f['target_date']][$f['location_id'].':'.$f['product_id']] ?? null;
                $sold = $row ? (float) $row['sold'] : 0.0;
                $pairs[] = [$sold, (float) $f['p50']];
                if ($sold >= (float) $f['p10'] && $sold <= (float) $f['p90']) {
                    $covered++;
                }
                $byLocation[$f['location_id']][] = [$sold, (float) $f['p50']];
            }
            foreach ($byLocation as $locationId => $list) {
                $liveRows[] = [
                    'location' => $scope->locationNames[$locationId] ?? ('#'.$locationId),
                    'forecasts' => count($list),
                    'wape_pct' => Stats::pct(Stats::wape($list)),
                    'bias_pct' => Stats::pct(Stats::bias($list)),
                ];
            }
            $live = [
                'forecasts_scored' => count($saved),
                'wape_pct' => Stats::pct(Stats::wape($pairs)),
                'bias_pct' => Stats::pct(Stats::bias($pairs)),
                'inside_range_pct' => Stats::pct(count($saved) ? $covered / count($saved) : null),
                'by_location' => $liveRows,
                'note' => 'Compared with units sold. On sold-out days the true demand was higher, so error looks larger than it is.',
            ];
        } else {
            $live = ['forecasts_scored' => 0, 'note' => 'No saved forecasts for these dates yet. Eli saves every forecast it makes and scores it once the day has passed.'];
        }

        $backtest = [];
        $naivePairs = [];
        $bestPairs = [];
        foreach ($series as $meta) {
            $bt = $forecaster->backtest($meta['history']);
            if ($bt['n'] < 8) {
                continue;
            }
            $volume = array_sum(array_map(fn ($p) => $p['demand'], array_slice($meta['history'], -$bt['n'])));
            $backtest[] = [
                'location' => $scope->locationNames[$meta['location_id']] ?? ('#'.$meta['location_id']),
                'product' => $products[$meta['product_id']]['name'] ?? ('#'.$meta['product_id']),
                'model' => $bt['best'],
                'wape_pct' => Stats::pct($bt['wape']),
                'naive_wape_pct' => Stats::pct($bt['by_model']['last_week']),
                'bias_pct' => Stats::pct($bt['bias']),
                'volume' => round($volume),
            ];
            if ($bt['wape'] !== null) {
                $bestPairs[] = [$volume, $bt['wape'] * $volume];
            }
            if ($bt['by_model']['last_week'] !== null) {
                $naivePairs[] = [$volume, $bt['by_model']['last_week'] * $volume];
            }
        }
        usort($backtest, fn ($a, $b) => $b['volume'] <=> $a['volume']);
        $weighted = fn (array $pairs) => ($sum = array_sum(array_column($pairs, 0))) > 0 ? array_sum(array_column($pairs, 1)) / $sum : null;

        $blocks = [];
        if ($liveRows !== []) {
            $blocks[] = Md::table(['Shop', 'Forecasts', 'Error (WAPE)', 'Bias'], array_map(fn ($r) => [$r['location'], $r['forecasts'], Md::pct($r['wape_pct']), Md::pct($r['bias_pct'])], $liveRows), 'Saved forecasts vs actual sales, '.$start->format('j M').'–'.$end->format('j M'));
        }
        $blocks[] = Md::table(
            ['Shop', 'Product', 'Model', 'Error', 'Same-day-last-week error', 'Bias'],
            array_map(fn ($r) => [$r['location'], $r['product'], $r['model'], Md::pct($r['wape_pct']), Md::pct($r['naive_wape_pct']), Md::pct($r['bias_pct'])], array_slice($backtest, 0, 25)),
            'Backtest, last 28 trading days (one day ahead)'
        );

        return [
            'ok' => true,
            'range' => ['start' => $start->toDateString(), 'end' => $end->toDateString()],
            'saved_forecasts' => $live,
            'backtest_volume_weighted_wape_pct' => Stats::pct($weighted($bestPairs)),
            'naive_volume_weighted_wape_pct' => Stats::pct($weighted($naivePairs)),
            'backtest' => array_slice($backtest, 0, 25),
            'note' => 'WAPE = total absolute error / total actual. Naive = same weekday last week. Bias > 0 means over-forecasting.',
            'verbatim_block' => implode("\n\n", $blocks),
        ];
    }
}
