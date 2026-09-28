<?php

namespace Tests\Unit\AIBusinessManager;

use Modules\AIBusinessManager\Services\BusinessDataToolService;
use Modules\AIBusinessManager\Services\BusinessInsightContextService;
use Modules\AIBusinessManager\Services\Manager\Stats;
use PHPUnit\Framework\TestCase;

class ManagerStatsTest extends TestCase
{
    public function test_quantile_interpolates(): void
    {
        $this->assertSame(2.5, Stats::quantile([1, 2, 3, 4], 0.5));
        $this->assertSame(1.0, Stats::quantile([4, 1, 3, 2], 0.0));
        $this->assertSame(0.0, Stats::quantile([], 0.5));
    }

    public function test_wape_and_bias(): void
    {
        $pairs = [[100.0, 90.0], [50.0, 60.0]];

        $this->assertEqualsWithDelta(20 / 150, Stats::wape($pairs), 1e-9);
        $this->assertEqualsWithDelta(0.0, Stats::bias($pairs), 1e-9);
        $this->assertNull(Stats::wape([[0.0, 5.0]]));
    }

    public function test_manager_tools_are_registered(): void
    {
        $service = new BusinessDataToolService($this->createMock(BusinessInsightContextService::class));
        $names = array_map(fn ($d) => $d['function']['name'], $service->getOpenAiToolDefinitions());

        foreach (['production_ledger', 'demand_forecast', 'forecast_accuracy', 'unit_economics', 'location_profit', 'cashier_exceptions', 'register_variances', 'payment_reconciliation', 'data_quality_audit', 'purchase_suggestion', 'save_note', 'decision_impact', 'target_progress', 'daily_brief'] as $tool) {
            $this->assertContains($tool, $names);
        }
        $this->assertSame(count($names), count(array_unique($names)), 'Tool names must be unique');
        $this->assertLessThanOrEqual(128, count($names), 'OpenAI accepts at most 128 tools');
    }
}
