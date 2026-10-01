<?php

namespace Tests\Feature\Cashier;

use App\SellingPriceGroup;
use App\Transaction;
use App\VariationGroupPrice;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Modules\Cashier\Services\SaleCreator;
use Spatie\Permission\Models\Permission;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class PriceGroupSaleTest extends TestCase
{
    use DatabaseTransactions;

    public function test_sale_uses_the_chosen_price_group(): void
    {
        $fx = CashierFixture::make();
        $group = $this->group($fx, 'Wholesale', 8);

        $result = app(SaleCreator::class)->create($fx['user'], $this->payload($fx, $group->id, 8));

        $sale = Transaction::find($result['entity_id']);
        $this->assertSame($group->id, (int) $sale->selling_price_group_id);
        $this->assertEquals(16, (float) $sale->final_total);
    }

    public function test_zero_group_price_falls_back_to_the_default_price(): void
    {
        $fx = CashierFixture::make();
        $group = $this->group($fx, 'UPSA', 0);

        $result = app(SaleCreator::class)->create($fx['user'], $this->payload($fx, $group->id, 10));

        $this->assertEquals(20, (float) Transaction::find($result['entity_id'])->final_total);
    }

    public function test_group_the_cashier_cannot_use_is_refused(): void
    {
        $fx = CashierFixture::make();
        $group = $this->group($fx, 'Wholesale', 8, false);

        try {
            app(SaleCreator::class)->create($fx['user'], $this->payload($fx, $group->id, 8));
            $this->fail('A price group without permission should be refused');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertStringContainsString('price group is not available', $e->getMessage());
        }
    }

    private function group(array $fx, string $name, float $price, bool $allow = true): SellingPriceGroup
    {
        $group = SellingPriceGroup::create([
            'business_id' => $fx['business']->id,
            'name' => $name.' '.Str::random(4),
            'is_active' => 1,
        ]);

        VariationGroupPrice::create([
            'variation_id' => $fx['variation']->id,
            'price_group_id' => $group->id,
            'price_inc_tax' => $price,
            'price_type' => 'fixed',
        ]);

        if ($allow) {
            $permission = 'selling_price_group.'.$group->id;
            Permission::findOrCreate($permission, 'web');
            $fx['user']->givePermissionTo($permission);
        }

        return $group;
    }

    private function payload(array $fx, int $groupId, float $unitPrice): array
    {
        return [
            'client_uuid' => (string) Str::uuid(),
            'device_ref' => 'C-ABCDEF-000001',
            'location_id' => $fx['location']->id,
            'contact_id' => $fx['contact']->id,
            'transaction_date' => now()->subMinutes(5)->toIso8601String(),
            'selling_price_group_id' => $groupId,
            'products' => [[
                'product_id' => $fx['product']->id,
                'variation_id' => $fx['variation']->id,
                'quantity' => 2,
                'unit_price' => $unitPrice,
            ]],
            'payments' => [[
                'method' => 'cash',
                'amount' => 2 * $unitPrice,
            ]],
        ];
    }
}
