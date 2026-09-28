<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Eli-owned tables. Eli never writes to TeamPOS records; everything it saves lives here.
 */
return new class extends Migration
{
    public function up()
    {
        if (! Schema::hasTable('ai_bm_targets')) {
            Schema::create('ai_bm_targets', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('business_id');
                $table->unsignedInteger('location_id')->nullable();
                $table->string('metric', 40);
                $table->date('month');
                $table->decimal('value', 22, 4);
                $table->unsignedInteger('created_by')->nullable();
                $table->timestamps();
                $table->index(['business_id', 'month'], 'ai_bm_targets_lookup');
            });
        }

        if (! Schema::hasTable('ai_bm_fixed_costs')) {
            Schema::create('ai_bm_fixed_costs', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('business_id');
                $table->unsignedInteger('location_id')->nullable();
                $table->string('name', 120);
                $table->string('category', 40)->default('other');
                $table->decimal('monthly_amount', 22, 4);
                $table->date('starts_on')->nullable();
                $table->date('ends_on')->nullable();
                $table->unsignedInteger('created_by')->nullable();
                $table->timestamps();
                $table->index('business_id', 'ai_bm_fixed_costs_business');
            });
        }

        if (! Schema::hasTable('ai_bm_notes')) {
            Schema::create('ai_bm_notes', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('business_id');
                $table->string('kind', 20);
                $table->string('title', 255);
                $table->text('details')->nullable();
                $table->unsignedInteger('location_id')->nullable();
                $table->unsignedInteger('product_id')->nullable();
                $table->date('effective_on')->nullable();
                $table->date('due_on')->nullable();
                $table->string('status', 20)->default('open');
                $table->unsignedInteger('created_by')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();
                $table->index(['business_id', 'kind', 'status'], 'ai_bm_notes_lookup');
            });
        }

        if (! Schema::hasTable('ai_bm_calendar_events')) {
            Schema::create('ai_bm_calendar_events', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('business_id');
                $table->unsignedInteger('location_id')->nullable();
                $table->string('name', 160);
                $table->string('kind', 30)->default('event');
                $table->date('starts_on');
                $table->date('ends_on');
                $table->decimal('effect_pct', 8, 2)->nullable();
                $table->unsignedInteger('created_by')->nullable();
                $table->timestamps();
                $table->index(['business_id', 'starts_on'], 'ai_bm_calendar_lookup');
            });
        }

        if (! Schema::hasTable('ai_bm_forecasts')) {
            Schema::create('ai_bm_forecasts', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('business_id');
                $table->unsignedInteger('location_id');
                $table->unsignedInteger('product_id');
                $table->date('target_date');
                $table->date('made_on');
                $table->string('model', 40);
                $table->decimal('p10', 22, 4);
                $table->decimal('p50', 22, 4);
                $table->decimal('p90', 22, 4);
                $table->decimal('recommended_qty', 22, 4)->nullable();
                $table->decimal('unit_price', 22, 4)->nullable();
                $table->decimal('unit_cost', 22, 4)->nullable();
                $table->timestamps();
                $table->unique(['business_id', 'location_id', 'product_id', 'target_date', 'made_on'], 'ai_bm_forecasts_unique');
                $table->index(['business_id', 'target_date'], 'ai_bm_forecasts_target');
            });
        }

        if (! Schema::hasTable('ai_bm_briefs')) {
            Schema::create('ai_bm_briefs', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('business_id');
                $table->date('brief_date');
                $table->string('kind', 20)->default('daily');
                $table->longText('content');
                $table->json('payload')->nullable();
                $table->timestamps();
                $table->unique(['business_id', 'brief_date', 'kind'], 'ai_bm_briefs_unique');
            });
        }

        if (! Schema::hasTable('ai_bm_alerts')) {
            Schema::create('ai_bm_alerts', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('business_id');
                $table->unsignedInteger('location_id')->nullable();
                $table->date('alert_date');
                $table->string('code', 60);
                $table->string('severity', 10)->default('warn');
                $table->text('message');
                $table->timestamp('seen_at')->nullable();
                $table->timestamps();
                $table->unique(['business_id', 'location_id', 'alert_date', 'code'], 'ai_bm_alerts_unique');
            });
        }
    }

    public function down()
    {
        foreach (['ai_bm_alerts', 'ai_bm_briefs', 'ai_bm_forecasts', 'ai_bm_calendar_events', 'ai_bm_notes', 'ai_bm_fixed_costs', 'ai_bm_targets'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
