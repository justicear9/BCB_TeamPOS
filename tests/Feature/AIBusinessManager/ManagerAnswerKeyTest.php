<?php

namespace Tests\Feature\AIBusinessManager;

use App\User;
use Illuminate\Support\Facades\DB;
use Modules\AIBusinessManager\Services\BusinessDataToolService;
use Tests\TestCase;

/**
 * Answer keys against the BreadCity data (business 2) in the local database: each tool figure is checked
 * against an independent SQL query. Skipped when that data is not present.
 */
class ManagerAnswerKeyTest extends TestCase
{
    private const BUSINESS = 2;

    private const START = '2026-09-01';

    private const END = '2026-09-27';

    private ?User $user = null;

    protected function setUp(): void
    {
        parent::setUp();
        try {
            $this->user = User::where('username', 'eunice')->first();
        } catch (\Throwable) {
            $this->user = null;
        }
        if (! $this->user || ! DB::table('mfg_recipes')->exists()) {
            $this->markTestSkipped('BreadCity data not available.');
        }
        config(['aibusinessmanager.save_forecasts' => false]);
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function tool(string $name, array $args = []): array
    {
        $out = json_decode(app(BusinessDataToolService::class)->execute($name, json_encode($args), self::BUSINESS, $this->user), true);
        $this->assertIsArray($out);
        $this->assertTrue($out['ok'] ?? false, $name.' failed: '.json_encode($out));

        return $out;
    }

    private function locationId(string $name): int
    {
        return (int) DB::table('business_locations')->where('business_id', self::BUSINESS)->where('name', $name)->value('id');
    }

    private function productId(string $name): int
    {
        return (int) DB::table('products')->where('business_id', self::BUSINESS)->where('name', $name)->value('id');
    }

    public function test_ledger_baked_matches_production_records(): void
    {
        $expected = (float) DB::table('purchase_lines as pl')
            ->join('transactions as t', 't.id', '=', 'pl.transaction_id')
            ->where('t.business_id', self::BUSINESS)
            ->where('t.type', 'production_purchase')
            ->where('t.status', 'received')
            ->where('pl.product_id', $this->productId('BUTTER BREAD'))
            ->whereBetween('t.transaction_date', [self::START.' 00:00:00', self::END.' 23:59:59'])
            ->sum('pl.quantity');

        $out = $this->tool('production_ledger', ['start_date' => self::START, 'end_date' => self::END, 'name_query' => 'butter bread']);
        $row = collect($out['network'])->firstWhere('product', 'BUTTER BREAD');

        $this->assertEqualsWithDelta($expected, $row['produced'], 0.5);
    }

    public function test_ledger_sales_at_trek_match_sell_lines(): void
    {
        $expected = (float) DB::table('transaction_sell_lines as tsl')
            ->join('transactions as t', 't.id', '=', 'tsl.transaction_id')
            ->where('t.business_id', self::BUSINESS)
            ->where('t.type', 'sell')
            ->where('t.status', 'final')
            ->where('t.location_id', $this->locationId('Trek'))
            ->where('tsl.product_id', $this->productId('BUTTER BREAD'))
            ->whereNull('tsl.parent_sell_line_id')
            ->whereBetween('t.transaction_date', [self::START.' 00:00:00', self::END.' 23:59:59'])
            ->sum(DB::raw('tsl.quantity - COALESCE(tsl.quantity_returned, 0)'));

        $out = $this->tool('production_ledger', ['start_date' => self::START, 'end_date' => self::END, 'location_id' => $this->locationId('Trek'), 'name_query' => 'butter bread']);
        $row = collect($out['shops'])->firstWhere('product', 'BUTTER BREAD');

        $this->assertEqualsWithDelta($expected, $row['sold'], 0.5);
        $this->assertNotEmpty($out['daily'], 'One shop + product gives a day-by-day table');
    }

    public function test_momo_reconciliation_matches_payments(): void
    {
        $expected = (float) DB::table('transaction_payments as tp')
            ->join('transactions as t', 't.id', '=', 'tp.transaction_id')
            ->where('t.business_id', self::BUSINESS)
            ->whereIn('t.type', ['sell', 'sell_return'])
            ->where('tp.method', 'custom_pay_1')
            ->whereBetween('tp.paid_on', [self::START.' 00:00:00', self::END.' 23:59:59'])
            ->sum(DB::raw('CASE WHEN tp.is_return = 1 OR t.type = "sell_return" THEN -tp.amount ELSE tp.amount END'));

        $out = $this->tool('payment_reconciliation', ['start_date' => self::START, 'end_date' => self::END, 'method' => 'momo']);

        $this->assertSame('MTN MoMo', $out['method_filter']);
        $this->assertEqualsWithDelta($expected, $out['totals_by_method']['MTN MoMo'] ?? 0, 0.01);
    }

    public function test_register_difference_matches_register_transactions(): void
    {
        $register = DB::table('cash_registers')->where('business_id', self::BUSINESS)->where('status', 'close')
            ->whereBetween('closed_at', [self::START.' 00:00:00', self::END.' 23:59:59'])->orderByDesc('id')->first();
        $expectedCash = (float) DB::table('cash_register_transactions')->where('cash_register_id', $register->id)->where('pay_method', 'cash')
            ->sum(DB::raw('CASE WHEN type = "credit" THEN amount ELSE -amount END'));

        $out = $this->tool('register_variances', ['start_date' => self::START, 'end_date' => self::END]);
        $session = collect($out['sessions'])->firstWhere('register_id', (int) $register->id);
        $difference = (float) $register->closing_amount - $expectedCash;

        if (abs($difference) >= 0.01) {
            $this->assertNotNull($session);
            $this->assertEqualsWithDelta($difference, $session['difference'], 0.01);
        } else {
            $this->assertTrue($session === null || $session['hours'] >= 20);
        }
    }

    public function test_forecast_is_coherent_and_beats_naive_for_busiest_series(): void
    {
        $out = $this->tool('demand_forecast', ['location_name' => 'Trek', 'name_query' => 'butter bread']);
        $row = collect($out['forecasts'])->firstWhere('product', 'BUTTER BREAD');

        $this->assertNotNull($row);
        $this->assertLessThanOrEqual($row['p50'], $row['p10']);
        $this->assertLessThanOrEqual($row['p90'], $row['p50']);
        $this->assertGreaterThanOrEqual(floor($row['p50']), $row['recommended_qty']);

        $accuracy = $this->tool('forecast_accuracy');
        $top = $accuracy['backtest'][0];
        $this->assertLessThan($top['naive_wape_pct'], $top['wape_pct'], 'Chosen model should beat same-day-last-week on the busiest series');
        $this->assertLessThan($accuracy['naive_volume_weighted_wape_pct'], $accuracy['backtest_volume_weighted_wape_pct']);
    }

    public function test_unit_cost_uses_recipe_and_latest_prices(): void
    {
        $recipe = DB::table('mfg_recipes')->where('product_id', $this->productId('BUTTER BREAD'))->first();
        $ingredients = DB::table('mfg_recipe_ingredients as ri')
            ->leftJoin('units as su', 'su.id', '=', 'ri.sub_unit_id')
            ->join('variations as v', 'v.id', '=', 'ri.variation_id')
            ->where('ri.mfg_recipe_id', $recipe->id)
            ->get(['ri.variation_id', 'ri.quantity', 'ri.waste_percent', 'v.dpp_inc_tax', DB::raw('COALESCE(su.base_unit_multiplier, 1) as m')]);
        $cost = 0.0;
        foreach ($ingredients as $i) {
            $last = DB::table('purchase_lines as pl')->join('transactions as t', 't.id', '=', 'pl.transaction_id')
                ->where('t.business_id', self::BUSINESS)->where('t.type', 'purchase')->where('t.status', 'received')
                ->where('pl.variation_id', $i->variation_id)->where('pl.purchase_price_inc_tax', '>', 0)
                ->orderByDesc('t.transaction_date')->value('pl.purchase_price_inc_tax');
            $cost += (float) $i->quantity * (float) $i->m * (1 + (float) $i->waste_percent / 100) * (float) ($last ?? $i->dpp_inc_tax);
        }
        $perUnit = $cost / (float) $recipe->total_quantity;

        $out = $this->tool('unit_economics', ['start_date' => self::START, 'end_date' => self::END]);
        $row = collect($out['products'])->firstWhere('product', 'BUTTER BREAD');

        $this->assertEqualsWithDelta($perUnit, $row['ingredient_cost'], 0.01);
    }

    public function test_every_manager_tool_runs(): void
    {
        foreach (['ingredient_variance', 'location_profit', 'cashier_exceptions', 'staff_productivity', 'data_quality_audit', 'purchase_suggestion', 'target_progress', 'customer_account_health'] as $name) {
            $this->tool($name);
        }
        $this->addToAssertionCount(1);
    }
}
