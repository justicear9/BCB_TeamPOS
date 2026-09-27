<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_business_manager_preferences')) {
            return;
        }

        Schema::table('ai_business_manager_preferences', function (Blueprint $table) {
            if (! Schema::hasColumn('ai_business_manager_preferences', 'business_industry')) {
                $table->string('business_industry', 191)->nullable()->after('preferred_model');
            }
            if (! Schema::hasColumn('ai_business_manager_preferences', 'context_notes')) {
                $table->text('context_notes')->nullable()->after('business_industry');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('ai_business_manager_preferences')) {
            return;
        }

        Schema::table('ai_business_manager_preferences', function (Blueprint $table) {
            if (Schema::hasColumn('ai_business_manager_preferences', 'context_notes')) {
                $table->dropColumn('context_notes');
            }
            if (Schema::hasColumn('ai_business_manager_preferences', 'business_industry')) {
                $table->dropColumn('business_industry');
            }
        });
    }
};
