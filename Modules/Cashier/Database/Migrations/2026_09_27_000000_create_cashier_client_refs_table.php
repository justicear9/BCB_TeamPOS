<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cashier_client_refs', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('business_id');
            $table->uuid('client_uuid');
            $table->string('entity_type', 32);
            $table->unsignedInteger('entity_id');
            $table->string('device_ref', 32)->nullable();
            $table->timestamps();

            $table->unique('client_uuid');
            $table->index('business_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cashier_client_refs');
    }
};
