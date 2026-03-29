<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('accounting_fixed_assets')) {
            Schema::create('accounting_fixed_assets', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedInteger('business_id');
                $table->unsignedInteger('location_id')->nullable();
                $table->string('asset_code', 64)->nullable();
                $table->string('name', 256);
                $table->unsignedInteger('asset_account_id');
                $table->unsignedInteger('accumulated_depreciation_account_id');
                $table->unsignedInteger('depreciation_expense_account_id');
                $table->date('acquisition_date');
                $table->decimal('cost', 22, 4);
                $table->decimal('salvage_value', 22, 4)->default(0);
                $table->unsignedInteger('useful_life_months');
                $table->string('depreciation_method', 32)->default('straight_line');
                $table->string('status', 32)->default('active');
                $table->decimal('accumulated_depreciation_posted', 22, 4)->default(0);
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->index(['business_id', 'status']);
                $table->index('location_id');
            });
        }

        Schema::table('accounting_acc_trans_mappings', function (Blueprint $table) {
            if (! Schema::hasColumn('accounting_acc_trans_mappings', 'fixed_asset_id')) {
                $table->unsignedBigInteger('fixed_asset_id')->nullable()->after('note');
                $table->string('depreciation_period', 7)->nullable()->after('fixed_asset_id');
                $table->index('fixed_asset_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('accounting_acc_trans_mappings', function (Blueprint $table) {
            if (Schema::hasColumn('accounting_acc_trans_mappings', 'fixed_asset_id')) {
                $table->dropIndex(['fixed_asset_id']);
                $table->dropColumn(['fixed_asset_id', 'depreciation_period']);
            }
        });

        Schema::dropIfExists('accounting_fixed_assets');
    }
};
