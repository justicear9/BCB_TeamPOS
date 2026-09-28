<?php

namespace Tests\Unit\AIBusinessManager;

use Modules\AIBusinessManager\Services\BusinessDataToolService;
use Modules\AIBusinessManager\Services\BusinessInsightContextService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class ProductLocationMetricsToolRegistrationTest extends TestCase
{
    public function test_tool_is_registered_and_dispatched(): void
    {
        $service = new BusinessDataToolService(
            $this->createMock(BusinessInsightContextService::class)
        );

        $defs = $service->getOpenAiToolDefinitions();
        $names = array_map(fn ($d) => $d['function']['name'] ?? '', $defs);
        $this->assertContains('product_location_metrics', $names);

        $out = json_decode($service->execute('product_location_metrics', '{', 1, $this->createMock(\App\User::class)), true);
        $this->assertIsArray($out);
        $this->assertFalse($out['ok'] ?? true);
        $this->assertSame('invalid_json_arguments', $out['error'] ?? null);
    }

    public function test_units_compatible_helper_via_math_class_used_by_tool(): void
    {
        $this->assertTrue(
            \Modules\AIBusinessManager\Support\ProductLocationMetricsMath::unitsAreCompatible(['loaf', 'Pc'])
        );
        $this->assertFalse(
            \Modules\AIBusinessManager\Support\ProductLocationMetricsMath::unitsAreCompatible(['loaf', 'kg'])
        );
    }

    public function test_product_location_metrics_method_exists(): void
    {
        $method = new ReflectionMethod(BusinessDataToolService::class, 'productLocationMetrics');
        $this->assertTrue($method->isProtected() || $method->isPublic());
    }
}
