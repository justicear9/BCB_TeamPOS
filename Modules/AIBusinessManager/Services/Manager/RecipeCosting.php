<?php

namespace Modules\AIBusinessManager\Services\Manager;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Manufacturing recipes as cost and ingredient-use per finished unit.
 * Ingredient prices use the latest received purchase price, falling back to the default purchase price.
 */
class RecipeCosting
{
    /** @var array<int, array<string, mixed>>|null */
    private ?array $recipes = null;

    public function __construct(private int $businessId)
    {
    }

    public function available(): bool
    {
        return Schema::hasTable('mfg_recipes') && Schema::hasTable('mfg_recipe_ingredients');
    }

    /**
     * @return array<int, array<string, mixed>> finished product_id => recipe
     */
    public function recipes(): array
    {
        if ($this->recipes !== null) {
            return $this->recipes;
        }
        if (! $this->available()) {
            return $this->recipes = [];
        }

        $recipes = DB::table('mfg_recipes as r')
            ->join('products as p', 'p.id', '=', 'r.product_id')
            ->leftJoin('units as yu', 'yu.id', '=', 'r.sub_unit_id')
            ->where('p.business_id', $this->businessId)
            ->select([
                'r.id', 'r.product_id', 'p.name', 'r.total_quantity', 'r.extra_cost', 'r.production_cost_type', 'r.waste_percent',
                DB::raw('COALESCE(yu.base_unit_multiplier, 1) as yield_multiplier'),
            ])
            ->get();
        if ($recipes->isEmpty()) {
            return $this->recipes = [];
        }

        $ingredients = DB::table('mfg_recipe_ingredients as ri')
            ->join('variations as v', 'v.id', '=', 'ri.variation_id')
            ->join('products as p', 'p.id', '=', 'v.product_id')
            ->leftJoin('units as su', 'su.id', '=', 'ri.sub_unit_id')
            ->leftJoin('units as bu', 'bu.id', '=', 'p.unit_id')
            ->whereIn('ri.mfg_recipe_id', $recipes->pluck('id')->all())
            ->select([
                'ri.mfg_recipe_id', 'ri.variation_id', 'p.id as product_id', 'p.name', 'ri.quantity', 'ri.waste_percent', 'v.dpp_inc_tax',
                DB::raw('COALESCE(su.base_unit_multiplier, 1) as multiplier'),
                DB::raw('COALESCE(bu.short_name, "unit") as unit'),
            ])
            ->get()
            ->groupBy('mfg_recipe_id');

        $variationIds = $ingredients->flatten(1)->pluck('variation_id')->unique()->values()->all();
        $lastPrice = $this->lastPurchasePrices($variationIds);

        $out = [];
        foreach ($recipes as $r) {
            $yield = (float) $r->total_quantity * (float) $r->yield_multiplier;
            if ($yield <= 0) {
                continue;
            }
            $lines = [];
            $ingredientCost = 0.0;
            foreach ($ingredients->get($r->id, []) as $i) {
                $baseQty = (float) $i->quantity * (float) $i->multiplier * (1 + (float) $i->waste_percent / 100);
                $price = $lastPrice[(int) $i->variation_id] ?? (float) $i->dpp_inc_tax;
                $ingredientCost += $baseQty * $price;
                $lines[] = [
                    'product_id' => (int) $i->product_id,
                    'variation_id' => (int) $i->variation_id,
                    'name' => (string) $i->name,
                    'unit' => (string) $i->unit,
                    'qty_per_batch' => $baseQty,
                    'qty_per_unit' => $baseQty / $yield,
                    'unit_price' => $price,
                    'price_source' => isset($lastPrice[(int) $i->variation_id]) ? 'last_purchase' : 'default_purchase_price',
                ];
            }
            $extra = (float) $r->extra_cost;
            $type = (string) ($r->production_cost_type ?: 'fixed');
            $productionCost = match ($type) {
                'percentage' => $ingredientCost * $extra / 100,
                'per_unit' => $extra * $yield,
                default => $extra,
            };
            $out[(int) $r->product_id] = [
                'recipe_id' => (int) $r->id,
                'product_id' => (int) $r->product_id,
                'name' => (string) $r->name,
                'yield' => $yield,
                'ingredients' => $lines,
                'ingredient_cost_per_unit' => $ingredientCost / $yield,
                'production_cost_per_unit' => $productionCost / $yield,
                'unit_cost' => ($ingredientCost + $productionCost) / $yield,
            ];
        }

        return $this->recipes = $out;
    }

    public function unitCost(int $productId): ?float
    {
        return $this->recipes()[$productId]['unit_cost'] ?? null;
    }

    /**
     * @param  list<int>  $variationIds
     * @return array<int, float>
     */
    private function lastPurchasePrices(array $variationIds): array
    {
        if ($variationIds === []) {
            return [];
        }
        $rows = DB::table('purchase_lines as pl')
            ->join('transactions as t', 't.id', '=', 'pl.transaction_id')
            ->where('t.business_id', $this->businessId)
            ->where('t.type', 'purchase')
            ->where('t.status', 'received')
            ->whereIn('pl.variation_id', $variationIds)
            ->where('pl.purchase_price_inc_tax', '>', 0)
            ->orderBy('t.transaction_date')
            ->select(['pl.variation_id', 'pl.purchase_price_inc_tax'])
            ->get();
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r->variation_id] = (float) $r->purchase_price_inc_tax;
        }

        return $out;
    }
}
