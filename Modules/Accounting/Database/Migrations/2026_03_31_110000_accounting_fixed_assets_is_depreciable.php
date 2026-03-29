<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('accounting_fixed_assets')) {
            return;
        }

        Schema::table('accounting_fixed_assets', function (Blueprint $table) {
            if (! Schema::hasColumn('accounting_fixed_assets', 'is_depreciable')) {
                $table->boolean('is_depreciable')->default(true)->after('depreciation_method');
            }
        });

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE accounting_fixed_assets MODIFY accumulated_depreciation_account_id INT UNSIGNED NULL');
            DB::statement('ALTER TABLE accounting_fixed_assets MODIFY depreciation_expense_account_id INT UNSIGNED NULL');
            DB::statement('ALTER TABLE accounting_fixed_assets MODIFY useful_life_months INT UNSIGNED NULL');
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('accounting_fixed_assets')) {
            return;
        }

        Schema::table('accounting_fixed_assets', function (Blueprint $table) {
            if (Schema::hasColumn('accounting_fixed_assets', 'is_depreciable')) {
                $table->dropColumn('is_depreciable');
            }
        });
    }
};
