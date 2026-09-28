<?php

namespace Modules\AIBusinessManager\Services\Manager;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Full cost per baked unit and profit by shop.
 *
 * Expense categories are grouped by name into production (gas, utilities, packaging, repairs…), distribution
 * (transport, fuel, vehicle…), people (allowances, staff…) and admin (everything else). Production overhead
 * is spread over baked units by ingredient cost; distribution over units received by each shop; people,
 * admin and owner-entered fixed costs by revenue share (location-specific fixed costs stay with their shop).
 */
class Economics
{
    private const GROUPS = [
        'production' => '/gas|utilit|electric|production|packag|repair|mainten|clean|sanit|market|fda|water|flour|oven/i',
        'distribution' => '/transport|fuel|distribut|vehicle|van|delivery|washing/i',
        'people' => '/allowance|staff|lunch|refresh|salar|wage|medical|payroll|bonus/i',
    ];

    public function __construct(private ManagerScope $scope)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function unitEconomics(Carbon $start, Carbon $end): array
    {
        $base = $this->base($start, $end);
        if ($base['recipes'] === []) {
            return ['ok' => false, 'error' => 'no_recipes'];
        }
        $rows = [];
        foreach ($base['products'] as $pid => $p) {
            if ($p['produced'] <= 0 && $p['sold'] <= 0) {
                continue;
            }
            $recipe = $base['recipes'][$pid];
            $price = $p['sold'] > 0 ? $p['revenue'] / $p['sold'] : null;
            $ingredient = $recipe['ingredient_cost_per_unit'];
            $factory = $ingredient + $base['production_per_ingredient_cedi'] * $ingredient;
            $overhead = $base['other_per_ingredient_cedi'] * $ingredient;
            $full = $factory + $overhead;
            $wasteFactor = $p['sold'] > 0 && $p['produced'] > 0 ? max(1.0, $p['produced'] / $p['sold']) : 1.0;
            $fullSold = $full * $wasteFactor;
            $rows[] = [
                'product' => $recipe['name'],
                'baked' => round($p['produced']),
                'sold' => round($p['sold']),
                'avg_price' => $price !== null ? $this->scope->money($price) : null,
                'ingredient_cost' => $this->scope->money($ingredient),
                'recipe_cost_in_teampos' => $this->scope->money($recipe['unit_cost']),
                'factory_cost' => $this->scope->money($factory),
                'full_cost' => $this->scope->money($full),
                'unsold_share_pct' => $p['produced'] > 0 ? round(max(0.0, 1 - $p['sold'] / $p['produced']) * 100, 1) : null,
                'full_cost_per_sold_unit' => $this->scope->money($fullSold),
                'margin_per_sold_unit' => $price !== null ? $this->scope->money($price - $fullSold) : null,
                'margin_pct' => $price ? round(($price - $fullSold) / $price * 100, 1) : null,
                'profit' => $price !== null ? $this->scope->money($p['revenue'] - $fullSold * $p['sold']) : null,
                'loss_maker' => $price !== null && $price < $fullSold,
            ];
        }
        usort($rows, fn ($a, $b) => ($b['profit'] ?? 0) <=> ($a['profit'] ?? 0));

        $block = Md::table(
            ['Product', 'Baked', 'Sold', 'Avg price', 'Ingredients', 'Full cost', 'Unsold', 'Cost per sold unit', 'Margin', 'Profit'],
            array_map(fn ($r) => [$r['product'], Md::qty($r['baked']), Md::qty($r['sold']), Md::money($r['avg_price'], $this->scope), Md::money($r['ingredient_cost'], $this->scope), Md::money($r['full_cost'], $this->scope), Md::pct($r['unsold_share_pct'], 1), Md::money($r['full_cost_per_sold_unit'], $this->scope), Md::money($r['margin_per_sold_unit'], $this->scope).' ('.Md::pct($r['margin_pct'], 1).')', Md::money($r['profit'], $this->scope)], $rows),
            'Unit economics, '.$start->format('j M').'–'.$end->format('j M Y')
        );

        return [
            'ok' => true,
            'range' => ['start' => $start->toDateString(), 'end' => $end->toDateString()],
            'products' => $rows,
            'overheads' => $base['overhead_summary'],
            'owner_fixed_costs_entered' => $base['fixed_count'] > 0,
            'note' => 'Ingredients use the latest purchase prices. Factory cost adds production expenses (gas, utilities, packaging, repairs, production supplies) spread by ingredient cost. Full cost adds distribution, people, admin expenses and owner-entered fixed costs the same way. Cost per sold unit spreads the cost of unsold / written-off units over units sold. Recipe cost in TeamPOS is the recipe screen figure for comparison. '.($base['fixed_count'] === 0 ? 'No owner fixed costs (rent, wages) are entered in Eli settings, and TeamPOS has no payroll, so labour and rent are missing: real margins are lower.' : ''),
            'verbatim_block' => $block,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function locationPnl(Carbon $start, Carbon $end): array
    {
        $base = $this->base($start, $end);
        $scope = $this->scope;
        $days = $start->diffInDays($end) + 1;

        $revenue = DB::table('transactions')
            ->where('business_id', $scope->businessId)
            ->when($scope->locationIds !== null, fn ($q) => $q->whereIn('location_id', $scope->locationIds ?: [0]))
            ->whereIn('type', ['sell', 'sell_return'])
            ->where('status', 'final')
            ->whereBetween('transaction_date', [$start->toDateTimeString(), $end->copy()->endOfDay()->toDateTimeString()])
            ->groupBy('location_id')
            ->selectRaw('location_id, SUM(CASE WHEN type = "sell" THEN final_total ELSE -final_total END) as revenue')
            ->pluck('revenue', 'location_id')
            ->map(fn ($v) => (float) $v)
            ->all();
        $totalRevenue = array_sum($revenue);

        $cogs = [];
        $nonRecipe = DB::table('transaction_sell_lines as tsl')
            ->join('transactions as t', 't.id', '=', 'tsl.transaction_id')
            ->join('variations as v', 'v.id', '=', 'tsl.variation_id')
            ->where('t.business_id', $scope->businessId)
            ->where('t.type', 'sell')
            ->where('t.status', 'final')
            ->whereNull('tsl.parent_sell_line_id')
            ->when($scope->locationIds !== null, fn ($q) => $q->whereIn('t.location_id', $scope->locationIds ?: [0]))
            ->whereNotIn('tsl.product_id', array_keys($base['recipes']) ?: [0])
            ->whereBetween('t.transaction_date', [$start->toDateTimeString(), $end->copy()->endOfDay()->toDateTimeString()])
            ->groupBy('t.location_id')
            ->selectRaw('t.location_id, SUM((tsl.quantity - COALESCE(tsl.quantity_returned, 0)) * v.dpp_inc_tax) as cost')
            ->pluck('cost', 'location_id');
        foreach ($nonRecipe as $lid => $cost) {
            $cogs[(int) $lid] = (float) $cost;
        }
        $waste = [];
        foreach ($base['location_product'] as $lp) {
            $recipe = $base['recipes'][$lp['product_id']];
            $factory = $recipe['ingredient_cost_per_unit'] * (1 + $base['production_per_ingredient_cedi']);
            $cogs[$lp['location_id']] = ($cogs[$lp['location_id']] ?? 0.0) + $lp['sold'] * $factory;
            $waste[$lp['location_id']] = ($waste[$lp['location_id']] ?? 0.0) + $lp['adjusted'] * $factory;
        }

        $received = [];
        foreach ($base['location_product'] as $lp) {
            $received[$lp['location_id']] = ($received[$lp['location_id']] ?? 0.0) + $lp['transfer_in'];
        }
        $totalReceived = array_sum($received);

        $locationIds = array_values(array_unique(array_merge(array_keys($revenue), array_keys($scope->locationNames))));
        $rows = [];
        foreach ($locationIds as $lid) {
            if ($scope->locationIds !== null && ! in_array($lid, $scope->locationIds, true)) {
                continue;
            }
            $rev = $revenue[$lid] ?? 0.0;
            $share = $totalRevenue > 0 ? $rev / $totalRevenue : 0.0;
            $distribution = $totalReceived > 0 ? $base['expenses']['distribution'] * (($received[$lid] ?? 0.0) / $totalReceived) : 0.0;
            $shared = ($base['expenses']['people'] + $base['expenses']['admin']) * $share;
            $fixed = ($base['fixed_by_location'][$lid] ?? 0.0) + $base['fixed_shared'] * $share;
            $gross = $rev - ($cogs[$lid] ?? 0.0);
            $profit = $gross - ($waste[$lid] ?? 0.0) - $distribution - $shared - $fixed;
            if ($rev == 0.0 && ($cogs[$lid] ?? 0.0) == 0.0) {
                continue;
            }
            $rows[] = [
                'location' => $scope->locationNames[$lid] ?? ('#'.$lid),
                'revenue' => $scope->money($rev),
                'cost_of_goods' => $scope->money($cogs[$lid] ?? 0.0),
                'gross_profit' => $scope->money($gross),
                'gross_margin_pct' => $rev > 0 ? round($gross / $rev * 100, 1) : null,
                'written_off' => $scope->money($waste[$lid] ?? 0.0),
                'distribution' => $scope->money($distribution),
                'people_and_admin' => $scope->money($shared),
                'owner_fixed_costs' => $scope->money($fixed),
                'profit' => $scope->money($profit),
                'profit_margin_pct' => $rev > 0 ? round($profit / $rev * 100, 1) : null,
                'profit_per_day' => $scope->money($profit / max(1, $days)),
            ];
        }
        usort($rows, fn ($a, $b) => $b['profit'] <=> $a['profit']);

        $block = Md::table(
            ['Shop', 'Revenue', 'Cost of goods', 'Gross profit', 'Written off', 'Distribution', 'People & admin', 'Fixed costs', 'Profit', 'Margin'],
            array_map(fn ($r) => [$r['location'], Md::money($r['revenue'], $scope), Md::money($r['cost_of_goods'], $scope), Md::money($r['gross_profit'], $scope), Md::money($r['written_off'], $scope), Md::money($r['distribution'], $scope), Md::money($r['people_and_admin'], $scope), Md::money($r['owner_fixed_costs'], $scope), Md::money($r['profit'], $scope), Md::pct($r['profit_margin_pct'], 1)], $rows),
            'Profit by shop, '.$start->format('j M').'–'.$end->format('j M Y')
        );

        return [
            'ok' => true,
            'range' => ['start' => $start->toDateString(), 'end' => $end->toDateString()],
            'locations' => $rows,
            'overheads' => $base['overhead_summary'],
            'owner_fixed_costs_entered' => $base['fixed_count'] > 0,
            'note' => 'Revenue = invoices minus sell returns. Cost of goods for baked products = factory cost (ingredients at latest prices + production expenses spread by ingredient cost); other products use default purchase price. Written off = stock adjustments at factory cost. Distribution expenses follow units received by transfer; people/admin expenses and business-wide fixed costs follow revenue share. This is a management estimate, not the accounting P&L. '.($base['fixed_count'] === 0 ? 'No rent or wages are entered in Eli settings and TeamPOS has no payroll, so real profit is lower.' : ''),
            'verbatim_block' => $block,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function base(Carbon $start, Carbon $end): array
    {
        $scope = $this->scope;
        $costing = new RecipeCosting($scope->businessId);
        $recipes = $costing->recipes();
        $ledger = (new StockLedger($scope))->build($start, $end, array_keys($recipes) ?: [0]);

        $products = [];
        $locationProduct = [];
        foreach ($ledger['days'] as $rows) {
            foreach ($rows as $key => $row) {
                $pid = $row['product_id'];
                if (! isset($recipes[$pid])) {
                    continue;
                }
                $p = &$products[$pid];
                $p ??= ['produced' => 0.0, 'sold' => 0.0, 'revenue' => 0.0];
                $p['produced'] += $row['produced'];
                $p['sold'] += $row['sold'];
                $p['revenue'] += $row['revenue'];
                unset($p);
                $lp = &$locationProduct[$key];
                $lp ??= ['location_id' => $row['location_id'], 'product_id' => $pid, 'sold' => 0.0, 'adjusted' => 0.0, 'transfer_in' => 0.0];
                $lp['sold'] += $row['sold'];
                $lp['adjusted'] += $row['adjusted'];
                $lp['transfer_in'] += $row['transfer_in'];
                unset($lp);
            }
        }

        $expenses = ['production' => 0.0, 'distribution' => 0.0, 'people' => 0.0, 'admin' => 0.0];
        $byCategory = [];
        $rows = DB::table('transactions as t')
            ->leftJoin('expense_categories as ec', 'ec.id', '=', 't.expense_category_id')
            ->where('t.business_id', $scope->businessId)
            ->where('t.type', 'expense')
            ->whereBetween('t.transaction_date', [$start->toDateTimeString(), $end->copy()->endOfDay()->toDateTimeString()])
            ->when($scope->locationIds !== null, fn ($q) => $q->whereIn('t.location_id', $scope->locationIds ?: [0]))
            ->groupBy('ec.name')
            ->selectRaw('COALESCE(ec.name, "Uncategorised") as name, SUM(t.final_total) as total')
            ->get();
        foreach ($rows as $r) {
            $group = 'admin';
            foreach (self::GROUPS as $g => $pattern) {
                if (preg_match($pattern, (string) $r->name)) {
                    $group = $g;
                    break;
                }
            }
            $expenses[$group] += (float) $r->total;
            $byCategory[$group][] = ['category' => (string) $r->name, 'amount' => $scope->money((float) $r->total)];
        }

        $days = $start->diffInDays($end) + 1;
        $store = new ManagerStore($scope->businessId);
        $fixed = $store->fixedCosts($start, $end);
        $fixedByLocation = [];
        $fixedShared = 0.0;
        foreach ($fixed as $f) {
            $amount = (float) $f['monthly_amount'] * $days / 30.4375;
            if ($f['location_id']) {
                $fixedByLocation[(int) $f['location_id']] = ($fixedByLocation[(int) $f['location_id']] ?? 0.0) + $amount;
            } else {
                $fixedShared += $amount;
            }
        }

        $ingredientSpend = 0.0;
        foreach ($products as $pid => $p) {
            $ingredientSpend += $p['produced'] * $recipes[$pid]['ingredient_cost_per_unit'];
        }
        $otherOverhead = $expenses['distribution'] + $expenses['people'] + $expenses['admin'] + array_sum($fixedByLocation) + $fixedShared;

        return [
            'recipes' => $recipes,
            'products' => $products,
            'location_product' => array_values($locationProduct),
            'expenses' => $expenses,
            'fixed_by_location' => $fixedByLocation,
            'fixed_shared' => $fixedShared,
            'fixed_count' => count($fixed),
            'production_per_ingredient_cedi' => $ingredientSpend > 0 ? $expenses['production'] / $ingredientSpend : 0.0,
            'other_per_ingredient_cedi' => $ingredientSpend > 0 ? $otherOverhead / $ingredientSpend : 0.0,
            'overhead_summary' => [
                'ingredient_cost_of_baked_units' => $scope->money($ingredientSpend),
                'production_expenses' => $scope->money($expenses['production']),
                'distribution_expenses' => $scope->money($expenses['distribution']),
                'people_expenses' => $scope->money($expenses['people']),
                'admin_expenses' => $scope->money($expenses['admin']),
                'owner_fixed_costs' => $scope->money(array_sum($fixedByLocation) + $fixedShared),
                'by_category' => $byCategory,
            ],
        ];
    }
}
