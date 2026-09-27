<?php

namespace Tests\Feature\Cashier;

use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ClientRefTableTest extends TestCase
{
    public function test_client_refs_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('cashier_client_refs'));
        $this->assertTrue(Schema::hasColumns('cashier_client_refs', [
            'business_id',
            'client_uuid',
            'entity_type',
            'entity_id',
            'device_ref',
        ]));
    }
}
