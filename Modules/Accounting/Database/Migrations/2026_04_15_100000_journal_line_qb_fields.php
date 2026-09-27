<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('accounting_accounts_transactions')) {
            return;
        }

        Schema::table('accounting_accounts_transactions', function (Blueprint $table) {
            if (! Schema::hasColumn('accounting_accounts_transactions', 'contact_id')) {
                $table->unsignedInteger('contact_id')->nullable()->after('note');
                $table->index('contact_id');
            }
            if (! Schema::hasColumn('accounting_accounts_transactions', 'billable')) {
                $table->boolean('billable')->default(false)->after('contact_id');
            }
            if (! Schema::hasColumn('accounting_accounts_transactions', 'job_name')) {
                $table->string('job_name', 191)->nullable()->after('billable');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('accounting_accounts_transactions')) {
            return;
        }

        Schema::table('accounting_accounts_transactions', function (Blueprint $table) {
            if (Schema::hasColumn('accounting_accounts_transactions', 'job_name')) {
                $table->dropColumn('job_name');
            }
            if (Schema::hasColumn('accounting_accounts_transactions', 'billable')) {
                $table->dropColumn('billable');
            }
            if (Schema::hasColumn('accounting_accounts_transactions', 'contact_id')) {
                $table->dropIndex(['contact_id']);
                $table->dropColumn('contact_id');
            }
        });
    }
};
