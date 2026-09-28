<?php

namespace Tests\Feature\Cashier;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

class SyncHttpTest extends TestCase
{
    use DatabaseTransactions;

    public function test_post_sale_returns_invoice_and_replay_is_the_same_sale(): void
    {
        $fx = CashierFixture::make();
        $body = [
            'type' => 'sale.create',
            'client_uuid' => (string) Str::uuid(),
            'device_ref' => 'C-ABCDEF-000001',
            'location_id' => $fx['location']->id,
            'contact_id' => $fx['contact']->id,
            'transaction_date' => now()->subMinutes(5)->toIso8601String(),
            'products' => [[
                'product_id' => $fx['product']->id,
                'variation_id' => $fx['variation']->id,
                'quantity' => 1,
                'unit_price' => 10,
            ]],
            'payments' => [[
                'method' => 'cash',
                'amount' => 10,
            ]],
        ];

        $first = $this->actingAs($fx['user'], 'api')
            ->postJson('/cashier/api/sync/operations', $body);
        $first->assertOk();
        $first->assertJsonPath('replayed', false);
        $this->assertNotEmpty($first->json('invoice_no'));

        $second = $this->actingAs($fx['user'], 'api')
            ->postJson('/cashier/api/sync/operations', $body);
        $second->assertOk();
        $second->assertJsonPath('entity_id', $first->json('entity_id'));
        $second->assertJsonPath('replayed', true);
    }

    public function test_changes_requires_location_id(): void
    {
        $fx = CashierFixture::make();
        $this->actingAs($fx['user'], 'api')
            ->getJson('/cashier/api/sync/changes')
            ->assertStatus(422);
    }

    public function test_unsupported_operation_is_rejected(): void
    {
        $fx = CashierFixture::make();
        $this->actingAs($fx['user'], 'api')
            ->postJson('/cashier/api/sync/operations', [
                'type' => 'register.open',
                'client_uuid' => (string) Str::uuid(),
            ])
            ->assertStatus(422);
    }
}
