<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_reporting_location_settings', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('business_id');
            $table->unsignedInteger('location_id');
            $table->unsignedInteger('inventory_adjustment_offset_account_id')->nullable()
                ->comment('Expense/other account: debited on stock decrease, credited on stock increase');
            $table->timestamps();

            $table->unique(['business_id', 'location_id'], 'inv_rep_loc_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_reporting_location_settings');
    }
};
