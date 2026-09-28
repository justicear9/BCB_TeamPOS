<?php

namespace Tests\Feature\Cashier;

use App\Transaction;
use App\TransactionPayment;
use App\VariationLocationDetails;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Modules\Cashier\Entities\CashierClientRef;
use Modules\Cashier\Services\PaymentRecorder;
use Modules\Cashier\Services\ReceiptBuilder;
use Modules\Cashier\Services\SaleCreator;
use Symfony\Component\HttpKernel\Exception\HttpException;
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

    public function test_the_first_phone_to_sync_gets_the_last_units(): void
    {
        $fx = CashierFixture::make();
        $creator = app(SaleCreator::class);

        $creator->create($fx['user'], $this->payload($fx, (string) Str::uuid(), 'C-ABCDEF-000001', 5));
        $secondUuid = (string) Str::uuid();
        try {
            $creator->create($fx['user'], $this->payload($fx, $secondUuid, 'C-ABCDEF-000002', 1));
            $this->fail('The second sale should be refused');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertStringContainsString('Not enough', $e->getMessage());
        }

        $qty = VariationLocationDetails::where('variation_id', $fx['variation']->id)
            ->where('location_id', $fx['location']->id)
            ->value('qty_available');
        $this->assertEquals(0, (float) $qty);
        $this->assertFalse(CashierClientRef::where('client_uuid', $secondUuid)->exists());
    }

    public function test_sale_posts_when_the_request_has_no_web_session(): void
    {
        $fx = CashierFixture::make();
        $fx['location']->accounting_default_map = json_encode([
            'sell_payment' => ['deposit_to' => '1', 'payment_account' => '1'],
        ]);
        $fx['location']->save();

        $this->app->instance('request', Request::create('/cashier/api/sync/operations', 'POST'));

        $result = app(SaleCreator::class)->create(
            $fx['user'],
            $this->payload($fx, (string) Str::uuid(), 'C-ABCDEF-000009')
        );

        $this->assertFalse($result['replayed']);
        $this->assertNotEmpty($result['invoice_no']);

        $html = app(ReceiptBuilder::class)->html($fx['user'], $result['entity_id']);
        $this->assertStringContainsString($result['invoice_no'], $html);
        $this->assertStringContainsString('css/vendor.css', $html);
    }

    public function test_split_payment_records_cash_change_and_a_second_method(): void
    {
        $fx = CashierFixture::make();
        $payload = $this->payload($fx, (string) Str::uuid(), 'C-ABCDEF-000010');
        $payload['payments'] = [
            ['method' => 'cash', 'amount' => 10, 'tendered' => 15],
            ['method' => 'card', 'amount' => 10, 'tendered' => 10],
        ];

        $result = app(SaleCreator::class)->create($fx['user'], $payload);
        $sale = Transaction::find($result['entity_id']);

        $this->assertSame('paid', $sale->payment_status);
        $this->assertEquals(5, (float) TransactionPayment::where('transaction_id', $sale->id)->where('is_return', 1)->value('amount'));
        $this->assertEquals(2, TransactionPayment::where('transaction_id', $sale->id)->where('is_return', 0)->count());
    }

    public function test_credit_sale_uses_the_customer_limit(): void
    {
        $fx = CashierFixture::make();
        $payload = $this->payload($fx, (string) Str::uuid(), 'C-ABCDEF-000011');
        $payload['payments'] = [['method' => 'cash', 'amount' => 5, 'tendered' => 5]];

        $result = app(SaleCreator::class)->create($fx['user'], $payload);
        $sale = Transaction::find($result['entity_id']);
        $this->assertSame('partial', $sale->payment_status);

        $paid = app(PaymentRecorder::class)->add($fx['user'], [
            'client_uuid' => (string) Str::uuid(),
            'sale_client_uuid' => $payload['client_uuid'],
            'method' => 'cash',
            'amount' => 15,
        ]);
        $this->assertSame('paid', $paid['payment_status']);
        $this->assertEquals(20, (float) $paid['total_paid']);

        $again = app(PaymentRecorder::class)->add($fx['user'], [
            'client_uuid' => $paid['client_uuid'],
            'sale_client_uuid' => $payload['client_uuid'],
            'method' => 'cash',
            'amount' => 15,
        ]);
        $this->assertTrue($again['replayed']);
        $this->assertEquals(20, (float) $again['total_paid']);
    }

    public function test_walk_in_with_no_credit_limit_cannot_take_a_balance(): void
    {
        $fx = CashierFixture::make();
        $fx['contact']->credit_limit = 0;
        $fx['contact']->save();
        $payload = $this->payload($fx, (string) Str::uuid(), 'C-ABCDEF-000012');
        $payload['payments'] = [];

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(SaleCreator::class)->create($fx['user'], $payload);
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

    public function test_sale_stores_the_device_coordinates(): void
    {
        $fx = CashierFixture::make();
        $payload = $this->payload($fx, (string) Str::uuid(), 'C-ABCDEF-000013');
        $payload['latitude'] = 5.6037;
        $payload['longitude'] = -0.187;
        $payload['accuracy'] = 8.5;

        $result = app(SaleCreator::class)->create($fx['user'], $payload);
        $row = DB::table('cashier_sale_locations')->where('transaction_id', $result['entity_id'])->first();

        $this->assertNotNull($row);
        $this->assertEqualsWithDelta(5.6037, (float) $row->latitude, 0.0000001);
        $this->assertEqualsWithDelta(-0.187, (float) $row->longitude, 0.0000001);
        $this->assertEqualsWithDelta(8.5, (float) $row->accuracy, 0.01);
    }

    public function test_prepayment_is_stored_as_customer_advance(): void
    {
        $fx = CashierFixture::make();
        $uuid = (string) Str::uuid();
        $recorder = app(PaymentRecorder::class);
        $payload = [
            'client_uuid' => $uuid,
            'contact_id' => $fx['contact']->id,
            'location_id' => $fx['location']->id,
            'method' => 'cash',
            'amount' => 15,
        ];

        $first = $recorder->advance($fx['user'], $payload);
        $second = $recorder->advance($fx['user'], $payload);

        $this->assertFalse($first['replayed']);
        $this->assertTrue($second['replayed']);
        $payment = TransactionPayment::findOrFail($first['entity_id']);
        $this->assertSame(1, (int) $payment->is_advance);
        $this->assertNull($payment->transaction_id);
        $this->assertEquals(15, (float) $fx['contact']->fresh()->balance);
        $this->assertEquals(15, (float) $first['advance']);
    }

    private function payload(array $fx, string $uuid, string $deviceRef, float $qty = 2): array
    {
        return [
            'client_uuid' => $uuid,
            'device_ref' => $deviceRef,
            'location_id' => $fx['location']->id,
            'contact_id' => $fx['contact']->id,
            'transaction_date' => now()->subMinutes(5)->toIso8601String(),
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
