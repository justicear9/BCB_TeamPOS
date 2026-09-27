<?php

namespace Tests\Feature\Cashier;

use App\CashRegister;
use App\CashRegisterTransaction;
use App\Product;
use App\ProductVariation;
use App\SellingPriceGroup;
use App\TaxRate;
use App\Transaction;
use App\TransactionSellLine;
use App\Variation;
use App\VariationGroupPrice;
use App\VariationLocationDetails;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Cashier\Services\CatalogPull;
use Modules\Cashier\Services\PaymentRecorder;
use Modules\Cashier\Services\SaleCreator;
use Modules\Cashier\Services\SaleReturner;
use Spatie\Permission\Models\Permission;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class CorrectnessTest extends TestCase
{
    use DatabaseTransactions;

    public function test_a_price_the_shop_does_not_charge_is_rejected(): void
    {
        $fx = CashierFixture::make();
        $payload = $this->payload($fx);
        $payload['products'][0]['unit_price'] = 1;
        $payload['payments'][0]['amount'] = 2;

        $this->expectHttp(422, fn () => app(SaleCreator::class)->create($fx['user'], $payload));
    }

    public function test_price_override_needs_the_permission(): void
    {
        $fx = CashierFixture::make();
        $payload = $this->payload($fx);
        $payload['products'][0]['unit_price'] = 8;
        $payload['products'][0]['price_override'] = true;
        $payload['payments'][0]['amount'] = 16;

        $this->expectHttp(422, fn () => app(SaleCreator::class)->create($fx['user'], $payload));

        Permission::findOrCreate('edit_product_price_from_pos_screen', 'web');
        $fx['user']->givePermissionTo('edit_product_price_from_pos_screen');
        $fx['user']->forgetCachedPermissions();
        $payload['client_uuid'] = (string) Str::uuid();

        $result = app(SaleCreator::class)->create($fx['user']->fresh(), $payload);
        $this->assertEquals(16, (float) $result['final_total']);
    }

    public function test_the_location_price_group_sets_the_price(): void
    {
        $fx = CashierFixture::make();
        $group = SellingPriceGroup::create(['business_id' => $fx['business']->id, 'name' => 'Trek']);
        VariationGroupPrice::create([
            'variation_id' => $fx['variation']->id,
            'price_group_id' => $group->id,
            'price_inc_tax' => 7,
            'price_type' => 'fixed',
        ]);
        $fx['location']->selling_price_group_id = $group->id;
        $fx['location']->save();

        $pulled = app(CatalogPull::class)->changes($fx['user'], $fx['location']->id, null);
        $this->assertEquals(7, (float) $pulled['products'][0]['sell_price']);

        $payload = $this->payload($fx);
        $payload['products'][0]['unit_price'] = 7;
        $payload['payments'][0]['amount'] = 14;
        $result = app(SaleCreator::class)->create($fx['user'], $payload);
        $this->assertEquals(14, (float) $result['final_total']);
    }

    public function test_product_tax_is_recorded_on_the_line(): void
    {
        $fx = CashierFixture::make();
        $tax = TaxRate::create([
            'business_id' => $fx['business']->id,
            'name' => 'VAT',
            'amount' => 25,
            'created_by' => $fx['user']->id,
        ]);
        $fx['product']->tax = $tax->id;
        $fx['product']->save();

        $result = app(SaleCreator::class)->create($fx['user'], $this->payload($fx));
        $line = TransactionSellLine::where('transaction_id', $result['entity_id'])->first();

        $this->assertEquals(20, (float) $result['final_total']);
        $this->assertEquals($tax->id, $line->tax_id);
        $this->assertEqualsWithDelta(8, (float) $line->unit_price, 0.0001);
        $this->assertEqualsWithDelta(2, (float) $line->item_tax, 0.0001);
        $this->assertEqualsWithDelta(10, (float) $line->unit_price_inc_tax, 0.0001);
    }

    public function test_inactive_not_for_selling_and_unassigned_products_are_rejected(): void
    {
        $fx = CashierFixture::make();
        foreach (['is_inactive', 'not_for_selling'] as $flag) {
            $fx['product']->{$flag} = 1;
            $fx['product']->save();
            $this->expectHttp(422, fn () => app(SaleCreator::class)->create($fx['user'], $this->payload($fx)));
            $fx['product']->{$flag} = 0;
            $fx['product']->save();
        }

        $fx['product']->product_locations()->sync([]);
        $this->expectHttp(422, fn () => app(SaleCreator::class)->create($fx['user'], $this->payload($fx)));
    }

    public function test_a_combo_sale_reduces_its_component_stock(): void
    {
        $fx = CashierFixture::make();
        $combo = Product::create([
            'name' => 'Breakfast pack',
            'business_id' => $fx['business']->id,
            'type' => 'combo',
            'unit_id' => $fx['product']->unit_id,
            'tax_type' => 'inclusive',
            'enable_stock' => 0,
            'sku' => 'CMB-'.Str::upper(Str::random(6)),
            'barcode_type' => 'C128',
            'created_by' => $fx['user']->id,
            'is_inactive' => 0,
            'not_for_selling' => 0,
        ]);
        $combo->product_locations()->sync([$fx['location']->id]);
        $comboVariation = Variation::create([
            'name' => 'DUMMY',
            'product_id' => $combo->id,
            'sub_sku' => $combo->sku,
            'product_variation_id' => ProductVariation::create(['name' => 'DUMMY', 'product_id' => $combo->id, 'is_dummy' => 1])->id,
            'default_sell_price' => 25,
            'sell_price_inc_tax' => 25,
            'combo_variations' => [[
                'variation_id' => $fx['variation']->id,
                'quantity' => 2,
                'unit_id' => $fx['product']->unit_id,
            ]],
        ]);

        $payload = $this->payload($fx);
        $payload['products'] = [[
            'product_id' => $combo->id,
            'variation_id' => $comboVariation->id,
            'quantity' => 1,
            'unit_price' => 25,
        ]];
        $payload['payments'][0]['amount'] = 25;
        app(SaleCreator::class)->create($fx['user'], $payload);

        $this->assertEquals(3, (float) $this->stock($fx));
    }

    public function test_a_wrong_phone_clock_uses_the_sync_time(): void
    {
        $fx = CashierFixture::make();
        $payload = $this->payload($fx);
        $payload['transaction_date'] = now()->addDays(3)->toIso8601String();

        $result = app(SaleCreator::class)->create($fx['user'], $payload);
        $sale = Transaction::find($result['entity_id']);

        $this->assertTrue(Carbon::parse($sale->transaction_date)->lte(now()->addMinute()));
        $this->assertStringContainsString('Phone clock', (string) $sale->staff_note);
    }

    public function test_the_phone_offset_is_converted_to_the_business_timezone(): void
    {
        $fx = CashierFixture::make();
        $fx['business']->time_zone = 'Africa/Accra';
        $fx['business']->save();
        $payload = $this->payload($fx);
        $when = now('Africa/Accra')->subHour()->setSecond(0);
        $payload['transaction_date'] = $when->copy()->setTimezone('Africa/Lagos')->toIso8601String();

        $result = app(SaleCreator::class)->create($fx['user'], $payload);

        $this->assertSame($when->toDateTimeString(), $result['transaction_date']);
    }

    public function test_paid_sales_land_in_the_cashier_register(): void
    {
        $fx = CashierFixture::make();
        $this->assertSame(0, CashRegister::where('user_id', $fx['user']->id)->count());

        $result = app(SaleCreator::class)->create($fx['user'], $this->payload($fx));

        $register = CashRegister::where('user_id', $fx['user']->id)->where('status', 'open')->firstOrFail();
        $this->assertSame($fx['location']->id, (int) $register->location_id);
        $row = CashRegisterTransaction::where('cash_register_id', $register->id)->where('transaction_id', $result['entity_id'])->firstOrFail();
        $this->assertEquals(20, (float) $row->amount);
        $this->assertSame('cash', $row->pay_method);
    }

    public function test_register_opens_with_a_float_and_closes_with_the_count(): void
    {
        $fx = CashierFixture::make();
        $this->actingAs($fx['user'], 'api')
            ->postJson('/cashier/api/register/open', ['location_id' => $fx['location']->id, 'opening_cash' => 50])
            ->assertOk()
            ->assertJsonPath('open', true)
            ->assertJsonPath('opening_cash', '50');

        app(SaleCreator::class)->create($fx['user'], $this->payload($fx));

        $summary = $this->actingAs($fx['user'], 'api')->getJson('/cashier/api/register')->assertOk();
        $this->assertEquals(70, (float) $summary->json('expected_cash'));

        $closed = $this->actingAs($fx['user'], 'api')
            ->postJson('/cashier/api/register/close', ['closing_cash' => 65])
            ->assertOk();
        $this->assertEquals(-5, (float) $closed->json('difference'));
        $this->assertSame(0, CashRegister::where('user_id', $fx['user']->id)->where('status', 'open')->count());
    }

    public function test_payments_need_the_sell_payments_permission(): void
    {
        $fx = CashierFixture::make();
        $payload = $this->payload($fx);
        $payload['payments'][0]['amount'] = 5;
        app(SaleCreator::class)->create($fx['user'], $payload);
        $fx['user']->revokePermissionTo('sell.payments');
        $fx['user']->forgetCachedPermissions();

        $this->expectHttp(403, fn () => app(PaymentRecorder::class)->add($fx['user']->fresh(), [
            'client_uuid' => (string) Str::uuid(),
            'sale_client_uuid' => $payload['client_uuid'],
            'method' => 'cash',
            'amount' => 5,
        ]));
    }

    public function test_advance_needs_an_allowed_location(): void
    {
        $fx = CashierFixture::make();
        $other = CashierFixture::make();

        $this->expectHttp(403, fn () => app(PaymentRecorder::class)->advance($fx['user'], [
            'client_uuid' => (string) Str::uuid(),
            'contact_id' => $fx['contact']->id,
            'location_id' => $other['location']->id,
            'method' => 'cash',
            'amount' => 5,
        ]));
    }

    public function test_sale_discount_lowers_the_total(): void
    {
        $fx = CashierFixture::make();
        $payload = $this->payload($fx);
        $payload['discount_type'] = 'percentage';
        $payload['discount_amount'] = 10;
        $payload['payments'][0]['amount'] = 18;

        $result = app(SaleCreator::class)->create($fx['user'], $payload);
        $this->assertEquals(18, (float) $result['final_total']);
        $this->assertSame('paid', Transaction::find($result['entity_id'])->payment_status);
    }

    public function test_discount_is_refused_when_the_role_disables_it(): void
    {
        $fx = CashierFixture::make();
        Permission::findOrCreate('disable_discount', 'web');
        $fx['user']->givePermissionTo('disable_discount');
        $fx['user']->forgetCachedPermissions();
        $payload = $this->payload($fx);
        $payload['discount_amount'] = 2;
        $payload['payments'][0]['amount'] = 18;

        $this->expectHttp(422, fn () => app(SaleCreator::class)->create($fx['user']->fresh(), $payload));
    }

    public function test_a_partial_return_restocks_and_refunds_cash(): void
    {
        $fx = CashierFixture::make();
        $sale = app(SaleCreator::class)->create($fx['user'], $this->payload($fx));
        $line = TransactionSellLine::where('transaction_id', $sale['entity_id'])->firstOrFail();

        $payload = [
            'client_uuid' => (string) Str::uuid(),
            'transaction_id' => $sale['entity_id'],
            'refund_method' => 'cash',
            'lines' => [['sell_line_id' => $line->id, 'quantity' => 1]],
        ];
        $first = app(SaleReturner::class)->create($fx['user'], $payload);
        $again = app(SaleReturner::class)->create($fx['user'], $payload);

        $this->assertEquals(10, (float) $first['refund']);
        $this->assertTrue($again['replayed']);
        $this->assertEquals(4, (float) $this->stock($fx));
        $this->assertEquals(1, (float) $line->fresh()->quantity_returned);
        $this->assertEquals(10, (float) CashRegisterTransaction::where('transaction_id', $first['entity_id'])->where('type', 'debit')->sum('amount'));

        $this->expectHttp(422, fn () => app(SaleReturner::class)->create($fx['user'], array_merge($payload, [
            'client_uuid' => (string) Str::uuid(),
            'lines' => [['sell_line_id' => $line->id, 'quantity' => 2]],
        ])));
    }

    public function test_a_return_on_an_unpaid_sale_lowers_the_balance_without_cash(): void
    {
        $fx = CashierFixture::make();
        $fx['contact']->credit_limit = null;
        $fx['contact']->is_default = 0;
        $fx['contact']->save();
        $payload = $this->payload($fx);
        $payload['payments'] = [];
        $sale = app(SaleCreator::class)->create($fx['user'], $payload);
        $line = TransactionSellLine::where('transaction_id', $sale['entity_id'])->firstOrFail();

        $result = app(SaleReturner::class)->create($fx['user'], [
            'client_uuid' => (string) Str::uuid(),
            'transaction_id' => $sale['entity_id'],
            'refund_method' => 'cash',
            'lines' => [['sell_line_id' => $line->id, 'quantity' => 1]],
        ]);

        $this->assertEquals(0, (float) $result['refund']);
        $this->assertEquals(10, (float) $result['return_total']);
    }

    public function test_customer_create_and_edit(): void
    {
        $fx = CashierFixture::make();
        $uuid = (string) Str::uuid();
        $created = $this->actingAs($fx['user'], 'api')->postJson('/cashier/api/customers', [
            'client_uuid' => $uuid,
            'name' => 'Ama Mensah',
            'mobile' => '0244000111',
        ])->assertOk();
        $id = $created->json('customer.id');
        $this->assertEquals(0, (float) $created->json('customer.credit_limit'));

        $this->actingAs($fx['user'], 'api')->postJson('/cashier/api/customers', [
            'client_uuid' => $uuid,
            'name' => 'Ama Mensah',
            'mobile' => '0244000111',
        ])->assertOk()->assertJsonPath('replayed', true)->assertJsonPath('customer.id', $id);

        $this->actingAs($fx['user'], 'api')->postJson('/cashier/api/customers', [
            'client_uuid' => (string) Str::uuid(),
            'name' => 'Someone else',
            'mobile' => '0244000111',
        ])->assertStatus(422);

        $this->actingAs($fx['user'], 'api')->putJson("/cashier/api/customers/{$id}", [
            'name' => 'Ama Owusu',
            'mobile' => '0244000222',
            'business_name' => 'Ama Stores',
        ])->assertOk()->assertJsonPath('customer.business_name', 'Ama Stores');
    }

    public function test_sign_in_issues_a_token_and_sign_out_revokes_it(): void
    {
        $fx = CashierFixture::make();
        $fx['user']->status = 'active';
        $fx['user']->save();

        $this->postJson('/cashier/api/auth/login', ['username' => $fx['user']->username, 'password' => 'wrong'])
            ->assertStatus(422);

        $login = $this->postJson('/cashier/api/auth/login', ['username' => $fx['user']->username, 'password' => 'secret'])
            ->assertOk();
        $token = $login->json('access_token');
        $this->assertNotEmpty($token);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/cashier/api/sync/locations')
            ->assertOk();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/cashier/api/auth/logout')
            ->assertOk();

        $this->assertSame(1, (int) DB::table('oauth_access_tokens')->where('user_id', $fx['user']->id)->value('revoked'));
    }

    public function test_the_sales_feed_skips_drafts(): void
    {
        $fx = CashierFixture::make();
        $sale = app(SaleCreator::class)->create($fx['user'], $this->payload($fx));
        Transaction::where('id', $sale['entity_id'])->update(['status' => 'draft']);

        $pulled = app(CatalogPull::class)->changes($fx['user'], $fx['location']->id, null);
        $this->assertSame([], array_values(array_filter($pulled['sales'], fn ($row) => $row['id'] === $sale['entity_id'])));
    }

    private function stock(array $fx)
    {
        return VariationLocationDetails::where('variation_id', $fx['variation']->id)
            ->where('location_id', $fx['location']->id)
            ->value('qty_available');
    }

    private function expectHttp(int $status, callable $call): void
    {
        try {
            $call();
        } catch (HttpException $e) {
            $this->assertSame($status, $e->getStatusCode(), $e->getMessage());

            return;
        }
        $this->fail("Expected HTTP {$status}");
    }

    private function payload(array $fx): array
    {
        return [
            'client_uuid' => (string) Str::uuid(),
            'device_ref' => 'C-ABCDEF-000001',
            'location_id' => $fx['location']->id,
            'contact_id' => $fx['contact']->id,
            'transaction_date' => now()->subMinutes(5)->toIso8601String(),
            'products' => [[
                'product_id' => $fx['product']->id,
                'variation_id' => $fx['variation']->id,
                'quantity' => 2,
                'unit_price' => 10,
            ]],
            'payments' => [['method' => 'cash', 'amount' => 20]],
        ];
    }
}
