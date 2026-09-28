<?php

namespace Modules\AIBusinessManager\Services\Manager;

use Carbon\Carbon;

class ProductionReport
{
    public function __construct(private ManagerScope $scope)
    {
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public function productionLedger(array $args, Carbon $start, Carbon $end): array
    {
        $focus = $this->scope->resolveLocationArgs($args);
        if (isset($focus['error'])) {
            return $focus['error'];
        }
        $focusIds = $focus['scope']->locationIds;
        $nameQuery = mb_strtolower(trim((string) ($args['name_query'] ?? '')));

        $ledger = new StockLedger($this->scope);
        $data = $ledger->build($start, $end, null, true);
        $products = $data['products'];
        $exactIds = array_keys(array_filter($products, fn ($p) => mb_strtolower(trim($p['name'] ?? '')) === $nameQuery));
        $matchProduct = fn (int $pid) => $nameQuery === ''
            || ($exactIds !== [] ? in_array($pid, $exactIds, true) : str_contains(mb_strtolower($products[$pid]['name'] ?? ''), $nameQuery));

        $network = [];
        $shops = [];
        $daily = [];
        foreach ($data['days'] as $date => $rows) {
            foreach ($rows as $key => $row) {
                $pid = $row['product_id'];
                if (! $matchProduct($pid)) {
                    continue;
                }
                $n = &$network[$pid];
                $n ??= ['produced' => 0.0, 'production_waste' => 0.0, 'sold' => 0.0, 'adjusted' => 0.0, 'revenue' => 0.0];
                $n['produced'] += $row['produced'];
                $n['production_waste'] += $row['production_waste'];
                $n['sold'] += $row['sold'];
                $n['adjusted'] += $row['adjusted'];
                $n['revenue'] += $row['revenue'];
                unset($n);

                if ($focusIds !== null && ! in_array($row['location_id'], $focusIds, true)) {
                    continue;
                }
                $s = &$shops[$key];
                $s ??= ['location_id' => $row['location_id'], 'product_id' => $pid, 'opening' => $row['opening'], 'produced' => 0.0, 'received' => 0.0,
                    'sold' => 0.0, 'sent_out' => 0.0, 'returned_after_trading' => 0.0, 'adjusted' => 0.0, 'closing' => 0.0,
                    'trading_days' => 0, 'sold_out_days' => 0, 'hours_early' => [], 'revenue' => 0.0];
                $s['produced'] += $row['produced'];
                $s['received'] += $row['transfer_in'] + $row['purchased'] + $row['opening_stock'];
                $s['sold'] += $row['sold'];
                $s['sent_out'] += $row['transfer_out'];
                $s['returned_after_trading'] += $row['returned_after_trading'];
                $s['adjusted'] += $row['adjusted'] + $row['consumed'];
                $s['closing'] = $row['closing'];
                $s['revenue'] += $row['revenue'];
                if ($row['sold'] > 0) {
                    $s['trading_days']++;
                }
                if ($row['sold_out']) {
                    $s['sold_out_days']++;
                    if ($row['sold_out_hours_early'] !== null) {
                        $s['hours_early'][] = $row['sold_out_hours_early'];
                    }
                }
                unset($s);
                $daily[$key][$date] = $row;
            }
        }

        $networkRows = [];
        foreach ($network as $pid => $n) {
            if ($n['produced'] <= 0 && $n['sold'] <= 0) {
                continue;
            }
            $networkRows[] = [
                'product' => $products[$pid]['name'] ?? ('#'.$pid),
                'produced' => round($n['produced']),
                'production_waste' => round($n['production_waste']),
                'sold' => round($n['sold']),
                'written_off' => round($n['adjusted']),
                'sold_pct_of_produced' => $n['produced'] > 0 ? round($n['sold'] / $n['produced'] * 100, 1) : null,
                'written_off_pct_of_produced' => $n['produced'] > 0 ? round($n['adjusted'] / $n['produced'] * 100, 1) : null,
                'revenue' => $this->scope->money($n['revenue']),
            ];
        }
        usort($networkRows, fn ($a, $b) => $b['produced'] <=> $a['produced']);

        $shopRows = [];
        foreach ($shops as $s) {
            $supply = $s['opening'] + $s['produced'] + $s['received'];
            $shopRows[] = [
                'location' => $this->scope->locationNames[$s['location_id']] ?? ('#'.$s['location_id']),
                'product' => $products[$s['product_id']]['name'] ?? ('#'.$s['product_id']),
                'opening' => round($s['opening']),
                'produced' => round($s['produced']),
                'received' => round($s['received']),
                'sold' => round($s['sold']),
                'sent_out' => round($s['sent_out']),
                'returned_after_trading' => round($s['returned_after_trading']),
                'written_off' => round($s['adjusted']),
                'closing' => round($s['closing']),
                'sell_through_pct' => $supply > 0 ? round($s['sold'] / $supply * 100, 1) : null,
                'trading_days' => $s['trading_days'],
                'sold_out_days' => $s['sold_out_days'],
                'avg_hours_sold_out_early' => $s['hours_early'] !== [] ? round(Stats::mean($s['hours_early']), 1) : null,
                'revenue' => $this->scope->money($s['revenue']),
            ];
        }
        usort($shopRows, fn ($a, $b) => [$a['location'], -$a['sold']] <=> [$b['location'], -$b['sold']]);
        $shopRows = array_values(array_filter($shopRows, fn ($r) => $r['sold'] + $r['produced'] + $r['received'] + $r['sent_out'] > 0));

        $blocks = [];
        if ($networkRows !== [] && $focusIds === null) {
            $blocks[] = Md::table(
                ['Product', 'Baked', 'Wasted in production', 'Sold (all shops)', 'Written off', 'Sold % of baked'],
                array_map(fn ($r) => [$r['product'], Md::qty($r['produced']), Md::qty($r['production_waste']), Md::qty($r['sold']), Md::qty($r['written_off']), Md::pct($r['sold_pct_of_produced'], 1)], $networkRows),
                'Production vs sales, '.$start->format('j M').'–'.$end->format('j M Y')
            );
        }
        $blocks[] = Md::table(
            ['Shop', 'Product', 'Opening', 'Baked', 'Received', 'Sold', 'Sent out', 'Written off', 'Closing', 'Sell-through', 'Sold-out days'],
            array_map(fn ($r) => [$r['location'], $r['product'], Md::qty($r['opening']), Md::qty($r['produced']), Md::qty($r['received']), Md::qty($r['sold']), Md::qty($r['sent_out']), Md::qty($r['written_off']), Md::qty($r['closing']), Md::pct($r['sell_through_pct']), $r['sold_out_days'].'/'.$r['trading_days']], $shopRows),
            'Stock ledger by shop'
        );

        $dailyRows = [];
        if (count($daily) === 1) {
            $only = reset($daily);
            foreach ($only as $date => $row) {
                $dailyRows[] = [
                    'date' => $date,
                    'opening' => round($row['opening']),
                    'baked' => round($row['produced']),
                    'received' => round($row['transfer_in']),
                    'sold' => round($row['sold']),
                    'sent_out' => round($row['transfer_out']),
                    'written_off' => round($row['adjusted']),
                    'closing' => round($row['closing']),
                    'last_sale' => $row['last_sale_time'],
                    'sold_out' => $row['sold_out'],
                ];
            }
            $blocks[] = Md::table(
                ['Date', 'Opening', 'Baked', 'Received', 'Sold', 'Sent out', 'Written off', 'Closing', 'Last sale', 'Sold out'],
                array_map(fn ($r) => [Carbon::parse($r['date'])->format('D j M'), $r['opening'], $r['baked'], $r['received'], $r['sold'], $r['sent_out'], $r['written_off'], $r['closing'], $r['last_sale'] ?? '—', $r['sold_out'] ? 'yes' : ''], $dailyRows),
                'Day by day'
            );
        }

        $liveShops = array_values(array_map(fn ($id) => $this->scope->locationNames[$id] ?? ('#'.$id), array_keys(array_filter($data['live_entry']))));

        return [
            'ok' => true,
            'range' => ['start' => $start->toDateString(), 'end' => $end->toDateString()],
            'network' => $networkRows,
            'shops' => $shopRows,
            'daily' => $dailyRows,
            'live_entry_shops' => $liveShops,
            'note' => 'Built from manufacturing production (baked), stock transfers, final sales and stock adjustments (written off) in base units. Opening stock is rebuilt backwards from current stock. Sold out = no stock left after the last sale of the day. Sell-out time is only meaningful at shops that key sales live ('.implode(', ', $liveShops ?: ['none']).'); other shops enter the day\'s sales at night.',
            'verbatim_block' => implode("\n\n", $blocks),
        ];
    }

    /**
     * Ingredients: recipe-expected use vs recorded production use, plus purchases and count corrections.
     *
     * @return array<string, mixed>
     */
    public function ingredientVariance(Carbon $start, Carbon $end): array
    {
        $costing = new RecipeCosting($this->scope->businessId);
        $recipes = $costing->recipes();
        if ($recipes === []) {
            return ['ok' => false, 'error' => 'no_recipes'];
        }
        $ingredientIds = [];
        $prices = [];
        $names = [];
        foreach ($recipes as $recipe) {
            foreach ($recipe['ingredients'] as $i) {
                $ingredientIds[$i['product_id']] = true;
                $prices[$i['product_id']] = $i['unit_price'];
                $names[$i['product_id']] = $i['name'].' ('.$i['unit'].')';
            }
        }
        $ledger = new StockLedger($this->scope);
        $data = $ledger->build($start, $end, array_merge(array_keys($ingredientIds), array_keys($recipes)));

        $expected = [];
        $actual = [];
        $purchased = [];
        $adjusted = [];
        $transfers = [];
        $closing = [];
        foreach ($data['days'] as $rows) {
            foreach ($rows as $row) {
                $pid = $row['product_id'];
                $lid = $row['location_id'];
                if (isset($recipes[$pid]) && ($row['produced'] + $row['production_waste']) > 0) {
                    $made = $row['produced'] + $row['production_waste'];
                    foreach ($recipes[$pid]['ingredients'] as $i) {
                        $expected[$lid][$i['product_id']] = ($expected[$lid][$i['product_id']] ?? 0.0) + $made * $i['qty_per_unit'];
                    }
                }
                if (isset($ingredientIds[$pid])) {
                    $actual[$lid][$pid] = ($actual[$lid][$pid] ?? 0.0) + $row['consumed'];
                    $purchased[$lid][$pid] = ($purchased[$lid][$pid] ?? 0.0) + $row['purchased'];
                    $adjusted[$lid][$pid] = ($adjusted[$lid][$pid] ?? 0.0) + $row['adjusted'];
                    $transfers[$lid][$pid] = ($transfers[$lid][$pid] ?? 0.0) + $row['transfer_in'] - $row['transfer_out'];
                    $closing[$lid][$pid] = $row['closing'];
                }
            }
        }

        $rows = [];
        $totalVarianceValue = 0.0;
        foreach (array_unique(array_merge(array_keys($expected), array_keys($actual))) as $lid) {
            foreach (array_keys($ingredientIds) as $pid) {
                $exp = $expected[$lid][$pid] ?? 0.0;
                $act = $actual[$lid][$pid] ?? 0.0;
                $adj = $adjusted[$lid][$pid] ?? 0.0;
                if ($exp <= 0 && $act <= 0 && $adj <= 0) {
                    continue;
                }
                $variance = $act - $exp;
                $value = $variance * ($prices[$pid] ?? 0.0);
                $totalVarianceValue += $value;
                $rows[] = [
                    'location' => $this->scope->locationNames[$lid] ?? ('#'.$lid),
                    'ingredient' => $names[$pid] ?? ('#'.$pid),
                    'recipe_expected' => round($exp, 2),
                    'recorded_use' => round($act, 2),
                    'variance' => round($variance, 2),
                    'variance_pct' => $exp > 0 ? round($variance / $exp * 100, 1) : null,
                    'variance_value' => $this->scope->money($value),
                    'purchased' => round($purchased[$lid][$pid] ?? 0.0, 2),
                    'net_transfers' => round($transfers[$lid][$pid] ?? 0.0, 2),
                    'written_off_or_counted_out' => round($adj, 2),
                    'written_off_value' => $this->scope->money($adj * ($prices[$pid] ?? 0.0)),
                    'stock_now' => isset($closing[$lid][$pid]) ? round($closing[$lid][$pid], 2) : null,
                ];
            }
        }
        usort($rows, fn ($a, $b) => abs($b['variance_value']) + $b['written_off_value'] <=> abs($a['variance_value']) + $a['written_off_value']);

        $production = [];
        foreach ($data['days'] as $dayRows) {
            foreach ($dayRows as $row) {
                if (isset($recipes[$row['product_id']]) && $row['produced'] > 0) {
                    $p = &$production[$row['location_id'].':'.$row['product_id']];
                    $p ??= ['location_id' => $row['location_id'], 'product_id' => $row['product_id'], 'produced' => 0.0, 'wasted' => 0.0];
                    $p['produced'] += $row['produced'];
                    $p['wasted'] += $row['production_waste'];
                    unset($p);
                }
            }
        }
        $wasteRows = [];
        foreach ($production as $p) {
            $made = $p['produced'] + $p['wasted'];
            $wasteRows[] = [
                'location' => $this->scope->locationNames[$p['location_id']] ?? ('#'.$p['location_id']),
                'product' => $recipes[$p['product_id']]['name'],
                'produced' => round($p['produced']),
                'wasted' => round($p['wasted']),
                'waste_pct' => $made > 0 ? round($p['wasted'] / $made * 100, 1) : null,
                'waste_cost' => $this->scope->money($p['wasted'] * $recipes[$p['product_id']]['unit_cost']),
            ];
        }

        $block = Md::table(
            ['Bakery', 'Ingredient', 'Recipe says', 'Recorded use', 'Over / under', 'Value', 'Purchased', 'Written off'],
            array_map(fn ($r) => [$r['location'], $r['ingredient'], Md::qty($r['recipe_expected'], 2), Md::qty($r['recorded_use'], 2), Md::qty($r['variance'], 2).($r['variance_pct'] !== null ? ' ('.Md::pct($r['variance_pct'], 1).')' : ''), Md::money($r['variance_value'], $this->scope), Md::qty($r['purchased'], 2), Md::qty($r['written_off_or_counted_out'], 2)], array_slice($rows, 0, 30)),
            'Ingredient use vs recipe, '.$start->format('j M').'–'.$end->format('j M Y')
        );

        return [
            'ok' => true,
            'range' => ['start' => $start->toDateString(), 'end' => $end->toDateString()],
            'ingredients' => array_slice($rows, 0, 40),
            'production_waste' => $wasteRows,
            'total_variance_value' => $this->scope->money($totalVarianceValue),
            'note' => 'Recipe says = (units baked + units wasted in production) × recipe quantity per unit (including ingredient waste %). Recorded use = ingredients consumed on the production screen. A positive variance means more was used than the recipe allows. Written off = stock adjustments (count corrections, spoilage). Values use the latest purchase price.',
            'verbatim_block' => $block,
        ];
    }
}
