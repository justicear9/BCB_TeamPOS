<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('accounting_bank_statement_lines')) {
            return;
        }

        Schema::table('accounting_bank_statement_lines', function (Blueprint $table) {
            if (! Schema::hasColumn('accounting_bank_statement_lines', 'reconciled_by')) {
                $table->unsignedInteger('reconciled_by')->nullable()->after('reconciled_at');
            }
        });

        $idx = DB::select("SHOW INDEX FROM accounting_bank_statement_lines WHERE Key_name = 'accounting_bank_statement_lines_matched_aat_id_unique'");
        if (empty($idx)) {
            try {
                Schema::table('accounting_bank_statement_lines', function (Blueprint $table) {
                    $table->unique('matched_aat_id', 'accounting_bank_statement_lines_matched_aat_id_unique');
                });
            } catch (\Throwable $e) {
                // Duplicate matched_aat_id in legacy data; skip unique index
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('accounting_bank_statement_lines')) {
            return;
        }

        $idx = DB::select("SHOW INDEX FROM accounting_bank_statement_lines WHERE Key_name = 'accounting_bank_statement_lines_matched_aat_id_unique'");
        if (! empty($idx)) {
            Schema::table('accounting_bank_statement_lines', function (Blueprint $table) {
                $table->dropUnique('accounting_bank_statement_lines_matched_aat_id_unique');
            });
        }

        if (Schema::hasColumn('accounting_bank_statement_lines', 'reconciled_by')) {
            Schema::table('accounting_bank_statement_lines', function (Blueprint $table) {
                $table->dropColumn('reconciled_by');
            });
        }
    }
};
