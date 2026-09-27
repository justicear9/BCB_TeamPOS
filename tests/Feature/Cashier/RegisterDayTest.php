<?php

namespace Tests\Feature\Cashier;

use App\CashRegister;
use App\CashRegisterTransaction;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Modules\Cashier\Services\SaleCreator;
use Tests\TestCase;

class RegisterDayTest extends TestCase
{
    use DatabaseTransactions;

    public function test_a_register_stays_open_across_days_until_someone_closes_it(): void
    {
        $fx = $this->accra();
        $yesterday = now('Africa/Accra')->subDay()->format('Y-m-d');
        $register = $this->register($fx, $yesterday.' 08:00:00');

        $this->actingAs($fx['user'], 'api')
            ->getJson('/cashier/api/register')
            ->assertOk()
            ->assertJsonPath('open', true)
            ->assertJsonPath('id', $register->id);
    }

    public function test_a_sale_synced_after_its_register_closed_goes_into_that_register(): void
    {
        $fx = $this->accra();
        $yesterday = now('Africa/Accra')->subDay()->format('Y-m-d');
        $register = $this->register($fx, $yesterday.' 08:00:00');
        $register->update(['status' => 'close', 'closed_at' => $yesterday.' 20:00:00']);

        $result = app(SaleCreator::class)->create($fx['user'], [
            'client_uuid' => (string) Str::uuid(),
            'device_ref' => 'C-ABCDEF-000009',
            'location_id' => $fx['location']->id,
            'contact_id' => $fx['contact']->id,
            'transaction_date' => Carbon::parse($yesterday.' 15:00:00', 'Africa/Accra')->toIso8601String(),
            'products' => [[
                'product_id' => $fx['product']->id,
                'variation_id' => $fx['variation']->id,
                'quantity' => 1,
                'unit_price' => 10,
            ]],
            'payments' => [['method' => 'cash', 'amount' => 10]],
        ]);

        $row = CashRegisterTransaction::where('transaction_id', $result['entity_id'])->firstOrFail();
        $this->assertSame($register->id, (int) $row->cash_register_id);
        $this->assertSame(0, CashRegister::where('user_id', $fx['user']->id)->where('status', 'open')->count());
    }

    private function accra(): array
    {
        $fx = CashierFixture::make();
        $fx['business']->time_zone = 'Africa/Accra';
        $fx['business']->save();

        return $fx;
    }

    private function register(array $fx, string $openedAt): CashRegister
    {
        return CashRegister::create([
            'business_id' => $fx['business']->id,
            'user_id' => $fx['user']->id,
            'status' => 'open',
            'location_id' => $fx['location']->id,
            'created_at' => $openedAt,
        ]);
    }
}
