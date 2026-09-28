<?php

namespace Tests\Unit\AIBusinessManager;

use Carbon\Carbon;
use Modules\AIBusinessManager\Support\ProductLocationMetricsMath;
use PHPUnit\Framework\TestCase;

class ProductLocationMetricsMathTest extends TestCase
{
    public function test_calendar_day_average_divides_by_inclusive_span(): void
    {
        $start = Carbon::parse('2026-01-01')->startOfDay();
        $end = Carbon::parse('2026-01-10')->endOfDay();
        $days = ProductLocationMetricsMath::daysInRangeInclusive($start, $end);

        $this->assertSame(10, $days);
        $this->assertSame(42.0, ProductLocationMetricsMath::averageQuantity(420.0, $days, 3, 'calendar_day'));
    }

    public function test_selling_day_average_uses_selling_day_divisor(): void
    {
        $avg = ProductLocationMetricsMath::averageQuantity(90.0, 30, 3, 'selling_day');
        $this->assertSame(30.0, $avg);
    }

    public function test_selling_day_average_null_when_no_selling_days(): void
    {
        $this->assertNull(ProductLocationMetricsMath::averageQuantity(0.0, 30, 0, 'selling_day'));
    }

    public function test_qty_delta_pct(): void
    {
        $this->assertSame(50.0, ProductLocationMetricsMath::qtyDeltaPct(150.0, 100.0));
        $this->assertSame(-25.0, ProductLocationMetricsMath::qtyDeltaPct(75.0, 100.0));
        $this->assertNull(ProductLocationMetricsMath::qtyDeltaPct(10.0, 0.0));
    }

    public function test_units_compatible_when_same_ignoring_case(): void
    {
        $this->assertTrue(ProductLocationMetricsMath::unitsAreCompatible(['Loaf', 'loaf', ' LOAF ']));
        $this->assertTrue(ProductLocationMetricsMath::unitsAreCompatible(['loaves', 'Pc', 'Pcs']));
        $this->assertFalse(ProductLocationMetricsMath::unitsAreCompatible(['Loaf', 'kg']));
    }

    public function test_prior_period_same_length_ending_day_before_start(): void
    {
        $start = Carbon::parse('2026-02-11')->startOfDay();
        $end = Carbon::parse('2026-02-20')->endOfDay();
        [$priorStart, $priorEnd] = ProductLocationMetricsMath::priorPeriod($start, $end);

        $this->assertSame('2026-02-01', $priorStart->toDateString());
        $this->assertSame('2026-02-10', $priorEnd->toDateString());
        $this->assertSame(
            ProductLocationMetricsMath::daysInRangeInclusive($start, $end),
            ProductLocationMetricsMath::daysInRangeInclusive($priorStart, $priorEnd)
        );
    }
}
