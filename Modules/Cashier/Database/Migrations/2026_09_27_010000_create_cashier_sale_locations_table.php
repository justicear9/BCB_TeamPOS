<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cashier_sale_locations', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('business_id');
            $table->unsignedInteger('transaction_id');
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->decimal('accuracy', 10, 2)->nullable();
            $table->timestamps();

            $table->unique('transaction_id');
            $table->index('business_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cashier_sale_locations');
    }
};
