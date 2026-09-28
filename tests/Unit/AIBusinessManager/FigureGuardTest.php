<?php

namespace Tests\Unit\AIBusinessManager;

use Modules\AIBusinessManager\Support\FigureGuard;
use PHPUnit\Framework\TestCase;

class FigureGuardTest extends TestCase
{
    private function guard(string $source): FigureGuard
    {
        $guard = new FigureGuard();
        $guard->addSource($source);

        return $guard;
    }

    public function test_keeps_figures_that_are_in_the_data(): void
    {
        $guard = $this->guard('{"revenue":51646.0,"cost":34450.12,"units":312}');
        $result = $guard->clean('Trek made ¢51,646 in revenue on 312 loaves.', '¢');

        $this->assertSame(0, $result['removed']);
        $this->assertStringContainsString('¢51,646', $result['text']);
    }

    public function test_keeps_simple_arithmetic_on_data(): void
    {
        $guard = $this->guard('{"a":51646,"b":34450.12,"prior":40000}');

        $this->assertTrue($guard->traceable(17195.88));
        $this->assertTrue($guard->traceable(29.1, true));
        $this->assertTrue($guard->traceable(66.7, true));
    }

    public function test_removes_invented_amounts(): void
    {
        $guard = $this->guard('{"revenue":51646}');
        $result = $guard->clean('Revenue was ¢51,646 and profit was ¢23,917.', '¢');

        $this->assertSame(1, $result['removed']);
        $this->assertStringContainsString('¢51,646', $result['text']);
        $this->assertStringContainsString(FigureGuard::REMOVED, $result['text']);
    }

    public function test_ignores_small_counts_and_dates(): void
    {
        $guard = $this->guard('{}');
        $result = $guard->clean('Bake 7 batches on 2026-09-29 for 3 shops.', '¢');

        $this->assertSame(0, $result['removed']);
    }

    public function test_sources_line_lists_tools_and_ranges(): void
    {
        $line = FigureGuard::sourcesLine([
            ['name' => 'demand_forecast', 'args' => ['target_date' => '2026-09-29']],
            ['name' => 'sales_report', 'args' => ['start_date' => '2026-01-01', 'end_date' => '2026-03-31']],
        ]);

        $this->assertStringContainsString('demand forecast (for 2026-09-29)', $line);
        $this->assertStringContainsString('sales report (2026-01-01 to 2026-03-31)', $line);
    }
}
