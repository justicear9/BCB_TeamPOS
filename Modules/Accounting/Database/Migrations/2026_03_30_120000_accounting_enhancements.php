<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounting_accounts', function (Blueprint $table) {
            if (! Schema::hasColumn('accounting_accounts', 'is_cash_account')) {
                $table->boolean('is_cash_account')->default(false)->after('gl_code');
            }
        });

        Schema::table('accounting_accounts_transactions', function (Blueprint $table) {
            if (! Schema::hasColumn('accounting_accounts_transactions', 'location_id')) {
                $table->unsignedInteger('location_id')->nullable()->after('operation_date');
                $table->index('location_id');
            }
        });

        if (! Schema::hasTable('accounting_bank_accounts')) {
            Schema::create('accounting_bank_accounts', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('business_id');
                $table->unsignedInteger('accounting_account_id');
                $table->string('name', 256);
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->index(['business_id', 'accounting_account_id']);
            });
        }

        if (! Schema::hasTable('accounting_bank_statement_lines')) {
            Schema::create('accounting_bank_statement_lines', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('bank_account_id');
                $table->date('line_date');
                $table->decimal('amount', 22, 4);
                $table->text('description')->nullable();
                $table->string('import_batch_id', 64)->nullable();
                $table->timestamp('reconciled_at')->nullable();
                $table->unsignedInteger('matched_aat_id')->nullable();
                $table->timestamps();

                $table->index(['bank_account_id', 'line_date']);
            });
        }

        if (! Schema::hasTable('accounting_audit_logs')) {
            Schema::create('accounting_audit_logs', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedInteger('business_id');
                $table->unsignedInteger('user_id')->nullable();
                $table->string('action', 64);
                $table->string('auditable_type', 255)->nullable();
                $table->unsignedBigInteger('auditable_id')->nullable();
                $table->json('before')->nullable();
                $table->json('after')->nullable();
                $table->string('ip_address', 45)->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->index(['business_id', 'created_at']);
                $table->index(['auditable_type', 'auditable_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_audit_logs');
        Schema::dropIfExists('accounting_bank_statement_lines');
        Schema::dropIfExists('accounting_bank_accounts');

        Schema::table('accounting_accounts_transactions', function (Blueprint $table) {
            if (Schema::hasColumn('accounting_accounts_transactions', 'location_id')) {
                $table->dropColumn('location_id');
            }
        });

        Schema::table('accounting_accounts', function (Blueprint $table) {
            if (Schema::hasColumn('accounting_accounts', 'is_cash_account')) {
                $table->dropColumn('is_cash_account');
            }
        });
    }
};
