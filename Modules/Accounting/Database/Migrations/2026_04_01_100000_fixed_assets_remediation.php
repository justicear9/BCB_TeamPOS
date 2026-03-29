<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('accounting_fixed_assets')) {
            Schema::table('accounting_fixed_assets', function (Blueprint $table) {
                if (! Schema::hasColumn('accounting_fixed_assets', 'opening_accumulated_depreciation')) {
                    $table->decimal('opening_accumulated_depreciation', 22, 4)->default(0)->after('accumulated_depreciation_posted');
                }
                if (! Schema::hasColumn('accounting_fixed_assets', 'acquisition_mapping_id')) {
                    $table->unsignedBigInteger('acquisition_mapping_id')->nullable()->after('notes');
                }
                if (! Schema::hasColumn('accounting_fixed_assets', 'disposed_at')) {
                    $table->date('disposed_at')->nullable()->after('acquisition_mapping_id');
                }
                if (! Schema::hasColumn('accounting_fixed_assets', 'disposal_mapping_id')) {
                    $table->unsignedBigInteger('disposal_mapping_id')->nullable()->after('disposed_at');
                }
            });
        }

        if (Schema::hasTable('accounting_acc_trans_mappings')) {
            $idx = DB::select("SHOW INDEX FROM accounting_acc_trans_mappings WHERE Key_name = 'accounting_acc_trans_mappings_business_id_ref_no_unique'");
            if (empty($idx)) {
                try {
                    Schema::table('accounting_acc_trans_mappings', function (Blueprint $table) {
                        $table->unique(['business_id', 'ref_no'], 'accounting_acc_trans_mappings_business_id_ref_no_unique');
                    });
                } catch (\Throwable $e) {
                    // Duplicate ref_no per business in legacy data; skip unique index
                }
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('accounting_acc_trans_mappings')) {
            $idx = DB::select("SHOW INDEX FROM accounting_acc_trans_mappings WHERE Key_name = 'accounting_acc_trans_mappings_business_id_ref_no_unique'");
            if (! empty($idx)) {
                Schema::table('accounting_acc_trans_mappings', function (Blueprint $table) {
                    $table->dropUnique('accounting_acc_trans_mappings_business_id_ref_no_unique');
                });
            }
        }

        if (Schema::hasTable('accounting_fixed_assets')) {
            Schema::table('accounting_fixed_assets', function (Blueprint $table) {
                foreach (['opening_accumulated_depreciation', 'acquisition_mapping_id', 'disposed_at', 'disposal_mapping_id'] as $col) {
                    if (Schema::hasColumn('accounting_fixed_assets', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }
    }
};
