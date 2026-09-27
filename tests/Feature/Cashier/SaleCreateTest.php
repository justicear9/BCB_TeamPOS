<?php

namespace Tests\Feature\Cashier;

use App\Transaction;
use App\VariationLocationDetails;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Modules\Cashier\Services\SaleCreator;
use Tests\TestCase;

class SaleCreateTest extends TestCase
{
    use DatabaseTransactions;

    public function test_same_client_uuid_does_not_decrease_stock_twice(): void
    {
        $fx = CashierFixture::make();
        $creator = app(SaleCreator::class);
        $payload = $this->payload($fx, (string) Str::uuid(), 'C-ABCDEF-000001');

        $first = $creator->create($fx['user'], $payload);
        $second = $creator->create($fx['user'], $payload);

        $this->assertFalse($first['replayed']);
        $this->assertTrue($second['replayed']);
        $this->assertSame($first['entity_id'], $second['entity_id']);
        $this->assertNotEmpty($first['invoice_no']);
        $this->assertSame(1, Transaction::where('id', $first['entity_id'])->count());

        $qty = VariationLocationDetails::where('variation_id', $fx['variation']->id)
            ->where('location_id', $fx['location']->id)
            ->value('qty_available');
        $this->assertEquals(3, (float) $qty);

        $sale = Transaction::find($first['entity_id']);
        $this->assertSame('cashier_app', $sale->source);
        $this->assertSame($first['invoice_no'], $sale->invoice_no);
    }

    public function test_two_sales_of_the_last_units_both_store(): void
    {
        $fx = CashierFixture::make();
        $creator = app(SaleCreator::class);

        $creator->create($fx['user'], $this->payload($fx, (string) Str::uuid(), 'C-ABCDEF-000001', 5));
        $second = $creator->create($fx['user'], $this->payload($fx, (string) Str::uuid(), 'C-ABCDEF-000002', 1));

        $this->assertFalse($second['replayed']);
        $qty = VariationLocationDetails::where('variation_id', $fx['variation']->id)
            ->where('location_id', $fx['location']->id)
            ->value('qty_available');
        $this->assertEquals(-1, (float) $qty);
    }

    public function test_sale_for_another_business_contact_is_rejected(): void
    {
        $fx = CashierFixture::make();
        $other = CashierFixture::make();
        $payload = $this->payload($fx, (string) Str::uuid(), 'C-ABCDEF-000001');
        $payload['contact_id'] = $other['contact']->id;

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        app(SaleCreator::class)->create($fx['user'], $payload);
    }

    private function payload(array $fx, string $uuid, string $deviceRef, float $qty = 2): array
    {
        return [
            'client_uuid' => $uuid,
            'device_ref' => $deviceRef,
            'location_id' => $fx['location']->id,
            'contact_id' => $fx['contact']->id,
            'transaction_date' => '2026-09-27 10:00:00',
            'products' => [[
                'product_id' => $fx['product']->id,
                'variation_id' => $fx['variation']->id,
                'quantity' => $qty,
                'unit_price' => 10,
            ]],
            'payments' => [[
                'method' => 'cash',
                'amount' => 10 * $qty,
            ]],
        ];
    }
}
