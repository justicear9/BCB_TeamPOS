<?php

namespace Modules\AIBusinessManager\Services\Manager;

use Illuminate\Support\Facades\DB;

/**
 * Record-keeping problems that make Eli's numbers (and TeamPOS reports) less reliable.
 */
class DataQuality
{
    public function __construct(private ManagerScope $scope)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function audit(int $days = 90): array
    {
        $scope = $this->scope;
        $since = $scope->today()->subDays($days)->toDateTimeString();
        $locIds = $scope->locationIds;
        $tx = fn () => DB::table('transactions as t')
            ->where('t.business_id', $scope->businessId)
            ->when($locIds !== null, fn ($q) => $q->whereIn('t.location_id', $locIds ?: [0]));
        $checks = [];
        $add = function (string $code, string $severity, string $title, int $count, string $fix, array $examples = []) use (&$checks) {
            if ($count > 0) {
                $checks[] = compact('code', 'severity', 'title', 'count', 'fix', 'examples');
            }
        };

        $negative = DB::table('variation_location_details as vld')
            ->join('products as p', 'p.id', '=', 'vld.product_id')
            ->where('p.business_id', $scope->businessId)
            ->where('p.enable_stock', 1)
            ->where('vld.qty_available', '<', -0.0001)
            ->when($locIds !== null, fn ($q) => $q->whereIn('vld.location_id', $locIds ?: [0]))
            ->select(['p.name', 'vld.location_id', 'vld.qty_available'])
            ->orderBy('vld.qty_available')
            ->get();
        $add('negative_stock', 'high', 'Products with negative stock', $negative->count(), 'Record the missing transfer or production, or count the stock and adjust.',
            $negative->take(8)->map(fn ($r) => $r->name.' @ '.($scope->locationNames[(int) $r->location_id] ?? '#'.$r->location_id).': '.round((float) $r->qty_available, 2))->all());

        $openRegisters = DB::table('cash_registers')
            ->where('business_id', $scope->businessId)
            ->where('status', 'open')
            ->where('created_at', '<', $scope->now()->subHours(20)->toDateTimeString())
            ->when($locIds !== null, fn ($q) => $q->whereIn('location_id', $locIds ?: [0]))
            ->get(['id', 'location_id', 'created_at']);
        $add('stale_registers', 'high', 'Cash registers open more than 20 hours', $openRegisters->count(), 'Close these registers with a counted cash amount.',
            $openRegisters->take(8)->map(fn ($r) => '#'.$r->id.' '.($scope->locationNames[(int) $r->location_id] ?? '').' since '.$r->created_at)->all());

        $noNote = $tx()->where('t.type', 'stock_adjustment')->where('t.transaction_date', '>=', $since)
            ->where(fn ($q) => $q->whereNull('t.additional_notes')->orWhere('t.additional_notes', ''))->count();
        $add('adjustment_without_reason', 'medium', 'Stock adjustments without a reason note', $noNote, 'Always note why stock was written off (expired, burnt, count correction) so waste can be analysed.');

        $draftSales = $tx()->where('t.type', 'sell')->where('t.status', 'draft')->where('t.transaction_date', '<', $scope->now()->subDays(7)->toDateTimeString())->count();
        $add('old_draft_sales', 'low', 'Draft sales older than 7 days', $draftSales, 'Finalise or delete old drafts; they are not counted as sales.');

        $draftProduction = $tx()->where('t.type', 'production_sell')->where('t.status', 'draft')->count()
            + $tx()->where('t.type', 'production_purchase')->where('t.status', '<>', 'received')->count();
        $add('unfinished_production', 'medium', 'Unfinished production entries (draft ingredients or pending output)', $draftProduction, 'Finalise or remove them in Manufacturing > Production so stock and costs are right.');

        $lateNight = $tx()->where('t.type', 'sell')->where('t.status', 'final')->where('t.transaction_date', '>=', $since)
            ->whereRaw('HOUR(t.transaction_date) < 4')->count();
        $add('after_midnight_sales', 'low', 'Sales dated between midnight and 4am', $lateNight, 'These usually belong to the previous trading day. Enter the day\'s sales before midnight or set the sale date.');

        $zeroPrice = DB::table('transaction_sell_lines as tsl')
            ->join('transactions as t', 't.id', '=', 'tsl.transaction_id')
            ->where('t.business_id', $scope->businessId)
            ->where('t.type', 'sell')->where('t.status', 'final')
            ->where('t.transaction_date', '>=', $since)
            ->when($locIds !== null, fn ($q) => $q->whereIn('t.location_id', $locIds ?: [0]))
            ->where('tsl.unit_price_inc_tax', '<=', 0)
            ->whereNull('tsl.parent_sell_line_id')
            ->count();
        $add('zero_price_lines', 'medium', 'Sale lines sold at zero price', $zeroPrice, 'Check if these were free giveaways; record them as a discount so the reason is visible.');

        $costing = new RecipeCosting($scope->businessId);
        $noCost = [];
        foreach ($costing->recipes() as $recipe) {
            foreach ($recipe['ingredients'] as $i) {
                if ($i['unit_price'] <= 0) {
                    $noCost[$i['name']] = true;
                }
            }
            if ($recipe['ingredients'] === []) {
                $noCost[$recipe['name'].' (recipe has no ingredients)'] = true;
            }
        }
        $add('ingredient_without_cost', 'high', 'Recipe ingredients with no purchase price', count($noCost), 'Record purchases (or set the default purchase price) so bread costs are right.', array_slice(array_keys($noCost), 0, 8));

        $noCategory = DB::table('transactions as t')
            ->where('t.business_id', $scope->businessId)->where('t.type', 'expense')
            ->where('t.transaction_date', '>=', $since)
            ->whereNull('t.expense_category_id')->count();
        $add('expense_without_category', 'medium', 'Expenses without a category', $noCategory, 'Categorise expenses so costs can be split between production, delivery and admin.');

        $future = $tx()->where('t.transaction_date', '>', $scope->now()->addDay()->toDateTimeString())->count();
        $add('future_dated', 'high', 'Records dated in the future', $future, 'Fix the transaction dates.');

        $duplicates = $tx()->where('t.type', 'sell')->where('t.status', 'final')->where('t.transaction_date', '>=', $since)
            ->groupBy('t.location_id', 't.invoice_no')->havingRaw('COUNT(*) > 1')->select('t.invoice_no')->get()->count();
        $add('duplicate_invoice_numbers', 'medium', 'Duplicate invoice numbers', $duplicates, 'Check invoice schemes; duplicates make audits harder.');

        $ledger = new StockLedger($scope);
        $data = $ledger->build($scope->today()->subDays(28), $scope->today(), null, true);
        $batch = array_values(array_map(fn ($id) => $scope->locationNames[$id] ?? ('#'.$id), array_keys(array_filter($data['live_entry'], fn ($live) => ! $live))));
        $add('batch_sales_entry', 'medium', 'Shops that enter the day\'s sales at night instead of at the till', count($batch), 'Ring up sales as they happen. Then Eli can see what time bread sells out and how much demand was missed.', $batch);

        $weights = ['high' => 0, 'medium' => 1, 'low' => 2];
        usort($checks, fn ($a, $b) => [$weights[$a['severity']], -$a['count']] <=> [$weights[$b['severity']], -$b['count']]);
        $score = max(0, 100 - array_sum(array_map(fn ($c) => ['high' => 15, 'medium' => 7, 'low' => 3][$c['severity']], $checks)));

        return [
            'ok' => true,
            'period_days' => $days,
            'score' => $score,
            'checks' => $checks,
            'note' => 'Score starts at 100 and drops per problem found (high 15, medium 7, low 3). Fixing these makes stock, costs and forecasts more accurate.',
            'verbatim_block' => Md::table(
                ['Severity', 'Problem', 'Count', 'Examples', 'Fix'],
                array_map(fn ($c) => [$c['severity'], $c['title'], $c['count'], implode('; ', $c['examples']) ?: '—', $c['fix']], $checks),
                'Data quality check (score '.$score.'/100)'
            ),
        ];
    }
}
