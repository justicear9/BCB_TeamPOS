<?php

namespace Modules\AIBusinessManager\Services\Concerns;

use App\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\AIBusinessManager\Support\ProductLocationMetricsMath;

/**
 * CEO-style product × location averages and branch comparisons.
 */
trait ProductLocationMetricsTool
{
    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected function productLocationMetrics(array $args, int $businessId, User $user): array
    {
        $avgBasis = strtolower((string) ($args['avg_basis'] ?? 'calendar_day'));
        if (! in_array($avgBasis, ['calendar_day', 'selling_day'], true)) {
            return ['ok' => false, 'error' => 'invalid_avg_basis', 'allowed' => ['calendar_day', 'selling_day']];
        }

        $comparePrior = filter_var($args['compare_prior_period'] ?? true, FILTER_VALIDATE_BOOLEAN);
        $includeAllLocations = filter_var($args['include_all_locations'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $range = $this->parseDateRangeOrYearToDate($args, $businessId, $user);
        if (isset($range['ok']) && $range['ok'] === false) {
            return $range;
        }

        /** @var Carbon $start */
        $start = $range['start'];
        /** @var Carbon $end */
        $end = $range['end'];
        $permittedLocationIds = $range['location_ids'];
        $precision = $range['precision'];
        $symbol = $range['symbol'];

        $locationResolution = $this->resolveMetricsLocation($args, $businessId, $permittedLocationIds, $includeAllLocations);
        if (isset($locationResolution['ok']) && $locationResolution['ok'] === false) {
            return $locationResolution;
        }
        if (! empty($locationResolution['ambiguous'])) {
            return $locationResolution;
        }

        /** @var ?int $focusLocationId */
        $focusLocationId = $locationResolution['location_id'];
        /** @var ?string $focusLocationName */
        $focusLocationName = $locationResolution['location_name'];
        /** @var list<int>|null $queryLocationIds */
        $queryLocationIds = $locationResolution['query_location_ids'];

        $productResolution = $this->resolveMetricsProducts($args, $businessId);
        if (isset($productResolution['ok']) && $productResolution['ok'] === false) {
            return $productResolution;
        }
        if (! empty($productResolution['ambiguous'])) {
            return $productResolution;
        }
        if (isset($productResolution['products']) && $productResolution['products'] === []) {
            return [
                'ok' => true,
                'ambiguous' => false,
                'products' => [],
                'total_quantity' => 0,
                'avg_quantity' => null,
                'note' => (string) ($productResolution['note'] ?? 'No product matched.'),
            ];
        }

        /** @var list<array{product_id: int, product_name: string}> $products */
        $products = $productResolution['products'];
        $productIds = array_map(fn ($p) => $p['product_id'], $products);

        $scopeLocationIds = $queryLocationIds;
        if ($focusLocationId !== null) {
            $scopeLocationIds = [$focusLocationId];
        }

        $primary = $this->aggregateProductLocationSales(
            $businessId,
            $productIds,
            $scopeLocationIds,
            $start,
            $end
        );

        if ($primary['unit_conflict']) {
            return [
                'ok' => false,
                'error' => 'mixed_units',
                'products' => $products,
                'units_seen' => $primary['units_seen'],
                'note' => 'Matched products use different selling units. Pick one product_id, or narrow name_query / category_query. Do not combine totals across different units.',
            ];
        }

        $daysInRange = ProductLocationMetricsMath::daysInRangeInclusive($start, $end);
        $sellingDays = $primary['selling_days'];
        $totalQty = $primary['total_quantity'];
        $totalRev = $primary['total_revenue'];
        $avgQty = ProductLocationMetricsMath::averageQuantity($totalQty, $daysInRange, $sellingDays, $avgBasis);
        $avgRev = ProductLocationMetricsMath::averageQuantity($totalRev, $daysInRange, $sellingDays, $avgBasis);

        $result = [
            'ok' => true,
            'ambiguous' => false,
            'currency_symbol' => $symbol,
            'unit' => $primary['unit'],
            'avg_basis' => $avgBasis,
            'start' => $start->toDateString(),
            'end' => $end->toDateString(),
            'days_in_range' => $daysInRange,
            'selling_days' => $sellingDays,
            'total_quantity' => $this->roundQuantity($totalQty),
            'total_revenue' => round($totalRev, $precision),
            'avg_quantity' => $avgQty,
            'avg_revenue' => $avgRev !== null ? round($avgRev, $precision) : null,
            'products' => $products,
            'product_id' => count($products) === 1 ? $products[0]['product_id'] : null,
            'product_name' => count($products) === 1
                ? $products[0]['product_name']
                : (count($products).' products (same unit)'),
            'location_id' => $focusLocationId,
            'location_name' => $focusLocationName,
            'note' => $avgBasis === 'selling_day'
                ? 'avg_quantity = total_quantity / selling_days (days with ≥1 finalized sale of these products in scope). Quote avg_quantity, unit, location, and date range.'
                : 'avg_quantity = total_quantity / days_in_range (inclusive calendar days). Quote avg_quantity, unit, location, and date range. Use avg_basis selling_day if the merchant means average on days that sold.',
            'caveat' => 'Quantity is invoice selling UoM (TeamPOS sub-unit). Guidance only — not a bake/order confirmation.',
        ];

        if ($comparePrior) {
            [$priorStart, $priorEnd] = ProductLocationMetricsMath::priorPeriod($start, $end);
            $prior = $this->aggregateProductLocationSales(
                $businessId,
                $productIds,
                $scopeLocationIds,
                $priorStart,
                $priorEnd
            );
            $priorDays = ProductLocationMetricsMath::daysInRangeInclusive($priorStart, $priorEnd);
            $priorAvg = ProductLocationMetricsMath::averageQuantity(
                $prior['total_quantity'],
                $priorDays,
                $prior['selling_days'],
                $avgBasis
            );
            $result['prior'] = [
                'start' => $priorStart->toDateString(),
                'end' => $priorEnd->toDateString(),
                'days_in_range' => $priorDays,
                'selling_days' => $prior['selling_days'],
                'total_quantity' => $this->roundQuantity($prior['total_quantity']),
                'avg_quantity' => $priorAvg,
                'qty_delta_pct' => ProductLocationMetricsMath::qtyDeltaPct($totalQty, $prior['total_quantity']),
            ];
        }

        $wantSiblings = $includeAllLocations || $focusLocationId !== null;
        if ($wantSiblings) {
            $siblingScope = $permittedLocationIds;
            $locations = $this->visibleLocations($businessId, $siblingScope);
            $siblings = [];
            foreach ($locations as $loc) {
                $lid = (int) $loc->id;
                $agg = $this->aggregateProductLocationSales(
                    $businessId,
                    $productIds,
                    [$lid],
                    $start,
                    $end
                );
                $siblings[] = [
                    'location_id' => $lid,
                    'location_name' => (string) $loc->name,
                    'total_quantity' => $this->roundQuantity($agg['total_quantity']),
                    'avg_quantity' => ProductLocationMetricsMath::averageQuantity(
                        $agg['total_quantity'],
                        $daysInRange,
                        $agg['selling_days'],
                        $avgBasis
                    ),
                    'selling_days' => $agg['selling_days'],
                    'is_focus' => $focusLocationId !== null && $lid === $focusLocationId,
                ];
            }
            usort($siblings, fn ($a, $b) => ($b['avg_quantity'] ?? -1) <=> ($a['avg_quantity'] ?? -1));
            $result['siblings'] = $siblings;
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $args
     * @param  list<int>|null  $permittedLocationIds
     * @return array<string, mixed>
     */
    protected function resolveMetricsLocation(array $args, int $businessId, ?array $permittedLocationIds, bool $includeAllLocations): array
    {
        $locationIdArg = isset($args['location_id']) && is_numeric($args['location_id'])
            ? (int) $args['location_id']
            : 0;
        $locationName = isset($args['location_name']) ? trim((string) $args['location_name']) : '';

        $visible = $this->visibleLocations($businessId, $permittedLocationIds);
        $byId = [];
        foreach ($visible as $loc) {
            $byId[(int) $loc->id] = (string) $loc->name;
        }

        if ($locationIdArg > 0) {
            if (! isset($byId[$locationIdArg])) {
                return [
                    'ok' => false,
                    'error' => 'location_forbidden_or_unknown',
                    'note' => 'location_id is not among locations this user can see.',
                ];
            }

            return [
                'location_id' => $locationIdArg,
                'location_name' => $byId[$locationIdArg],
                'query_location_ids' => [$locationIdArg],
            ];
        }

        if ($locationName !== '') {
            $like = mb_strtolower($locationName);
            $matches = [];
            foreach ($byId as $id => $name) {
                if (str_contains(mb_strtolower($name), $like)) {
                    $matches[] = ['location_id' => $id, 'location_name' => $name];
                }
            }
            if ($matches === []) {
                return [
                    'ok' => false,
                    'error' => 'location_not_found',
                    'note' => 'No permitted location matched that name.',
                ];
            }
            if (count($matches) > 1) {
                return [
                    'ok' => true,
                    'ambiguous' => true,
                    'ambiguous_type' => 'location',
                    'matches' => $matches,
                    'note' => 'Several locations matched location_name. Call again with location_id.',
                ];
            }

            return [
                'location_id' => $matches[0]['location_id'],
                'location_name' => $matches[0]['location_name'],
                'query_location_ids' => [$matches[0]['location_id']],
            ];
        }

        if ($includeAllLocations) {
            return [
                'location_id' => null,
                'location_name' => null,
                'query_location_ids' => $permittedLocationIds,
            ];
        }

        return [
            'location_id' => null,
            'location_name' => null,
            'query_location_ids' => $permittedLocationIds,
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected function resolveMetricsProducts(array $args, int $businessId): array
    {
        $productIdArg = isset($args['product_id']) ? (int) $args['product_id'] : 0;
        $nameQuery = isset($args['name_query']) ? trim((string) $args['name_query']) : '';
        $categoryQuery = isset($args['category_query']) ? trim((string) $args['category_query']) : '';

        if ($productIdArg <= 0 && $nameQuery === '' && $categoryQuery === '') {
            return [
                'ok' => false,
                'error' => 'missing_product_id_or_name_query_or_category_query',
            ];
        }

        if ($productIdArg > 0) {
            $row = DB::table('products')
                ->where('business_id', $businessId)
                ->where('id', $productIdArg)
                ->select('id', 'name')
                ->first();
            if (! $row) {
                return ['ok' => false, 'error' => 'product_not_found_for_business'];
            }

            return [
                'products' => [[
                    'product_id' => (int) $row->id,
                    'product_name' => (string) $row->name,
                ]],
            ];
        }

        $maxMatches = max(1, min(25, (int) config('aibusinessmanager.tool_product_metrics_match_limit', 15)));

        $query = DB::table('products as p')
            ->leftJoin('variations as v', function ($join) {
                $join->on('v.product_id', '=', 'p.id')->whereNull('v.deleted_at');
            })
            ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
            ->where('p.business_id', $businessId);

        if ($nameQuery !== '') {
            if (mb_strlen($nameQuery) < 2) {
                return ['ok' => false, 'error' => 'name_query_too_short'];
            }
            $like = '%'.addcslashes($nameQuery, '%_\\').'%';
            $query->where(function ($w) use ($like) {
                $w->where('p.name', 'like', $like)
                    ->orWhere('v.name', 'like', $like)
                    ->orWhere('v.sub_sku', 'like', $like);
            });
        }

        if ($categoryQuery !== '') {
            if (mb_strlen($categoryQuery) < 2) {
                return ['ok' => false, 'error' => 'category_query_too_short'];
            }
            $catLike = '%'.addcslashes($categoryQuery, '%_\\').'%';
            $query->where('c.name', 'like', $catLike);
        }

        $matches = $query
            ->groupBy('p.id', 'p.name')
            ->orderBy('p.name')
            ->limit($maxMatches + 1)
            ->selectRaw('p.id as product_id')
            ->selectRaw('p.name as product_name')
            ->get();

        if ($matches->isEmpty()) {
            return [
                'products' => [],
                'note' => 'No product matched. Try a shorter name_query, category_query, or pass product_id.',
            ];
        }

        $allowCombine = filter_var($args['combine_matching_products'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $maxCombine = max(1, min(20, (int) config('aibusinessmanager.tool_product_metrics_combine_limit', 8)));

        if ($matches->count() > 1 && ! $allowCombine) {
            return [
                'ok' => true,
                'ambiguous' => true,
                'ambiguous_type' => 'product',
                'matches' => $matches->take($maxMatches)->map(fn ($m) => [
                    'product_id' => (int) $m->product_id,
                    'product_name' => (string) $m->product_name,
                ])->values()->all(),
                'note' => 'Several products matched. Call again with product_id, or set combine_matching_products true when they share one selling unit (e.g. all loaves).',
            ];
        }

        if ($matches->count() > $maxCombine && $allowCombine) {
            return [
                'ok' => true,
                'ambiguous' => true,
                'ambiguous_type' => 'product',
                'matches' => $matches->take($maxMatches)->map(fn ($m) => [
                    'product_id' => (int) $m->product_id,
                    'product_name' => (string) $m->product_name,
                ])->values()->all(),
                'note' => 'Too many products to combine safely. Narrow name_query / category_query or pick product_id.',
            ];
        }

        return [
            'products' => $matches->take($maxCombine)->map(fn ($m) => [
                'product_id' => (int) $m->product_id,
                'product_name' => (string) $m->product_name,
            ])->values()->all(),
        ];
    }

    /**
     * @param  list<int>  $productIds
     * @param  list<int>|null  $locationIds
     * @return array{total_quantity: float, total_revenue: float, selling_days: int, unit: string, units_seen: list<string>, unit_conflict: bool}
     */
    protected function aggregateProductLocationSales(
        int $businessId,
        array $productIds,
        ?array $locationIds,
        Carbon $start,
        Carbon $end
    ): array {
        if ($productIds === []) {
            return [
                'total_quantity' => 0.0,
                'total_revenue' => 0.0,
                'selling_days' => 0,
                'unit' => 'unit',
                'units_seen' => [],
                'unit_conflict' => false,
            ];
        }

        $qtySql = $this->qtySellingUomSql();
        $unitExpr = 'COALESCE(NULLIF(TRIM(sell_unit.short_name), ""), NULLIF(TRIM(base_u.short_name), ""), "unit")';
        $lineValue = 'tsl.quantity * COALESCE(tsl.unit_price_inc_tax, tsl.unit_price, 0)';

        $totals = $this->sellLinesInRangeQuery($businessId, $locationIds, $start, $end)
            ->join('products as p', 'p.id', '=', 'tsl.product_id')
            ->leftJoin('units as base_u', 'base_u.id', '=', 'p.unit_id')
            ->whereIn('tsl.product_id', $productIds)
            ->selectRaw('SUM('.$qtySql.') as quantity')
            ->selectRaw('SUM('.$lineValue.') as revenue')
            ->selectRaw('COUNT(DISTINCT DATE(t.transaction_date)) as selling_days')
            ->first();

        $unitRows = $this->sellLinesInRangeQuery($businessId, $locationIds, $start, $end)
            ->join('products as p', 'p.id', '=', 'tsl.product_id')
            ->leftJoin('units as base_u', 'base_u.id', '=', 'p.unit_id')
            ->whereIn('tsl.product_id', $productIds)
            ->groupBy(DB::raw($unitExpr))
            ->selectRaw($unitExpr.' as unit')
            ->pluck('unit')
            ->map(fn ($u) => (string) ($u ?: 'unit'))
            ->values()
            ->all();

        if ($unitRows === []) {
            $catalogUnits = DB::table('products as p')
                ->leftJoin('units as base_u', 'base_u.id', '=', 'p.unit_id')
                ->where('p.business_id', $businessId)
                ->whereIn('p.id', $productIds)
                ->selectRaw('COALESCE(NULLIF(TRIM(base_u.short_name), ""), "unit") as unit')
                ->pluck('unit')
                ->map(fn ($u) => (string) ($u ?: 'unit'))
                ->unique()
                ->values()
                ->all();
            $unitRows = $catalogUnits !== [] ? $catalogUnits : ['unit'];
        }

        $conflict = ! ProductLocationMetricsMath::unitsAreCompatible($unitRows);

        return [
            'total_quantity' => (float) ($totals->quantity ?? 0),
            'total_revenue' => (float) ($totals->revenue ?? 0),
            'selling_days' => (int) ($totals->selling_days ?? 0),
            'unit' => (string) ($unitRows[0] ?? 'unit'),
            'units_seen' => array_values(array_unique($unitRows)),
            'unit_conflict' => $conflict,
        ];
    }
}
