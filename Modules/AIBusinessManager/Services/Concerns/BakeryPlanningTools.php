<?php

namespace Modules\AIBusinessManager\Services\Concerns;

use App\Business;
use App\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Production and perishable-stock tools. Generic for any business that
 * manufactures (recipes) or sells through the register. Does not hardcode products.
 */
trait BakeryPlanningTools
{
    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected function openSells(array $args, int $businessId, User $user): array
    {
        $business = Business::with('currency')->find($businessId);
        if (! $business) {
            return ['ok' => false, 'error' => 'business_not_found'];
        }

        $locationIds = $this->planningLocationIds($args, $user);
        $precision = (int) ($business->currency_precision ?? 2);
        $symbol = $business->currency->symbol ?? '';

        $hasSuspend = Schema::hasColumn('transactions', 'is_suspend');
        $hasQuotation = Schema::hasColumn('transactions', 'is_quotation');

        $query = DB::table('transactions as t')
            ->leftJoin('business_locations as bl', 'bl.id', '=', 't.location_id')
            ->where('t.business_id', $businessId)
            ->where('t.type', 'sell')
            ->where(function ($q) use ($hasSuspend) {
                $q->where('t.status', '!=', 'final');
                if ($hasSuspend) {
                    $q->orWhere('t.is_suspend', 1);
                }
            })
            ->when($locationIds !== null, fn ($q) => $q->whereIn('t.location_id', $locationIds));

        $groups = ['t.status', 't.location_id', 'bl.name'];

        if ($hasSuspend) {
            $query->selectRaw('t.is_suspend as is_suspend');
            $groups[] = 't.is_suspend';
        }
        if ($hasQuotation) {
            $query->selectRaw('t.is_quotation as is_quotation');
            $groups[] = 't.is_quotation';
        }

        $rows = $query
            ->groupBy($groups)
            ->orderByDesc(DB::raw('SUM(t.final_total)'))
            ->selectRaw('t.status as status')
            ->selectRaw('bl.name as location_name')
            ->selectRaw('COUNT(*) as invoices')
            ->selectRaw('SUM(t.final_total) as value')
            ->limit(40)
            ->get();

        $mapped = $rows->map(function ($r) use ($precision, $hasSuspend, $hasQuotation) {
            $row = [
                'status' => (string) $r->status,
                'location_name' => (string) ($r->location_name ?? ''),
                'invoices' => (int) $r->invoices,
                'value' => round((float) $r->value, $precision),
            ];
            if ($hasSuspend) {
                $row['is_suspend'] = (int) $r->is_suspend === 1;
            }
            if ($hasQuotation) {
                $row['is_quotation'] = (int) $r->is_quotation === 1;
            }

            return $row;
        })->values()->all();

        return [
            'ok' => true,
            'currency_symbol' => $symbol,
            'invoice_count' => array_sum(array_column($mapped, 'invoices')),
            'value' => round(array_sum(array_column($mapped, 'value')), $precision),
            'note' => 'Open sells are drafts, quotations, or suspended tickets. They are not finalized revenue. Do not add this value to sales totals.',
            'rows' => $mapped,
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected function bakePlan(array $args, int $businessId, User $user): array
    {
        $demand = $this->sameWeekdayDemand($args, $businessId, $user);
        if (isset($demand['ok']) && $demand['ok'] === false) {
            return $demand;
        }

        $hasRecipes = $demand['recipes_on_file'] > 0;
        $rows = [];
        $withoutRecipe = [];
        foreach ($demand['products'] as $product) {
            $suggested = max(0, round($product['avg_qty'] - $product['on_hand'], 4));
            $line = [
                'product_name' => $product['product_name'],
                'unit' => $product['unit'],
                'avg_qty_sold' => $product['avg_qty'],
                'on_hand' => $product['on_hand'],
                'suggested_bake' => $suggested,
                'has_recipe' => $product['has_recipe'],
            ];
            if ($hasRecipes && ! $product['has_recipe']) {
                if ($product['avg_qty'] > 0) {
                    $withoutRecipe[] = $line;
                }
                continue;
            }
            if ($product['avg_qty'] <= 0 && $product['on_hand'] <= 0) {
                continue;
            }
            $rows[] = $line;
        }

        usort($rows, fn ($a, $b) => $b['suggested_bake'] <=> $a['suggested_bake']);
        $rows = array_slice($rows, 0, 30);
        $withoutRecipe = array_slice($withoutRecipe, 0, 15);

        return [
            'ok' => true,
            'target_date' => $demand['target_date'],
            'target_weekday' => $demand['target_weekday'],
            'history_dates' => $demand['history_dates'],
            'weeks' => $demand['weeks'],
            'note' => $hasRecipes
                ? 'suggested_bake = average quantity sold on the previous same weekdays, minus on-hand, floored at 0. Only products with a manufacturing recipe are bake targets. sold_without_recipe still sold, but has no recipe. This is a sales guide, not a confirmed order. on_hand is stock qty_available; avg_qty_sold is the invoice selling unit (they match when the unit multiplier is 1).'
                : 'No manufacturing recipes are on file, so this lists products by past same-weekday sales. suggested_bake = average sold minus on-hand, floored at 0. It is a sales guide, not a confirmed order.',
            'rows' => $rows,
            'sold_without_recipe' => $withoutRecipe,
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected function stockDaysOfCover(array $args, int $businessId, User $user): array
    {
        $demand = $this->sameWeekdayDemand($args, $businessId, $user);
        if (isset($demand['ok']) && $demand['ok'] === false) {
            return $demand;
        }

        $rows = [];
        $recipesOnFile = $demand['recipes_on_file'] > 0;
        foreach ($demand['products'] as $product) {
            if ($product['on_hand'] <= 0 && $product['avg_qty'] <= 0) {
                continue;
            }
            if ($recipesOnFile && ! $product['has_recipe'] && $product['avg_qty'] <= 0) {
                continue;
            }
            $cover = $product['avg_qty'] > 0
                ? round($product['on_hand'] / $product['avg_qty'], 2)
                : null;
            $rows[] = [
                'product_name' => $product['product_name'],
                'unit' => $product['unit'],
                'on_hand' => $product['on_hand'],
                'avg_qty_sold' => $product['avg_qty'],
                'days_of_cover' => $cover,
                'has_recipe' => $product['has_recipe'],
            ];
        }

        usort($rows, function ($a, $b) {
            $aNull = $a['days_of_cover'] === null;
            $bNull = $b['days_of_cover'] === null;
            if ($aNull !== $bNull) {
                return $aNull ? -1 : 1;
            }

            return ($b['days_of_cover'] ?? 0) <=> ($a['days_of_cover'] ?? 0);
        });

        return [
            'ok' => true,
            'target_date' => $demand['target_date'],
            'target_weekday' => $demand['target_weekday'],
            'history_dates' => $demand['history_dates'],
            'weeks' => $demand['weeks'],
            'note' => 'days_of_cover = on_hand / average quantity sold on this weekday over the history dates. Null means stock is on hand but none of those days had sales. Cover above 1 means current stock would last beyond one of these weekdays. If the merchant says goods are same-day perishable, cover above 1 is likely leftover. Do not assume perishability unless they said so. When recipes exist, unsold inputs (flour and similar, no sales and no recipe) are omitted so the list is finished goods and anything that actually sold.',
            'rows' => array_slice($rows, 0, 30),
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected function recipeUnitCost(array $args, int $businessId, User $user): array
    {
        if (! Schema::hasTable('mfg_recipes') || ! Schema::hasTable('mfg_recipe_ingredients')) {
            return ['ok' => false, 'error' => 'manufacturing_module_unavailable'];
        }

        $business = Business::with('currency')->find($businessId);
        if (! $business) {
            return ['ok' => false, 'error' => 'business_not_found'];
        }

        $precision = (int) ($business->currency_precision ?? 2);
        $symbol = $business->currency->symbol ?? '';
        $nameQuery = isset($args['name_query']) ? trim((string) $args['name_query']) : '';

        $sellCol = Schema::hasColumn('variations', 'sell_price_inc_tax') ? 'v.sell_price_inc_tax' : 'v.default_sell_price';
        $hasCostType = Schema::hasColumn('mfg_recipes', 'production_cost_type');

        $recipes = DB::table('mfg_recipes as r')
            ->join('products as p', 'p.id', '=', 'r.product_id')
            ->join('variations as v', 'v.id', '=', 'r.variation_id')
            ->leftJoin('units as yield_unit', 'yield_unit.id', '=', 'r.sub_unit_id')
            ->leftJoin('units as product_unit', 'product_unit.id', '=', 'p.unit_id')
            ->where('p.business_id', $businessId)
            ->when($nameQuery !== '', fn ($q) => $q->where('p.name', 'like', '%'.$nameQuery.'%'))
            ->orderBy('p.name')
            ->limit(40)
            ->select([
                'r.id as recipe_id',
                'p.name as product_name',
                'r.extra_cost',
                'r.total_quantity',
                DB::raw($sellCol.' as sell_price'),
                DB::raw('COALESCE(NULLIF(TRIM(yield_unit.short_name), ""), NULLIF(TRIM(product_unit.short_name), ""), "unit") as unit'),
            ])
            ->when($hasCostType, fn ($q) => $q->addSelect('r.production_cost_type'))
            ->get();

        if ($recipes->isEmpty()) {
            return [
                'ok' => true,
                'currency_symbol' => $symbol,
                'note' => 'No recipes matched.',
                'rows' => [],
            ];
        }

        $ingredients = DB::table('mfg_recipe_ingredients as ri')
            ->join('variations as iv', 'iv.id', '=', 'ri.variation_id')
            ->leftJoin('units as su', 'su.id', '=', 'ri.sub_unit_id')
            ->whereIn('ri.mfg_recipe_id', $recipes->pluck('recipe_id')->all())
            ->select([
                'ri.mfg_recipe_id',
                'ri.quantity',
                'iv.dpp_inc_tax',
                'su.base_unit_multiplier',
            ])
            ->get()
            ->groupBy('mfg_recipe_id');

        $rows = [];
        foreach ($recipes as $recipe) {
            $ingredientCost = 0.0;
            foreach ($ingredients->get($recipe->recipe_id, []) as $ingredient) {
                $line = (float) $ingredient->dpp_inc_tax * (float) $ingredient->quantity;
                $multiplier = (float) ($ingredient->base_unit_multiplier ?? 0);
                if ($multiplier > 0) {
                    $line *= $multiplier;
                }
                $ingredientCost += $line;
            }

            $extra = (float) $recipe->extra_cost;
            $yield = (float) $recipe->total_quantity;
            $costType = $hasCostType ? (string) ($recipe->production_cost_type ?? 'fixed') : 'fixed';
            if ($costType === 'percentage') {
                $productionCost = ($ingredientCost * $extra) / 100;
            } elseif ($costType === 'per_unit') {
                $productionCost = $extra * $yield;
            } else {
                $productionCost = $extra;
                $costType = 'fixed';
            }

            $batchCost = $ingredientCost + $productionCost;
            $unitCost = $yield > 0 ? $batchCost / $yield : null;
            $sell = $recipe->sell_price !== null ? round((float) $recipe->sell_price, $precision) : null;

            $rows[] = [
                'product_name' => (string) $recipe->product_name,
                'unit' => (string) $recipe->unit,
                'yield_quantity' => $this->roundQuantity($yield),
                'ingredient_cost' => round($ingredientCost, $precision),
                'production_cost' => round($productionCost, $precision),
                'production_cost_type' => $costType,
                'batch_cost' => round($batchCost, $precision),
                'unit_cost' => $unitCost !== null ? round($unitCost, $precision) : null,
                'default_sell_price' => $sell,
                'unit_margin' => ($unitCost !== null && $sell !== null) ? round($sell - $unitCost, $precision) : null,
            ];
        }

        return [
            'ok' => true,
            'currency_symbol' => $symbol,
            'note' => 'unit_cost follows the manufacturing recipe total: ingredient default purchase price (dpp_inc_tax) times recipe quantity times the sub-unit multiplier, plus recipe production cost (percentage of ingredients, per yield unit, or a fixed amount), divided by yield. It is not a full energy, labour, or overhead study. Ingredient waste percent is not included in this total.',
            'rows' => $rows,
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected function sameWeekdayDemand(array $args, int $businessId, User $user): array
    {
        $business = Business::find($businessId);
        if (! $business) {
            return ['ok' => false, 'error' => 'business_not_found'];
        }

        $tz = $business->time_zone ?: (string) config('app.timezone');
        try {
            $target = isset($args['target_date']) && is_string($args['target_date']) && $args['target_date'] !== ''
                ? Carbon::parse($args['target_date'], $tz)->startOfDay()
                : Carbon::now($tz)->addDay()->startOfDay();
        } catch (\Throwable) {
            return ['ok' => false, 'error' => 'invalid_date_format'];
        }

        $weeks = isset($args['weeks']) ? (int) $args['weeks'] : 4;
        $weeks = max(1, min(12, $weeks));

        $dates = [];
        for ($i = 1; $i <= $weeks; $i++) {
            $dates[] = $target->copy()->subWeeks($i)->toDateString();
        }

        $locationIds = $this->planningLocationIds($args, $user);
        $start = Carbon::parse(min($dates))->startOfDay();
        $end = Carbon::parse(max($dates))->endOfDay();
        $unitExpr = 'COALESCE(NULLIF(TRIM(sell_unit.short_name), ""), NULLIF(TRIM(base_u.short_name), ""), "unit")';

        $sales = $this->sellLinesInRangeQuery($businessId, $locationIds, $start, $end)
            ->join('products as p', 'p.id', '=', 'tsl.product_id')
            ->leftJoin('units as base_u', 'base_u.id', '=', 'p.unit_id')
            ->whereIn(DB::raw('DATE(t.transaction_date)'), $dates)
            ->groupBy('p.id', 'p.name')
            ->selectRaw('p.id as product_id')
            ->selectRaw('p.name as product_name')
            ->selectRaw('SUM('.$this->qtySellingUomSql().') as quantity')
            ->selectRaw('SUBSTRING_INDEX(GROUP_CONCAT(DISTINCT '.$unitExpr.' ORDER BY '.$unitExpr.' SEPARATOR "|"), "|", 1) as unit')
            ->get()
            ->keyBy(fn ($row) => (int) $row->product_id);

        $onHand = DB::table('variation_location_details as vld')
            ->join('variations as v', 'v.id', '=', 'vld.variation_id')
            ->join('products as p', 'p.id', '=', 'v.product_id')
            ->where('p.business_id', $businessId)
            ->when($locationIds !== null, fn ($q) => $q->whereIn('vld.location_id', $locationIds))
            ->groupBy('p.id')
            ->selectRaw('p.id as product_id')
            ->selectRaw('SUM(vld.qty_available) as on_hand')
            ->pluck('on_hand', 'product_id')
            ->mapWithKeys(fn ($qty, $id) => [(int) $id => (float) $qty]);

        $recipeIds = [];
        if (Schema::hasTable('mfg_recipes')) {
            $recipeIds = DB::table('mfg_recipes as r')
                ->join('products as p', 'p.id', '=', 'r.product_id')
                ->where('p.business_id', $businessId)
                ->pluck('p.id')
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->all();
        }
        $recipeLookup = array_fill_keys($recipeIds, true);

        $names = DB::table('products')
            ->where('business_id', $businessId)
            ->whereIn('id', array_values(array_unique(array_merge(
                $sales->keys()->map(fn ($id) => (int) $id)->all(),
                array_map('intval', $onHand->keys()->all()),
                $recipeIds
            ))))
            ->pluck('name', 'id');

        $ids = array_unique(array_merge(
            $sales->keys()->map(fn ($id) => (int) $id)->all(),
            array_map('intval', $onHand->keys()->all())
        ));

        $products = [];
        foreach ($ids as $id) {
            $sold = $sales->get($id);
            $avg = $sold ? ((float) $sold->quantity) / $weeks : 0.0;
            $hand = (float) ($onHand[$id] ?? 0);
            if ($avg <= 0 && $hand <= 0) {
                continue;
            }
            $products[] = [
                'product_id' => $id,
                'product_name' => (string) ($sold->product_name ?? $names[$id] ?? ('Product #'.$id)),
                'unit' => $sold ? (string) ($sold->unit ?: 'unit') : 'unit',
                'avg_qty' => $this->roundQuantity($avg),
                'on_hand' => $this->roundQuantity($hand),
                'has_recipe' => isset($recipeLookup[$id]),
            ];
        }

        return [
            'ok' => true,
            'target_date' => $target->toDateString(),
            'target_weekday' => $target->englishDayOfWeek,
            'history_dates' => $dates,
            'weeks' => $weeks,
            'recipes_on_file' => count($recipeIds),
            'products' => $products,
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<int>|null
     */
    protected function planningLocationIds(array $args, User $user): ?array
    {
        $permitted = $user->permitted_locations();
        $locationIds = $permitted === 'all' ? null : (is_array($permitted) ? $permitted : []);
        if (isset($args['location_id']) && is_numeric($args['location_id'])) {
            $lid = (int) $args['location_id'];
            if ($permitted === 'all' || (is_array($permitted) && in_array($lid, $permitted, true))) {
                return [$lid];
            }
        }

        return $locationIds;
    }
}
