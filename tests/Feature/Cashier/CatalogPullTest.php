<?php

namespace Tests\Feature\Cashier;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\Cashier\Services\CatalogPull;
use Tests\TestCase;

class CatalogPullTest extends TestCase
{
    use DatabaseTransactions;

    public function test_pull_includes_the_location_product_and_walk_in_customer(): void
    {
        $fx = CashierFixture::make();
        auth()->setUser($fx['user']);
        $pull = app(CatalogPull::class);

        $locations = $pull->locations($fx['user']);
        $this->assertSame($fx['location']->id, $locations[0]['id']);

        $changes = $pull->changes($fx['user'], $fx['location']->id, null);

        $this->assertSame($fx['location']->id, $changes['location']['id']);
        $this->assertNotEmpty($changes['server_time']);
        $product = collect($changes['products'])->firstWhere('variation_id', $fx['variation']->id);
        $this->assertSame('Test Item', $product['name']);
        $this->assertEquals(5, (float) $product['qty_available']);
        $this->assertEquals(10, (float) $product['sell_price']);
        $customer = collect($changes['customers'])->firstWhere('id', $fx['contact']->id);
        $this->assertSame(1, (int) $customer['is_default']);
        $this->assertContains('cash', array_column($changes['payment_methods'], 'id'));
        $this->assertArrayHasKey('display_name', $changes['receipt']);
        $this->assertNotSame('', $changes['receipt']['invoice_heading']);
    }

    public function test_pull_returns_current_stock_when_the_cursor_is_ahead_of_the_database(): void
    {
        $fx = CashierFixture::make();
        auth()->setUser($fx['user']);

        $changes = app(CatalogPull::class)->changes($fx['user'], $fx['location']->id, '2099-01-01 00:00:00');
        $product = collect($changes['products'])->firstWhere('variation_id', $fx['variation']->id);

        $this->assertEquals(5, (float) $product['qty_available']);
        $this->assertLessThan('2099-01-01 00:00:00', $changes['server_time']);
    }

    public function test_pull_rejects_a_location_the_user_cannot_access(): void
    {
        $fx = CashierFixture::make();
        $other = CashierFixture::make();
        auth()->setUser($fx['user']);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(CatalogPull::class)->changes($fx['user'], $other['location']->id, null);
    }
}
