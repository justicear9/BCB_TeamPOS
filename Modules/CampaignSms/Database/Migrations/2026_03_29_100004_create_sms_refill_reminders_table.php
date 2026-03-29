<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('sms_refill_reminders', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('business_id');
            $table->unsignedInteger('contact_id');
            $table->unsignedInteger('product_id');
            $table->unsignedSmallInteger('interval_days')->default(30);
            $table->dateTime('next_run_at');
            $table->dateTime('last_sent_at')->nullable();
            $table->text('template_body')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('business_id')->references('id')->on('business')->onDelete('cascade');
            $table->foreign('contact_id')->references('id')->on('contacts')->onDelete('cascade');
            $table->foreign('product_id')->references('id')->on('products')->onDelete('cascade');
            $table->index(['business_id', 'is_active', 'next_run_at']);
            $table->unique(['business_id', 'contact_id', 'product_id'], 'sms_refill_unique_contact_product');
        });
    }

    public function down()
    {
        Schema::dropIfExists('sms_refill_reminders');
    }
};
